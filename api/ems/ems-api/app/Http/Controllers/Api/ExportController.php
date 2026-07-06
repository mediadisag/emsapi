<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;


class ExportController extends Controller
{
   public function export(Request $request): StreamedResponse
    {
        $request->validate([
            'export_format' => 'required|in:csv,excel',
            'date_sel_start' => 'nullable|date',
            'date_sel_end' => 'nullable|date',
            'branch_sel' => 'nullable|string',
            'fing_sel' => 'nullable|string',
            'time_sel' => 'nullable|in:Time_in,Time_out',
            'time_sel_start' => 'nullable|date_format:H:i:s',
            'time_sel_end' => 'nullable|date_format:H:i:s',
        ]);

        $conditions = [];

        if ($request->filled(['date_sel_start', 'date_sel_end'])) {
            $conditions[] = [DB::raw('DATE(ul.checkindate)'), '>=', $request->date_sel_start];
            $conditions[] = [DB::raw('DATE(ul.checkindate)'), '<=', $request->date_sel_end];
        }

        if ($request->filled('branch_sel') && $request->branch_sel !== '0') {
            $conditions[] = ['u.device_branch', '=', $request->branch_sel];
        }

        if ($request->filled('fing_sel') && $request->fing_sel !== '0') {
            $finger_id = DB::table('users')
                ->where('username', $request->fing_sel)
                ->value('fingerprint_id');
            if ($finger_id) {
                $conditions[] = ['ul.fingerprint_id', '=', $finger_id];
            }
        }

        $time_column = null;
        if ($request->filled(['time_sel', 'time_sel_start', 'time_sel_end'])) {
            $time_column = $request->time_sel === 'Time_in' ? 'timein' : 'timeout';
        }

        $query = DB::table('users_logs as ul')
            ->leftJoin('devices as d', 'ul.device_uid', '=', 'd.device_uid')
            ->leftJoin('users as u', 'ul.fingerprint_id', '=', 'u.fingerprint_id')
            ->select([
                'ul.id', 'ul.username', 'ul.serialnumber', 'ul.fingerprint_id',
                'u.device_branch as branch_name', 'd.location', 'ul.checkindate',
                'ul.timein', 'ul.timeout', 'u.start_time', 'u.end_time', 'u.hourly_pay',
                DB::raw(
                    "CASE WHEN ul.timeout = '00:00:00' THEN 0 
                    ELSE TIMESTAMPDIFF(HOUR, ul.timein, ul.timeout) END as hours_worked"
                ),
                DB::raw(
                    "CASE WHEN TIME_TO_SEC(TIMEDIFF(ul.timein, u.start_time)) > 0 
                    THEN ROUND(TIME_TO_SEC(TIMEDIFF(ul.timein, u.start_time))/60, 2) ELSE 0 END as late_minutes"
                ),
                DB::raw(
                    "CASE WHEN ul.timeout != '00:00:00' AND TIME_TO_SEC(TIMEDIFF(ul.timeout, u.end_time)) > 0 
                    THEN ROUND(TIME_TO_SEC(TIMEDIFF(ul.timeout, u.end_time))/60, 2) ELSE 0 END as overtime_minutes"
                )
            ])
            ->where($conditions);

        if ($time_column) {
            $query->whereBetween(DB::raw("TIME(ul.$time_column)"), [$request->time_sel_start, $request->time_sel_end]);
        }

        $results = $query->orderByDesc('ul.checkindate')->orderByDesc('ul.timein')->get();

        $show_pay_info = $request->filled('fing_sel') && $request->fing_sel !== '0';
        $export_format = $request->export_format;

        $headers = [
            'Sl_No', 'Name', 'Employee_Id', 'Fingerprint ID',
            'Branch', 'Location', 'Checkin Date',
            'Hours Worked', 'Time In', 'Time Out',
            'Late By (minutes)', 'Overtime (minutes)'
        ];

        if ($show_pay_info) {
            $headers[] = 'Total Pay';
        }

        $callback = function () use ($results, $headers, $show_pay_info) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($file, $headers);

            foreach ($results as $row) {
                $pay = $row->hours_worked * ($row->hourly_pay ?? 0);
                $line = [
                    $row->id,
                    $row->username,
                    $row->serialnumber,
                    $row->fingerprint_id,
                    $row->branch_name,
                    $row->location,
                    $row->checkindate,
                    $row->hours_worked,
                    $row->timein,
                    $row->timeout,
                    $row->late_minutes,
                    $row->overtime_minutes,
                ];
                if ($show_pay_info) {
                    $line[] = number_format($pay, 2);
                }
                fputcsv($file, $line);
            }

            fclose($file);
        };

        $filename = 'UserLog_' . now()->format('Y-m-d') . '.csv';

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }
}
