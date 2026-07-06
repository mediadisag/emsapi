<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsersLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AttendanceLogsController extends Controller
{
    /*
    |==========================================================================
    | GET /attendance-logs
    | Role-scoped + device-scoped attendance, grouped by fingerprint + date.
    |
    | ✅ NEW: accepts a `user_id` query param so the frontend can scope
    |    directly to one employee's own record (employees.id), rather than
    |    only by fingerprint_id. fingerprint_id can be reused/reassigned
    |    over time (e.g. after an employee leaves and a new one is enrolled
    |    on the same slot), so scoping by user_id is the precise way to make
    |    sure the attendance modal / export only ever shows that employee's
    |    own data. When both are supplied, user_id takes priority.
    |
    | ✅ when BOTH from_date and to_date are supplied, we switch to
    |    "grid mode" (see getLogsGrid()) — every employee in scope gets one
    |    row PER DATE in the range, so days with no punch show as Absent
    |    (or Holiday, if the employee's department has a holiday that day)
    |    instead of just being silently missing from the list.
    |
    |    Without a bounded range we can't do this (there's no way to
    |    enumerate "all missing days" over unlimited history), so if
    |    from_date/to_date aren't both given we fall back to the original
    |    punch-only listing below.
    |==========================================================================
    */
    public function getLogs(Request $request)
    {
        $date   = $request->query('date');
        $page   = max(1, (int) $request->query('page', 1));
        $limit  = max(1, (int) $request->query('limit', 15));
        $search = trim((string) $request->query('search', ''));
        $offset = ($page - 1) * $limit;

        $fromDate = $request->query('from_date');
        $toDate   = $request->query('to_date');

        if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['status' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD'], 422);
        }

        $scopeCompany   = $request->query('company_id')     !== null ? (int) $request->query('company_id')     : null;
        $scopeBranch    = $request->query('branch_id')      !== null ? (int) $request->query('branch_id')      : null;
        $scopeDept      = $request->query('department_id')  !== null ? (int) $request->query('department_id')  : null;
        $scopeFpId      = $request->query('fingerprint_id') !== null ? (int) $request->query('fingerprint_id') : null;
        $scopeUserId    = $request->query('user_id')        !== null ? (int) $request->query('user_id')        : null; // ✅ NEW
        $scopeDeviceUid = $request->query('device_uid')     !== null ? trim($request->query('device_uid'))     : null;

        // ✅ Route to grid mode when we have a proper bounded date range.
        if (
            $fromDate && $toDate &&
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) &&
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)
        ) {
            return $this->getLogsGrid(
                $fromDate, $toDate, $page, $limit, $search,
                $scopeCompany, $scopeBranch, $scopeDept, $scopeFpId, $scopeDeviceUid, $scopeUserId
            );
        }

        // ✅ users/departments/company eppozhum join cheyyunnu — live data kittan.
        //    leftJoin aanu upayogichathu, employee delete/del_fingerid=1 aayalum
        //    pazhaya log rows kaanikkan (name/dept null aayi vannalum handle cheyyunnu).
        $query = DB::table('users_logs as ul')
            ->leftJoin('users as u', function ($join) {
                $join->on('u.fingerprint_id', '=', 'ul.fingerprint_id')
                     ->where('u.del_fingerid', '=', 0);
            })
            ->leftJoin('department as dep', 'dep.id', '=', 'u.department_id')
            ->leftJoin('company as comp', 'comp.id', '=', 'u.company_id');

        if ($date) {
            $query->where('ul.checkindate', $date);
        }
        if ($fromDate) {
            $query->where('ul.checkindate', '>=', $fromDate);
        }
        if ($toDate) {
            $query->where('ul.checkindate', '<=', $toDate);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('ul.username', 'LIKE', '%' . $search . '%')
                  ->orWhere('u.username', 'LIKE', '%' . $search . '%');
            });
        }
        if ($scopeDeviceUid) {
            $query->where('ul.device_uid', $scopeDeviceUid);
        }

        // ✅ user_id (employees.id) is the most precise scope — takes priority
        // over fingerprint_id, which can be reused across employees over time.
        if ($scopeUserId !== null) {
            $query->where('ul.user_id', $scopeUserId);
        } elseif ($scopeFpId !== null) {
            $query->where('ul.fingerprint_id', $scopeFpId);
        } elseif ($scopeDept !== null) {
            $query->where('u.department_id', $scopeDept);
        } elseif ($scopeBranch !== null) {
            $query->where('u.branch_id', $scopeBranch);
        } elseif ($scopeCompany !== null) {
            $query->where('u.company_id', $scopeCompany);
        }

        $totalEmployees = (clone $query)
            ->select(DB::raw('COUNT(DISTINCT ul.fingerprint_id) as total'))
            ->value('total') ?? 0;

        $totalPunches = (clone $query)->count('ul.id');

        $selects = [
            'ul.fingerprint_id',
            // ✅ users table-ile current username-inu priority, illenkil ul.username fallback
            DB::raw('MIN(COALESCE(u.username, ul.username)) as username'),
            DB::raw('MIN(ul.serialnumber) as serialnumber'),
            DB::raw('MIN(ul.device_uid) as device_uid'),
            // ✅ live department/company name — departments & company tables-il ninnu
            DB::raw('MIN(dep.department_name) as department_name'),
            DB::raw('MIN(comp.name) as company_name'),
            'ul.checkindate',
            DB::raw('MIN(ul.timein) as first_in'),
            DB::raw("MAX(CASE WHEN ul.timeout != '00:00:00' AND ul.timeout IS NOT NULL THEN ul.timeout END) as last_out"),
            DB::raw('COUNT(*) as punch_count'),
            DB::raw("MAX(CASE WHEN ul.timeout != '00:00:00' AND ul.timeout IS NOT NULL THEN 1 ELSE 0 END) as has_out"),
            DB::raw('MAX(ul.is_edited) as is_edited'),
            DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(ul.edited_by ORDER BY ul.edited_at DESC), ",", 1) as edited_by'),
            DB::raw('MAX(ul.edited_at) as edited_at'),
            DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(ul.id ORDER BY ul.timein ASC), ",", 1) as first_in_id'),
            DB::raw("SUBSTRING_INDEX(GROUP_CONCAT(ul.id ORDER BY CASE WHEN ul.timeout != '00:00:00' AND ul.timeout IS NOT NULL THEN ul.timeout END DESC), ',', 1) as last_out_id"),
            // ✅ ippo eppozhum available (join always active)
            DB::raw('MIN(u.department_id) as department_id'),
            DB::raw('MIN(u.branch_id) as branch_id'),
            DB::raw('MIN(u.company_id) as company_id'),
            // ✅ NEW — pass the raw user_id straight through so the frontend
            // can reuse it for the "mark attendance" quick-add action.
            DB::raw('MIN(ul.user_id) as user_id'),
        ];

        $rowsRaw = (clone $query)
            ->select($selects)
            ->groupBy('ul.fingerprint_id', 'ul.checkindate')
            ->orderBy('ul.checkindate', 'desc')
            ->orderByRaw('MIN(ul.timein) ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        $rows = [];
        foreach ($rowsRaw as $row) {
            $rows[] = [
                'fingerprint_id' => $row->fingerprint_id,
                'user_id'        => $row->user_id !== null ? (int) $row->user_id : null, // ✅ NEW
                'name'           => $row->username,
                'company'        => $row->company_name    ?? '',
                'department'     => $row->department_name ?? '',
                'serial'         => $row->serialnumber     ?? '',
                'device_uid'     => $row->device_uid       ?? '',
                'date'           => $row->checkindate,
                'status'         => 'present',
                'first_in'       => $row->first_in,
                'last_out'       => $row->last_out,
                'punch_count'    => (int) $row->punch_count,
                'is_complete'    => ((int) $row->has_out) === 1,
                'department_id'  => $row->department_id !== null ? (int) $row->department_id : null,
                'branch_id'      => $row->branch_id     !== null ? (int) $row->branch_id     : null,
                'company_id'     => $row->company_id    !== null ? (int) $row->company_id    : null,
                'is_edited'      => (int) $row->is_edited,
                'edited_by'      => $row->edited_by ?: null,
                'edited_at'      => $row->edited_at ?: null,
                'first_in_id'    => $row->first_in_id ? (int) $row->first_in_id : null,
                'last_out_id'    => $row->last_out_id ? (int) $row->last_out_id : null,
            ];
        }

        $empFirstIn = $empLastOut = $empDuration = null;
        if (($scopeFpId !== null || $scopeUserId !== null) && count($rows) === 1) {
            $empFirstIn = $rows[0]['first_in'];
            $empLastOut = $rows[0]['last_out'];
            if ($empFirstIn && $empLastOut) {
                $ts = strtotime(date('Y-m-d') . ' ' . $empFirstIn);
                $te = strtotime(date('Y-m-d') . ' ' . $empLastOut);
                $sec = $te - $ts;
                if ($sec > 0) {
                    $h = floor($sec / 3600);
                    $m = floor(($sec % 3600) / 60);
                    $empDuration = $h > 0 ? "{$h}h {$m}m" : "{$m}m";
                }
            }
        }

        $complete  = array_filter($rows, fn ($r) => $r['is_complete']);
        $stillIn   = array_filter($rows, fn ($r) => !$r['is_complete']);
        $hasMore   = ($offset + count($rows)) < $totalEmployees;
        $remaining = max(0, $totalEmployees - ($offset + count($rows)));

        return response()->json([
            'status'    => true,
            'mode'      => 'punches',
            'date'      => $date,
            'page'      => $page,
            'limit'     => $limit,
            'rows'      => $rows,
            'has_more'  => $hasMore,
            'remaining' => $remaining,
            'stats'     => [
                'total_punches'   => (int) $totalPunches,
                'total_employees' => (int) $totalEmployees,
                'complete'        => count($complete),
                'still_inside'    => count($stillIn),
                'first_in'        => $empFirstIn,
                'last_out'        => $empLastOut,
                'duration'        => $empDuration,
            ],
            '_scope' => [
                'company_id'     => $scopeCompany,
                'branch_id'      => $scopeBranch,
                'department_id'  => $scopeDept,
                'fingerprint_id' => $scopeFpId,
                'user_id'        => $scopeUserId, // ✅ NEW
                'device_uid'     => $scopeDeviceUid,
            ],
        ]);
    }

    /*
    |==========================================================================
    | Grid mode: employees-in-scope × every date in [from_date, to_date].
    | Each employee/date cell resolves to one of:
    |   - present  -> there's a punch (first_in/last_out as before)
    |   - holiday  -> no punch, but the employee's department has a holiday
    |                 that day (including recurring yearly holidays)
    |   - absent   -> no punch, not a holiday, and the date is not in the future
    |   - upcoming -> no punch, date is still in the future (no verdict yet)
    |
    | Pagination applies to EMPLOYEES (not employee×date rows), so page size
    | stays predictable regardless of how wide the date range is.
    |
    | ✅ NEW: $scopeUserId (employees.id) — when supplied, scopes the
    |    employee list down to that exact user, taking priority over
    |    fingerprint_id/department/branch/company scoping. This guarantees
    |    the grid (and therefore the attendance modal + Excel export) only
    |    ever contains rows belonging to that one employee.
    |==========================================================================
    */
    private function getLogsGrid(
        string $fromDate,
        string $toDate,
        int $page,
        int $limit,
        string $search,
        ?int $scopeCompany,
        ?int $scopeBranch,
        ?int $scopeDept,
        ?int $scopeFpId,
        ?string $scopeDeviceUid,
        ?int $scopeUserId = null   // ✅ NEW
    ) {
        $start = Carbon::parse($fromDate)->startOfDay();
        $end   = Carbon::parse($toDate)->startOfDay();

        if ($end->lt($start)) {
            return response()->json(['status' => false, 'error' => 'to_date must be on/after from_date'], 422);
        }

        // ✅ Cap the range so a huge span can't blow up the grid.
        $totalDays = $start->diffInDays($end) + 1;
        if ($totalDays > 366) {
            return response()->json(['status' => false, 'error' => 'Date range too large — please pick 366 days or fewer.'], 422);
        }

        $dates = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $dates[] = $d->format('Y-m-d');
        }

        // --- Employees in scope ---
        $empQuery = DB::table('users as u')
            ->leftJoin('department as dep', 'dep.id', '=', 'u.department_id')
            ->leftJoin('company as comp', 'comp.id', '=', 'u.company_id')
            ->where('u.del_fingerid', 0);

        // ✅ user_id is the most precise filter — exact-match a single employee
        // and skip the rest of the scope chain entirely when it's supplied.
        if ($scopeUserId !== null)  $empQuery->where('u.id', $scopeUserId);           // ✅ NEW
        if ($scopeFpId !== null)    $empQuery->where('u.fingerprint_id', $scopeFpId);
        if ($scopeDept !== null)    $empQuery->where('u.department_id', $scopeDept);
        if ($scopeBranch !== null)  $empQuery->where('u.branch_id', $scopeBranch);
        if ($scopeCompany !== null) $empQuery->where('u.company_id', $scopeCompany);
        if ($scopeDeviceUid)        $empQuery->where('u.device_uid', $scopeDeviceUid);
        if ($search !== '')         $empQuery->where('u.username', 'LIKE', '%' . $search . '%');

        $totalEmployees = (clone $empQuery)->distinct()->count('u.id');

        $employees = (clone $empQuery)
            ->select([
                'u.id as user_id', 'u.fingerprint_id', 'u.username',
                'u.department_id', 'u.branch_id', 'u.company_id', 'u.device_uid',
                DB::raw('dep.department_name as department_name'),
                DB::raw('comp.name as company_name'),
            ])
            ->distinct()
            ->orderBy('u.username')
            ->limit($limit)
            ->offset(($page - 1) * $limit)
            ->get();

        if ($employees->isEmpty()) {
            return response()->json([
                'status'    => true,
                'mode'      => 'grid',
                'from_date' => $fromDate,
                'to_date'   => $toDate,
                'page'      => $page,
                'limit'     => $limit,
                'total'     => $totalEmployees,
                'has_more'  => false,
                'rows'      => [],
                'stats'     => [
                    'total_punches' => 0, 'total_employees' => $totalEmployees,
                    'complete' => 0, 'still_inside' => 0, 'holiday' => 0, 'absent' => 0,
                ],
            ]);
        }

        $fpIds   = $employees->pluck('fingerprint_id')->filter()->values()->all();
        $deptIds = $employees->pluck('department_id')->filter()->unique()->values()->all();

        // --- Attendance punches for these employees within the range ---
        // ✅ Scoped by user_id (when known) instead of fingerprint_id alone,
        // so a reused/reassigned fingerprint_id can never pull in another
        // employee's punches for this employee's grid.
        $userIds = $employees->pluck('user_id')->filter()->values()->all();

        $logsQuery = DB::table('users_logs as ul')
            ->whereBetween('ul.checkindate', [$fromDate, $toDate]);

        if (!empty($userIds)) {
            $logsQuery->where(function ($q) use ($userIds, $fpIds) {
                $q->whereIn('ul.user_id', $userIds)
                  ->orWhere(function ($q2) use ($fpIds) {
                      // fallback for older rows that never had user_id populated
                      $q2->whereNull('ul.user_id')->whereIn('ul.fingerprint_id', $fpIds);
                  });
            });
        } else {
            $logsQuery->whereIn('ul.fingerprint_id', $fpIds);
        }

        $logRows = $logsQuery
            ->select([
                'ul.fingerprint_id', 'ul.user_id', 'ul.checkindate',
                DB::raw('MIN(ul.timein) as first_in'),
                DB::raw("MAX(CASE WHEN ul.timeout != '00:00:00' AND ul.timeout IS NOT NULL THEN ul.timeout END) as last_out"),
                DB::raw('COUNT(*) as punch_count'),
                DB::raw("MAX(CASE WHEN ul.timeout != '00:00:00' AND ul.timeout IS NOT NULL THEN 1 ELSE 0 END) as has_out"),
                DB::raw('MAX(ul.is_edited) as is_edited'),
                DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(ul.edited_by ORDER BY ul.edited_at DESC), ",", 1) as edited_by'),
                DB::raw('MAX(ul.edited_at) as edited_at'),
                DB::raw('SUBSTRING_INDEX(GROUP_CONCAT(ul.id ORDER BY ul.timein ASC), ",", 1) as first_in_id'),
                DB::raw("SUBSTRING_INDEX(GROUP_CONCAT(ul.id ORDER BY CASE WHEN ul.timeout != '00:00:00' AND ul.timeout IS NOT NULL THEN ul.timeout END DESC), ',', 1) as last_out_id"),
            ])
            // ✅ group by user_id when present so two employees who once
            // shared a fingerprint_id never get merged into one bucket.
            ->groupBy('ul.fingerprint_id', 'ul.user_id', 'ul.checkindate')
            ->get()
            ->groupBy(function ($row) {
                return $row->user_id !== null ? 'u:' . $row->user_id : 'f:' . $row->fingerprint_id;
            });

        // --- Holidays covering this department set + range (incl. recurring yearly holidays) ---
        $holidayRows = empty($deptIds) ? collect() : DB::table('holidays')
            ->whereIn('department_id', $deptIds)
            ->where(function ($q) use ($fromDate, $toDate) {
                $q->whereBetween('holiday_date', [$fromDate, $toDate])
                  ->orWhere('is_recurring', 1);
            })
            ->select(['department_id', 'holiday_name', 'holiday_date', 'is_recurring'])
            ->get();

        // Index: department_id => ['Y-m-d' => holiday_name]
        $holidayIndex = [];
        foreach ($holidayRows as $h) {
            $hd = Carbon::parse($h->holiday_date);
            if ($h->is_recurring) {
                foreach ($dates as $dateStr) {
                    if (Carbon::parse($dateStr)->format('m-d') === $hd->format('m-d')) {
                        $holidayIndex[$h->department_id][$dateStr] = $h->holiday_name;
                    }
                }
            } else {
                $key = $hd->format('Y-m-d');
                if ($key >= $fromDate && $key <= $toDate) {
                    $holidayIndex[$h->department_id][$key] = $h->holiday_name;
                }
            }
        }

        $today = now()->format('Y-m-d');

        $rows = [];
        $statComplete = $statStillIn = $statHoliday = $statAbsent = $statPunches = 0;

        foreach ($employees as $emp) {
            // ✅ look up this employee's punches by user_id first (precise),
            // falling back to fingerprint_id-keyed bucket for legacy rows.
            $logKey       = 'u:' . $emp->user_id;
            $fallbackKey  = 'f:' . $emp->fingerprint_id;
            $empLogs      = ($logRows->get($logKey) ?? $logRows->get($fallbackKey) ?? collect())->keyBy('checkindate');
            $deptHolidays = $holidayIndex[$emp->department_id] ?? [];

            foreach ($dates as $dateStr) {
                $log         = $empLogs->get($dateStr);
                $holidayName = $deptHolidays[$dateStr] ?? null;

                if ($log) {
                    $status = 'present';
                    $statPunches += (int) $log->punch_count;
                    if ((int) $log->has_out === 1) { $statComplete++; } else { $statStillIn++; }
                } elseif ($holidayName) {
                    $status = 'holiday';
                    $statHoliday++;
                } elseif ($dateStr > $today) {
                    $status = 'upcoming';
                } else {
                    $status = 'absent';
                    $statAbsent++;
                }

                $rows[] = [
                    'fingerprint_id' => $emp->fingerprint_id,
                    'user_id'        => $emp->user_id, // ✅ always the employee's actual id — never null in grid mode
                    'name'           => $emp->username,
                    'company'        => $emp->company_name    ?? '',
                    'department'     => $emp->department_name ?? '',
                    'date'           => $dateStr,
                    'status'         => $status,
                    'holiday_name'   => $holidayName,
                    'first_in'       => $log->first_in ?? null,
                    'last_out'       => $log->last_out ?? null,
                    'punch_count'    => $log ? (int) $log->punch_count : 0,
                    'is_complete'    => $log ? ((int) $log->has_out === 1) : false,
                    'department_id'  => $emp->department_id !== null ? (int) $emp->department_id : null,
                    'branch_id'      => $emp->branch_id     !== null ? (int) $emp->branch_id     : null,
                    'company_id'     => $emp->company_id    !== null ? (int) $emp->company_id    : null,
                    'is_edited'      => $log ? (int) $log->is_edited : 0,
                    'edited_by'      => $log->edited_by ?? null,
                    'edited_at'      => $log->edited_at ?? null,
                    'first_in_id'    => $log && $log->first_in_id ? (int) $log->first_in_id : null,
                    'last_out_id'    => $log && $log->last_out_id ? (int) $log->last_out_id : null,
                ];
            }
        }

        return response()->json([
            'status'    => true,
            'mode'      => 'grid',
            'from_date' => $fromDate,
            'to_date'   => $toDate,
            'page'      => $page,
            'limit'     => $limit,
            'total'     => $totalEmployees,
            'has_more'  => ($page * $limit) < $totalEmployees,
            'rows'      => $rows,
            'stats'     => [
                'total_punches'   => $statPunches,
                'total_employees' => $totalEmployees,
                'complete'        => $statComplete,
                'still_inside'    => $statStillIn,
                'holiday'         => $statHoliday,
                'absent'          => $statAbsent,
            ],
        ]);
    }

    /*
    |==========================================================================
    | POST /attendance-logs/add
    | Admin-only — manually add an attendance entry for an employee.
    | Frontend sends company_id/branch_id/department_id only to filter the
    | employee dropdown; the actual payload just needs the selected user_id.
    |==========================================================================
    */
    public function addAttendance(Request $request)
    {
        $user = $request->user();

        // ✅ Only Super Admin (1) and Company Admin (2) can add attendance manually.
        if (!in_array((int) $user->user_types_id, [1, 2])) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to add attendance records.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'date'    => 'required|date_format:Y-m-d',
            'timein'  => 'required|date_format:H:i:s',
            'timeout' => 'nullable|date_format:H:i:s',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();

        // ✅ employee-യുടെ fingerprint_id / device_uid / branch / department
        // users table-ൽ നിന്ന് നേരിട്ട് എടുക്കുന്നു — dropdown-ൽ user_id തിരഞ്ഞെടുത്താൽ മതി.
        $emp = DB::table('users')
            ->where('id', $data['user_id'])
            ->where('del_fingerid', 0)
            ->first();

        if (!$emp) {
            return response()->json([
                'status'  => false,
                'message' => 'Employee not found or fingerprint has been removed.',
            ], 404);
        }

        // ✅ Duplicate check — അതേ employee + date + timein നിലവിൽ ഉണ്ടോ എന്ന് നോക്കുന്നു.
        // Scoped by user_id (falls back to fingerprint_id+device for legacy rows)
        // so this never false-flags against another employee's entry.
        $duplicate = DB::table('users_logs')
            ->where('user_id', $emp->id)
            ->where('checkindate', $data['date'])
            ->where('timein', $data['timein'])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status'  => false,
                'message' => 'An attendance entry already exists for this employee at this time.',
            ], 409);
        }

        $editedBy = $user->username ?? $user->name ?? 'Admin';

        DB::beginTransaction();
        try {
            UsersLog::create([
                'username'       => $emp->username,
                'user_id'        => $emp->id,
                'serialnumber'   => $emp->serialnumber ?? 0,
                'fingerprint_id' => $emp->fingerprint_id,
                'device_uid'     => $emp->device_uid,
                'device_branch'  => $emp->device_branch,
                'device_dep'     => $emp->device_dep,
                'checkindate'    => $data['date'],
                'timein'         => $data['timein'],
                'timeout'        => $data['timeout'] ?? '00:00:00',
                'fingerout'      => !empty($data['timeout']) ? 1 : 0,
                'is_edited'      => 1,
                'edited_by'      => $editedBy,
                'edited_at'      => now(),
            ]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Attendance added successfully.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Add failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /*
    |==========================================================================
    | PUT /attendance-logs/edit
    | Admin-only — edit check-in / check-out for a grouped attendance row.
    |==========================================================================
    */
    public function updateAttendance(Request $request)
    {
        $user = $request->user();

        // ✅ Only Super Admin (1) and Company Admin (2) can edit.
        // Adjust role IDs to match your user_types table.
        if (!in_array((int) $user->user_types_id, [1, 2])) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to edit attendance records.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'fingerprint_id' => 'required|integer',
            'date'           => 'required|date_format:Y-m-d',
            'first_in_id'    => 'nullable|integer',
            'last_out_id'    => 'nullable|integer',
            'timein'         => 'nullable|date_format:H:i:s',
            'timeout'        => 'nullable|date_format:H:i:s',
            'device_uid'     => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();
        $editedBy = $user->username ?? $user->name ?? 'Admin';

        if (empty($data['timein']) && empty($data['timeout'])) {
            return response()->json([
                'status'  => false,
                'message' => 'Nothing to update — provide timein and/or timeout.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $updatedAny = false;

            if (!empty($data['timein'])) {
                $row = $this->resolveRow(
                    $data['first_in_id'] ?? null,
                    $data['fingerprint_id'],
                    $data['date'],
                    $data['device_uid'] ?? null,
                    'first_in'
                );
                if ($row) {
                    $row->update([
                        'timein'    => $data['timein'],
                        'is_edited' => 1,
                        'edited_by' => $editedBy,
                        'edited_at' => now(),
                    ]);
                    $updatedAny = true;
                }
            }

            if (!empty($data['timeout'])) {
                $row = $this->resolveRow(
                    $data['last_out_id'] ?? null,
                    $data['fingerprint_id'],
                    $data['date'],
                    $data['device_uid'] ?? null,
                    'last_out'
                );
                if ($row) {
                    $row->update([
                        'timeout'   => $data['timeout'],
                        'is_edited' => 1,
                        'edited_by' => $editedBy,
                        'edited_at' => now(),
                    ]);
                    $updatedAny = true;
                }
            }

            if (!$updatedAny) {
                DB::rollBack();
                return response()->json([
                    'status'  => false,
                    'message' => 'No matching attendance row found to update.',
                ], 404);
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Attendance updated successfully.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Update failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolve which users_logs row to update.
     * Prefers explicit row id sent by frontend; falls back to
     * earliest/latest punch lookup for that fingerprint + date.
     */
    private function resolveRow(?int $explicitId, int $fingerprintId, string $date, ?string $deviceUid, string $type)
    {
        if ($explicitId) {
            return UsersLog::find($explicitId);
        }

        $query = UsersLog::where('fingerprint_id', $fingerprintId)
            ->where('checkindate', $date);

        if ($deviceUid) {
            $query->where('device_uid', $deviceUid);
        }

        if ($type === 'first_in') {
            return $query->orderBy('timein', 'asc')->first();
        }

        return $query->orderByRaw("timeout != '00:00:00' DESC")
                      ->orderBy('timeout', 'desc')
                      ->first();
    }
}