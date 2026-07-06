<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UsersLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class UserLogController extends Controller
{
    public function index(Request $request)
    {
        $authUser = Auth::user();

        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $query = DB::table('users_logs')
            ->join('users', 'users_logs.serialnumber', '=', 'users.serialnumber')
            ->join('devices', 'users.device_uid', '=', 'devices.device_uid')
            ->leftJoin('company', 'devices.company_id', '=', 'company.id')
            ->leftJoin('branch', 'devices.branch_id', '=', 'branch.id')
            ->leftJoin('department', 'users.department_id', '=', 'department.id')
            ->where('users.del_fingerid', 0);

        // ── Role-based filtering ──
        if ($authUser->user_types_id == 2) {
            // Company Admin
            $companyIds = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->pluck('company_id');

            if ($companyIds->isEmpty()) {
                return response()->json(['success' => true, 'data' => [], 'message' => 'No company found for this user.'], 200);
            }

            $branchIds = DB::table('branch')
                ->whereIn('company_id', $companyIds)
                ->pluck('id');

            $deviceUIDs = collect();

            if ($branchIds->isNotEmpty()) {
                $deviceUIDs = DB::table('branch_devices')
                    ->join('devices', 'branch_devices.device_id', '=', 'devices.id')
                    ->whereIn('branch_devices.branch_id', $branchIds)
                    ->pluck('devices.device_uid');
            }

            if ($deviceUIDs->isEmpty()) {
                $deviceUIDs = DB::table('devices')
                    ->whereIn('company_id', $companyIds)
                    ->pluck('device_uid');
            }

            if ($deviceUIDs->isNotEmpty()) {
                $query->whereIn('users.device_uid', $deviceUIDs);
            } else {
                return response()->json(['success' => true, 'data' => []], 200);
            }
        }

        if ($authUser->user_types_id == 3) {
            // Branch Manager
            $branchIds = DB::table('branch_user')
                ->where('user_id', $authUser->id)
                ->pluck('branch_id');

            $deviceUIDs = DB::table('branch_devices')
                ->join('devices', 'branch_devices.device_id', '=', 'devices.id')
                ->whereIn('branch_devices.branch_id', $branchIds)
                ->pluck('devices.device_uid');

            if ($deviceUIDs->isNotEmpty()) {
                $query->whereIn('users.device_uid', $deviceUIDs);
            } else {
                return response()->json(['success' => true, 'data' => []], 200);
            }
        }

        if ($authUser->user_types_id == 4) {
            // Department Admin — STRICT department-level scoping.
            // A single device can be shared by multiple departments, so we
            // scope by users.department_id directly rather than by the
            // device's company (which would leak other departments' logs).
            $departmentIds = DB::table('department_users')
                ->where('users_id', $authUser->id)
                ->pluck('department_id');

            if ($departmentIds->isEmpty()) {
                return response()->json(['success' => true, 'data' => []], 200);
            }

            $query->whereIn('users.department_id', $departmentIds);
        }

        if ($authUser->user_types_id == 5) {
            // Employee - show only own logs
            $query->where('users.id', $authUser->id);
        }

        // ── Request parameter filters ──

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('users_logs.checkindate', [$request->date_from, $request->date_to]);
        }

        if ($request->filled('time_from') && $request->filled('time_to')) {
            $query->whereBetween('users_logs.timein', [$request->time_from, $request->time_to]);
        }

        if ($request->filled('timeout_from') && $request->filled('timeout_to')) {
            $query->whereBetween('users_logs.timeout', [$request->timeout_from, $request->timeout_to]);
        }

        // fingerprint_id is OPTIONAL — if provided, filter by it; if not, show all (based on role)
        if ($request->filled('fingerprint_id') && $request->fingerprint_id != '0') {
            $query->where('users_logs.fingerprint_id', $request->fingerprint_id);
        }

        // ── device_uid filter ──
        if ($request->filled('device_uid') && $request->device_uid != '0') {
            $query->where('users.device_uid', $request->device_uid);
        }

        if ($request->filled('username') && $request->username != '0') {
            $query->where('users.username', $request->username);
        }

        if ($request->filled('device_dep') && $request->device_dep != '0') {
            $query->where('users_logs.device_dep', $request->device_dep);
        }

        if ($request->filled('branch_id') && $request->branch_id != '0') {
            $query->where('branch.id', $request->branch_id);
        }

        if ($request->filled('company_id') && $request->company_id != '0') {
            $query->where('company.id', $request->company_id);
        }

        if ($request->filled('department_id') && $request->department_id != '0') {
            $query->where('department.id', $request->department_id);
        }

        if ($request->filled('company') && $request->company != '0') {
            $query->where('company.name', $request->company);
        }

        if ($request->filled('department_name') && $request->department_name != '0') {
            $query->where('department.department_name', 'like', '%' . $request->department_name . '%');
        }

        $logs = $query
            ->select(
                'users_logs.serialnumber',
                'users.id as employee_id',
                'users.username',
                'users.device_uid',
                'users_logs.checkindate',
                DB::raw('MIN(users_logs.timein) as timein'),
                DB::raw('MAX(users_logs.timeout) as timeout'),
                'users_logs.device_dep',
                'users_logs.device_branch',
                'users_logs.fingerprint_id',
                'company.id as company_id',
                'company.name as company_name',
                'branch.id as branch_id',
                'branch.branch_name as branch_name',
                'department.id as department_id',
                'department.department_name'
            )
            ->groupBy(
                'users_logs.checkindate',
                'users_logs.serialnumber',
                'users.id',
                'users.username',
                'users_logs.device_dep',
                'users_logs.device_branch',
                'users.device_uid',
                'users_logs.fingerprint_id',
                'company.id',
                'company.name',
                'branch.id',
                'branch.branch_name',
                'department.id',
                'department.department_name'
            )
            ->orderBy('users_logs.checkindate', 'desc')
            ->orderBy(DB::raw('MIN(users_logs.timein)'), 'desc')
            ->get();

        // ── Time adjustment for UI ──
        $logs->transform(function ($log) {
            $checkinDate = Carbon::parse($log->checkindate);

            // Adjust timeout from 1st Sept 2025 onwards
            if ($checkinDate->gte(Carbon::create(2025, 9, 1)) && $log->timeout && $log->timeout != '00:00:00') {
                $log->timeout = Carbon::parse($log->timeout)->addMinutes(330)->format('H:i:s');
            }

            // Adjust timein from 2nd Sept 2025 onwards
            if ($checkinDate->gte(Carbon::create(2025, 9, 2)) && $log->timein) {
                $log->timein = Carbon::parse($log->timein)->addMinutes(330)->format('H:i:s');
            }

            return $log;
        });

        return response()->json(['success' => true, 'data' => $logs]);
    }


    public function todayUsers()
    {
        $authUser = Auth::user();

        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $today = Carbon::today()->toDateString();

        $query = DB::table('users_logs')
            ->join('users', 'users_logs.serialnumber', '=', 'users.serialnumber')
            ->join('devices', 'users.device_uid', '=', 'devices.device_uid')
            ->leftJoin('department', 'users.department_id', '=', 'department.id')
            ->where('users.del_fingerid', 0)
            ->select([
                'users.serialnumber',
                'users.username',
                'users.device_uid',
                'devices.device_name',
                'users_logs.checkindate',
                'users_logs.device_dep',
                'users_logs.device_branch',
                'users_logs.fingerprint_id',
                'department.department_name',
                DB::raw('MIN(users_logs.timein) as timein'),
                DB::raw('MAX(users_logs.timeout) as timeout'),
            ])
            ->whereDate('users_logs.checkindate', $today)
            ->groupBy(
                'users.serialnumber',
                'users.username',
                'users.device_uid',
                'devices.device_name',
                'users_logs.checkindate',
                'users_logs.device_dep',
                'users_logs.device_branch',
                'users_logs.fingerprint_id',
                'department.department_name'
            )
            ->orderBy(DB::raw('MIN(users_logs.timein)'), 'desc');

        if ($authUser->user_types_id == 2) {
            // Company Admin
            $companyIds = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->pluck('company_id');

            $deviceUIDs = DB::table('devices')
                ->whereIn('company_id', $companyIds)
                ->pluck('device_uid');

            if ($deviceUIDs->isNotEmpty()) {
                $query->whereIn('users.device_uid', $deviceUIDs);
            } else {
                return response()->json(['success' => true, 'data' => []], 200);
            }
        }

        if ($authUser->user_types_id == 3) {
            // Branch Manager
            $branchIds = DB::table('branch_user')
                ->where('user_id', $authUser->id)
                ->pluck('branch_id');

            $deviceUIDs = DB::table('branch_devices')
                ->join('devices', 'branch_devices.device_id', '=', 'devices.id')
                ->whereIn('branch_devices.branch_id', $branchIds)
                ->pluck('devices.device_uid');

            if ($deviceUIDs->isNotEmpty()) {
                $query->whereIn('users.device_uid', $deviceUIDs);
            } else {
                return response()->json(['success' => true, 'data' => []], 200);
            }
        }

        if ($authUser->user_types_id == 4) {
            // Department Admin — STRICT department-level scoping (see index()).
            $departmentIds = DB::table('department_users')
                ->where('users_id', $authUser->id)
                ->pluck('department_id');

            if ($departmentIds->isEmpty()) {
                return response()->json(['success' => true, 'data' => []], 200);
            }

            $query->whereIn('users.department_id', $departmentIds);
        }

        if ($authUser->user_types_id == 5) {
            // Employee - show only own logs
            $query->where('users.id', $authUser->id);
        }

        $logs = $query->get();

        // ── Time adjustment for UI ──
        $logs->transform(function ($log) {
            if ($log->timein) {
                $log->timein = Carbon::parse($log->timein)->addMinutes(330)->format('H:i:s');
            }
            if ($log->timeout && $log->timeout != '00:00:00') {
                $log->timeout = Carbon::parse($log->timeout)->addMinutes(330)->format('H:i:s');
            } else {
                $log->timeout = '00:00:00';
            }
            return $log;
        });

        return response()->json(['success' => true, 'data' => $logs]);
    }


    public function countTodayActiveUsers()
    {
        $authUser = Auth::user();

        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $today = Carbon::today()->toDateString();

        $query = DB::table('users_logs')
            ->join('users', 'users_logs.serialnumber', '=', 'users.serialnumber')
            ->whereDate('users_logs.checkindate', $today)
            ->where('users.del_fingerid', 0);

        if ($authUser->user_types_id == 2) {
            // Company Admin
            $companyIds = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->pluck('company_id');

            if ($companyIds->isEmpty()) {
                return response()->json(['date' => $today, 'active_user_count' => 0]);
            }

            $deviceUIDs = DB::table('devices')
                ->whereIn('company_id', $companyIds)
                ->pluck('device_uid');

            if ($deviceUIDs->isEmpty()) {
                return response()->json(['date' => $today, 'active_user_count' => 0]);
            }

            $query->whereIn('users.device_uid', $deviceUIDs);
        }

        if ($authUser->user_types_id == 3) {
            // Branch Manager
            $branchIds = DB::table('branch_user')
                ->where('user_id', $authUser->id)
                ->pluck('branch_id');

            if ($branchIds->isEmpty()) {
                return response()->json(['date' => $today, 'active_user_count' => 0]);
            }

            $deviceUIDs = DB::table('branch_devices')
                ->join('devices', 'branch_devices.device_id', '=', 'devices.id')
                ->whereIn('branch_devices.branch_id', $branchIds)
                ->pluck('devices.device_uid');

            if ($deviceUIDs->isEmpty()) {
                return response()->json(['date' => $today, 'active_user_count' => 0]);
            }

            $query->whereIn('users.device_uid', $deviceUIDs);
        }

        if ($authUser->user_types_id == 4) {
            // Department Admin — STRICT department-level scoping (see index()).
            $departmentIds = DB::table('department_users')
                ->where('users_id', $authUser->id)
                ->pluck('department_id');

            if ($departmentIds->isEmpty()) {
                return response()->json(['date' => $today, 'active_user_count' => 0]);
            }

            $query->whereIn('users.department_id', $departmentIds);
        }

        if ($authUser->user_types_id == 5) {
            // Employee - count only own logs
            $query->where('users.id', $authUser->id);
        }

        // FIX: distinct('column')->count('column') is unreliable across DB
        // drivers in Laravel's query builder. Use distinct() with no args
        // plus an explicit count column instead.
        $activeUserCount = $query->distinct()->count('users.fingerprint_id');

        return response()->json([
            'date' => $today,
            'active_user_count' => $activeUserCount
        ]);
    }
}