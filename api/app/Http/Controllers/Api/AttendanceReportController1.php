<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class AttendanceReportController extends Controller
{
    public function attendanceStatus(Request $request)
    {
        try {
            $type = $request->input('type', 'monthly'); // default monthly

            // Validate input based on type
            switch ($type) {
                case 'weekly':
                    $request->validate([
                        'week_start' => 'required|date',
                        'department_name' => 'nullable|string',
                    ]);
                    $startDate = Carbon::parse($request->week_start)->startOfDay();
                    $endDate = $startDate->copy()->addDays(6)->endOfDay();
                    break;

                case 'custom':
                    $request->validate([
                        'start_date' => 'required|date',
                        'end_date' => 'required|date|after_or_equal:start_date',
                        'department_name' => 'nullable|string',
                    ]);
                    $startDate = Carbon::parse($request->start_date)->startOfDay();
                    $endDate = Carbon::parse($request->end_date)->endOfDay();
                    break;

                case 'monthly':
                default:
                    $request->validate([
                        'month' => 'required|integer|min:1|max:12',
                        'year' => 'required|integer|min:2000',
                        'department_name' => 'nullable|string',
                    ]);
                    $startDate = Carbon::create($request->year, $request->month, 1)->startOfDay();
                    $endDate = $startDate->copy()->endOfMonth()->endOfDay();
                    break;
            }

            $authUser = Auth::user();

            // Base query for users with department join
            $usersQuery = DB::table('users')
                ->select('users.id', 'users.username', 'users.department_id', 'department.department_name as department_name')
                ->leftJoin('department', 'users.department_id', '=', 'department.id')
                ->whereNotNull('users.fingerprint_id') // only users with fingerprint_id
                ->where('users.fingerprint_id', '!=', 0);

            // Filter by department_name if provided
            if ($request->filled('department_name')) {
                $usersQuery->where('department.department_name', $request->department_name);
            } else {
                // Role-based filtering
                if ($authUser->user_types_id == 2) {
                    // Company Admin: departments under user's companies
                    $companyIds = DB::table('company_users')
                        ->where('users_id', $authUser->id)
                        ->pluck('company_id');

                    if ($companyIds->isEmpty()) {
                        return response()->json(['status' => 'success', 'data' => []]);
                    }

                    $accessibleDeps = DB::table('department')
                        ->whereIn('company_id', $companyIds)
                        ->pluck('id');

                    if ($accessibleDeps->isEmpty()) {
                        return response()->json(['status' => 'success', 'data' => []]);
                    }

                    $usersQuery->whereIn('users.department_id', $accessibleDeps);

                } elseif (in_array($authUser->user_types_id, [3, 4])) {
                    // Branch Admin or Department Admin: departments assigned to the user
                    $accessibleDeps = DB::table('department_users')
                        ->where('users_id', $authUser->id)
                        ->pluck('department_id');

                    if ($accessibleDeps->isEmpty()) {
                        return response()->json(['status' => 'success', 'data' => []]);
                    }

                    $usersQuery->whereIn('users.department_id', $accessibleDeps);
                }
                // Super Admin (user_types_id == 1) sees all users (no filter)
            }

            $users = $usersQuery->get();

            if ($users->isEmpty()) {
                return response()->json(['status' => 'success', 'data' => []]);
            }

            // Get logs for these users in date range
            $logs = DB::table('users_logs')
                ->whereBetween('checkindate', [$startDate->toDateString(), $endDate->toDateString()])
                ->whereIn('username', $users->pluck('username'))
                ->get()
                ->groupBy('username');

            $report = [];

            for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                $dateStr = $date->toDateString();
                foreach ($users as $user) {
                    $logForDay = isset($logs[$user->username])
                        ? $logs[$user->username]->firstWhere('checkindate', $dateStr)
                        : null;

                    if ($logForDay) {
                        $timeIn = $logForDay->timein;
                        $timeOut = $logForDay->timeout;

                        if ($timeIn && $timeOut) {
                            $start = new \DateTime($timeIn);
                            $end = new \DateTime($timeOut);
                            $interval = $start->diff($end);
                            $workingHours = $interval->format('%H:%I:%S');
                        } else {
                            $workingHours = "Incomplete";
                        }
                        $status = 'Present';
                    } else {
                        $timeIn = null;
                        $timeOut = null;
                        $workingHours = "00:00:00";
                        $status = 'Absent';
                    }

                    $report[] = [
                        'name' => $user->username,
                        'department' => $user->department_name,
                        'date' => $dateStr,
                        'status' => $status,
                        'time_in' => $timeIn ? date('H:i:s', strtotime($timeIn)) : null,
                        'time_out' => $timeOut ? date('H:i:s', strtotime($timeOut)) : null,
                        'working_hours' => $workingHours,
                    ];
                }
            }

            return response()->json([
                'status' => 'success',
                'data' => $report,
                'period' => [
                    'type' => $type,
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('AttendanceReportController attendanceStatus error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong: ' . $e->getMessage(),
            ], 500);
        }
    }
}
