<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsersLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MyAttendanceController extends Controller
{
    /*
    |==========================================================================
    | GET /my-attendance/today
    | Employee's own dashboard — today's check-in/check-out + status.
    | users_logs.user_id is NOT NULL (always populated), so we scope
    | directly by auth()->user()->id — no fingerprint_id fallback needed.
    |==========================================================================
    */
    public function today(Request $request)
    {
        $user  = $request->user();
        $today = now()->format('Y-m-d');

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
            ])
            ->first();

        $hasLog   = $log && $log->punch_count > 0;
        $checkIn  = $hasLog ? $log->first_in : null;
        $checkOut = ($hasLog && (int) $log->has_out === 1) ? $log->last_out : null;

        // ✅ Holiday check — department-inte holiday indengil, punch illenkilum "Holiday" kaanikkanam
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
                'holiday_name' => $holidayName,
            ],
        ]);
    }

    /*
    |==========================================================================
    | GET /my-attendance/history?from_date=&to_date=
    | Employee's own attendance history, grid style (every date in range gets
    | a row: present / holiday / absent / upcoming) — scoped directly by
    | user_id = auth()->user()->id. Defaults to current month.
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

        $fromDate = $request->query('from_date') ?: now()->startOfMonth()->format('Y-m-d');
        $toDate   = $request->query('to_date')   ?: now()->format('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            return response()->json(['status' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD'], 422);
        }

        $start = Carbon::parse($fromDate)->startOfDay();
        $end   = Carbon::parse($toDate)->startOfDay();

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

        // ✅ user_id direct match — column is NOT NULL, so no fallback needed
        $logRows = DB::table('users_logs')
            ->where('user_id', $user->id)
            ->whereBetween('checkindate', [$fromDate, $toDate])
            ->select([
                'checkindate',
                DB::raw('MIN(timein) as first_in'),
                DB::raw("MAX(CASE WHEN timeout != '00:00:00' AND timeout IS NOT NULL THEN timeout END) as last_out"),
                DB::raw("MAX(CASE WHEN timeout != '00:00:00' AND timeout IS NOT NULL THEN 1 ELSE 0 END) as has_out"),
                DB::raw('COUNT(*) as punch_count'),
            ])
            ->groupBy('checkindate')
            ->get()
            ->keyBy('checkindate');

        // ✅ holidays for this employee's department (incl. recurring yearly)
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

        $today = now()->format('Y-m-d');
        $rows  = [];
        $statPresent = $statLate = $statAbsent = $statHoliday = 0;

        foreach ($dates as $dateStr) {
            $log         = $logRows->get($dateStr);
            $holidayName = $holidayIndex[$dateStr] ?? null;

            $checkIn  = $log ? $log->first_in : null;
            $checkOut = ($log && (int) $log->has_out === 1) ? $log->last_out : null;

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

            if ($log) {
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
                'holiday_name' => $holidayName,
            ];
        }

        // Latest date first, matching how the dashboard table expects to read
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
            ],
        ]);
    }

    /*
    |==========================================================================
    | POST /my-attendance/mark  { type: 'checkin' | 'checkout' }
    | Employee self check-in/check-out from the web dashboard (not a
    | fingerprint device punch) — device_uid marked as 'WEB-SELF' so it's
    | easy to tell apart from real device logs in reports.
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
        $today = now()->format('Y-m-d');
        $now   = now()->format('H:i:s');

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
                    'device_uid'     => 'WEB-SELF',
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

            // checkout — find today's row still without a timeout
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