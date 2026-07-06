<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AttendanceReportController extends Controller
{
    public function attendanceStatus(Request $request)
    {
        $type = $request->input('type', 'monthly'); // default monthly

        // Validate and set date range
        switch ($type) {
            case 'weekly':
                $request->validate([
                    'week_start' => 'required|date',
                ]);
                $startDate = Carbon::parse($request->week_start)->startOfDay();
                $endDate = $startDate->copy()->addDays(6)->endOfDay();
                break;

            case 'custom':
                $request->validate([
                    'start_date' => 'required|date',
                    'end_date' => 'required|date|after_or_equal:start_date',
                ]);
                $startDate = Carbon::parse($request->start_date)->startOfDay();
                $endDate = Carbon::parse($request->end_date)->endOfDay();
                break;

            case 'monthly':
            default:
                $request->validate([
                    'month' => 'required|integer|min:1|max:12',
                    'year' => 'required|integer|min:2000',
                ]);
                $startDate = Carbon::create($request->year, $request->month, 1)->startOfDay();
                $endDate = $startDate->copy()->endOfMonth()->endOfDay();
                break;
        }

        // Get all users - you may add filters if needed
        $users = DB::table('users')->select('username')->get();

        $report = [];

        foreach ($users as $user) {
            for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                $dateString = $date->toDateString();

                // Query user_log for this user and date
                $log = DB::table('users_logs')
                    ->where('username', $user->username)
                    ->where('checkindate', $dateString)
                    ->orderBy('timein')
                    ->first();

                if ($log) {
                    $timeIn = $log->timein;
                    $timeOut = $log->timeout;

                    if ($timeIn && $timeOut) {
                        $start = new \DateTime($timeIn);
                        $end = new \DateTime($timeOut);
                        $interval = $start->diff($end);
                        $workingHours = $interval->format('%H:%I:%S');
                    } else {
                        $workingHours = "00:00:00";
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
                    'date' => $dateString,
                    'status' => $status,
                    'time_in' => $timeIn ? date('H:i:s', strtotime($timeIn)) : null,
                    'time_out' => $timeOut ? date('H:i:s', strtotime($timeOut)) : null,
                    'working_hours' => $workingHours,
                ];
            }
        }

        return response()->json([
            'data' => $report,
            'period' => [
                'type' => $type,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ],
        ]);
    }
}
