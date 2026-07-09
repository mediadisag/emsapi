<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsersLog;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MyAttendanceController extends Controller
{
    // ✅ Fixed timezone for all attendance date/time capture & comparisons.
    // Using this instead of now() avoids the UTC-vs-IST bug (server config
    // may have app.timezone = UTC, which would shift check-in/out times by
    // 5:30 — same class of bug fixed earlier in ILTService / attendance-logs).
    private const TZ = 'Asia/Kolkata';

    // ✅ device_uid value written by MyAttendanceController::mark() for
    // self check-in/out on an approved WFH day. Any log row with this
    // device_uid was NOT punched on the office fingerprint device.
    private const WFH_DEVICE_UID = 'WEB-SELF';

    /*
    |==========================================================================
    | GET /my-attendance/today
    |==========================================================================
    */
    public function today(Request $request)
    {
        $user  = $request->user();
        $today = Carbon::now(self::TZ)->format('Y-m-d');

        $emp = DB::table('users')
            ->where('id', $user->id)
            ->where('del_fingerid', 0)
            ->first();

        if (!$emp) {
            return response()->json([
                'status'  => false,
                'message' => 'Employee record not found.',
            ], 404);
        }

        $log = DB::table('users_logs')
            ->where('user_id', $user->id)
            ->where('checkindate', $today)
            ->select([
                DB::raw('MIN(timein) as first_in'),
                DB::raw("MAX(CASE WHEN timeout != '00:00:00' AND timeout IS NOT NULL THEN timeout END) as last_out"),
                DB::raw("MAX(CASE WHEN timeout != '00:00:00' AND timeout IS NOT NULL THEN 1 ELSE 0 END) as has_out"),
                DB::raw('COUNT(*) as punch_count'),
                // ✅ WEB-SELF means the employee self-marked today (an approved WFH
                // day) instead of punching the office fingerprint device.
                DB::raw("MAX(CASE WHEN device_uid = '" . self::WFH_DEVICE_UID . "' THEN 1 ELSE 0 END) as is_wfh"),
                // ✅ Raw device_uid too, so it's directly visible for debugging
                // (e.g. if a device sends an unexpected UID that doesn't match
                // WFH_DEVICE_UID exactly, this shows up here instead of silently
                // falling through to "Present").
                DB::raw('MAX(device_uid) as device_uid'),
            ])
            ->first();

        $hasLog   = $log && $log->punch_count > 0;
        $checkIn  = $hasLog ? $log->first_in : null;
        $checkOut = ($hasLog && (int) $log->has_out === 1) ? $log->last_out : null;
        $isWfh    = $hasLog && (int) $log->is_wfh === 1;

        $isHoliday = false;
        $holidayName = null;
        if (!$hasLog && $emp->department_id) {
            $hol = DB::table('holidays')
                ->where('department_id', $emp->department_id)
                ->where(function ($q) use ($today) {
                    $q->where('holiday_date', $today)->orWhere('is_recurring', 1);
                })
                ->get()
                ->first(function ($h) use ($today) {
                    if ($h->is_recurring) {
                        return Carbon::parse($h->holiday_date)->format('m-d') === Carbon::parse($today)->format('m-d');
                    }
                    return $h->holiday_date === $today;
                });
            if ($hol) {
                $isHoliday   = true;
                $holidayName = $hol->holiday_name;
            }
        }

        $status = 'Not marked';
        if ($isHoliday && !$hasLog) {
            $status = 'Holiday';
        } elseif ($checkIn && $isWfh) {
            $status = 'WFH';
        } elseif ($checkIn) {
            $status = 'Present';
        }

        return response()->json([
            'status' => true,
            'data'   => [
                'date'         => $today,
                'check_in'     => $checkIn,
                'check_out'    => $checkOut,
                'status'       => $status,
                'is_wfh'       => $isWfh,
                'device_uid'   => $hasLog ? $log->device_uid : null,
                'holiday_name' => $holidayName,
            ],
        ]);
    }

    /*
    |==========================================================================
    | GET /my-attendance/history?from_date=&to_date=
    |==========================================================================
    */
    public function history(Request $request)
    {
        $user = $request->user();

        $emp = DB::table('users')
            ->where('id', $user->id)
            ->where('del_fingerid', 0)
            ->first();

        if (!$emp) {
            return response()->json(['status' => false, 'message' => 'Employee record not found.'], 404);
        }

        $fromDate = $request->query('from_date') ?: Carbon::now(self::TZ)->startOfMonth()->format('Y-m-d');
        $toDate   = $request->query('to_date')   ?: Carbon::now(self::TZ)->format('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            return response()->json(['status' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD'], 422);
        }

        $start = Carbon::parse($fromDate, self::TZ)->startOfDay();
        $end   = Carbon::parse($toDate, self::TZ)->startOfDay();

        if ($end->lt($start)) {
            return response()->json(['status' => false, 'error' => 'to_date must be on/after from_date'], 422);
        }

        if ($start->diffInDays($end) + 1 > 366) {
            return response()->json(['status' => false, 'error' => 'Date range too large — please pick 366 days or fewer.'], 422);
        }

        $dates = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $dates[] = $d->format('Y-m-d');
        }

        $logRows = DB::table('users_logs')
            ->where('user_id', $user->id)
            ->whereBetween('checkindate', [$fromDate, $toDate])
            ->select([
                'checkindate',
                DB::raw('MIN(timein) as first_in'),
                DB::raw("MAX(CASE WHEN timeout != '00:00:00' AND timeout IS NOT NULL THEN timeout END) as last_out"),
                DB::raw("MAX(CASE WHEN timeout != '00:00:00' AND timeout IS NOT NULL THEN 1 ELSE 0 END) as has_out"),
                DB::raw('COUNT(*) as punch_count'),
                // ✅ WEB-SELF means the employee self-marked this day (an approved
                // WFH day) rather than punching the office fingerprint device.
                DB::raw("MAX(CASE WHEN device_uid = '" . self::WFH_DEVICE_UID . "' THEN 1 ELSE 0 END) as is_wfh"),
                // ✅ Raw device_uid too, so it's directly visible for debugging.
                DB::raw('MAX(device_uid) as device_uid'),
            ])
            ->groupBy('checkindate')
            ->get()
            ->keyBy('checkindate');

        $holidayIndex = [];
        if ($emp->department_id) {
            $holidayRows = DB::table('holidays')
                ->where('department_id', $emp->department_id)
                ->where(function ($q) use ($fromDate, $toDate) {
                    $q->whereBetween('holiday_date', [$fromDate, $toDate])->orWhere('is_recurring', 1);
                })
                ->select(['holiday_name', 'holiday_date', 'is_recurring'])
                ->get();

            foreach ($holidayRows as $h) {
                $hd = Carbon::parse($h->holiday_date);
                if ($h->is_recurring) {
                    foreach ($dates as $dateStr) {
                        if (Carbon::parse($dateStr)->format('m-d') === $hd->format('m-d')) {
                            $holidayIndex[$dateStr] = $h->holiday_name;
                        }
                    }
                } else {
                    $key = $hd->format('Y-m-d');
                    if ($key >= $fromDate && $key <= $toDate) {
                        $holidayIndex[$key] = $h->holiday_name;
                    }
                }
            }
        }

        $today = Carbon::now(self::TZ)->format('Y-m-d');
        $rows  = [];
        $statPresent = $statLate = $statAbsent = $statHoliday = $statWfh = 0;

        foreach ($dates as $dateStr) {
            $log         = $logRows->get($dateStr);
            $holidayName = $holidayIndex[$dateStr] ?? null;

            $checkIn  = $log ? $log->first_in : null;
            $checkOut = ($log && (int) $log->has_out === 1) ? $log->last_out : null;
            $isWfh    = $log && (int) $log->is_wfh === 1;

            $hours = null;
            if ($checkIn && $checkOut) {
                $ts  = strtotime($dateStr . ' ' . $checkIn);
                $te  = strtotime($dateStr . ' ' . $checkOut);
                $sec = $te - $ts;
                if ($sec > 0) {
                    $h = floor($sec / 3600);
                    $m = floor(($sec % 3600) / 60);
                    $hours = $h > 0 ? "{$h}h {$m}m" : "{$m}m";
                }
            }

            if ($log && $isWfh) {
                $status = 'WFH';
                $statWfh++;
                // WFH days still count as present for the "Present Days" stat
                $statPresent++;
            } elseif ($log) {
                $status = 'Present';
                $statPresent++;
            } elseif ($holidayName) {
                $status = 'Holiday';
                $statHoliday++;
            } elseif ($dateStr > $today) {
                $status = 'Upcoming';
            } else {
                $status = 'Absent';
                $statAbsent++;
            }

            $rows[] = [
                'date'         => $dateStr,
                'check_in'     => $checkIn,
                'check_out'    => $checkOut,
                'hours'        => $hours,
                'status'       => $status,
                'is_wfh'       => $isWfh,
                'device_uid'   => $log->device_uid ?? null,
                'holiday_name' => $holidayName,
            ];
        }

        $rows = array_reverse($rows);

        return response()->json([
            'status'    => true,
            'from_date' => $fromDate,
            'to_date'   => $toDate,
            'rows'      => $rows,
            'stats'     => [
                'present' => $statPresent,
                'absent'  => $statAbsent,
                'late'    => $statLate,
                'holiday' => $statHoliday,
                'wfh'     => $statWfh,
            ],
        ]);
    }

    /*
    |==========================================================================
    | POST /my-attendance/mark  { type: 'checkin' | 'checkout' }
    |
    | ✅ Self check-in/out only allowed on an admin-approved WFH day.
    | Regular office attendance must go through the fingerprint device.
    |==========================================================================
    */
    public function mark(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:checkin,checkout',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        $emp = DB::table('users')
            ->where('id', $user->id)
            ->where('del_fingerid', 0)
            ->first();

        if (!$emp) {
            return response()->json(['status' => false, 'message' => 'Employee record not found.'], 404);
        }

        $type  = $request->input('type');
        $today = Carbon::now(self::TZ)->format('Y-m-d');
        $now   = Carbon::now(self::TZ)->format('H:i:s');

        // ✅ Self check-in/out only allowed on an admin-approved WFH day
        $hasApprovedWfh = LeaveRequest::where('user_id', $user->id)
            ->where('type', 'WFH')
            ->where('status', 'Approved')
            ->where('from_date', '<=', $today)
            ->where('to_date', '>=', $today)
            ->exists();

        if (!$hasApprovedWfh) {
            return response()->json([
                'status'  => false,
                'message' => 'Self check-in/out is only available on an admin-approved WFH day. Please use the fingerprint device for regular attendance.',
            ], 403);
        }

        DB::beginTransaction();
        try {
            if ($type === 'checkin') {
                $already = UsersLog::where('user_id', $user->id)
                    ->where('checkindate', $today)
                    ->exists();

                if ($already) {
                    DB::rollBack();
                    return response()->json(['status' => false, 'message' => 'Already checked in today.'], 409);
                }

                UsersLog::create([
                    'username'       => $emp->username,
                    'user_id'        => $emp->id,
                    'serialnumber'   => $emp->serialnumber ?? 0,
                    'fingerprint_id' => $emp->fingerprint_id,
                    'device_uid'     => self::WFH_DEVICE_UID,
                    'device_branch'  => $emp->device_branch ?? null,
                    'device_dep'     => $emp->device_dep ?? null,
                    'checkindate'    => $today,
                    'timein'         => $now,
                    'timeout'        => '00:00:00',
                    'fingerout'      => 0,
                    'is_edited'      => 0,
                ]);

                DB::commit();
                return response()->json(['status' => true, 'message' => 'Checked in.', 'time' => $now]);
            }

            $row = UsersLog::where('user_id', $user->id)
                ->where('checkindate', $today)
                ->where(function ($q) {
                    $q->where('timeout', '00:00:00')->orWhereNull('timeout');
                })
                ->orderBy('timein', 'desc')
                ->first();

            if (!$row) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => 'No open check-in found for today.'], 409);
            }

            $row->update(['timeout' => $now, 'fingerout' => 1]);

            DB::commit();
            return response()->json(['status' => true, 'message' => 'Checked out.', 'time' => $now]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }
}