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

    $query = DB::table('users_logs');

    // Join with users table to get device_uid if needed for access filtering
    $query->join('users', 'users_logs.serialnumber', '=', 'users.serialnumber');

    // Role-based filtering
    if ($authUser->user_types_id == 2) {
        // Company Admin
        $companyIds = DB::table('company_users')
            ->where('users_id', $authUser->id)
            ->pluck('company_id');

        $branchIds = DB::table('branch')
            ->whereIn('company_id', $companyIds)
            ->pluck('id');

        $deviceUIDs = DB::table('branch_devices')
            ->join('devices', 'branch_devices.device_id', '=', 'devices.id')
            ->whereIn('branch_devices.branch_id', $branchIds)
            ->pluck('devices.device_uid');

        $query->whereIn('users.device_uid', $deviceUIDs);
    }

    if ($authUser->user_types_id == 3) {
        // Branch Admin
        $branchIds = DB::table('branch_user')
            ->where('user_id', $authUser->id)
            ->pluck('branch_id');

        $deviceUIDs = DB::table('branch_devices')
            ->join('devices', 'branch_devices.device_id', '=', 'devices.id')
            ->whereIn('branch_devices.branch_id', $branchIds)
            ->pluck('devices.device_uid');

        $query->whereIn('users.device_uid', $deviceUIDs);
    }

    // Filter by Date Range
    if ($request->filled('date_from') && $request->filled('date_to')) {
        $query->whereBetween('users_logs.checkindate', [$request->date_from, $request->date_to]);
    }

    // Filter by Time-in Range
    if ($request->filled('time_from') && $request->filled('time_to')) {
        $query->whereBetween('users_logs.timein', [$request->time_from, $request->time_to]);
    }

    // Filter by Time-out Range
    if ($request->filled('timeout_from') && $request->filled('timeout_to')) {
        $query->whereBetween('users_logs.timeout', [$request->timeout_from, $request->timeout_to]);
    }

    // Filter by Fingerprint ID
    if ($request->filled('fingerprint_id') && $request->fingerprint_id != '0') {
        $query->where('users_logs.fingerprint_id', $request->fingerprint_id);
    }

    // Filter by Username
    if ($request->filled('username') && $request->username != '0') {
        $query->where('users_logs.username', $request->username);
    }

    // Filter by Device Department
    if ($request->filled('device_dep') && $request->device_dep != '0') {
        $query->where('users_logs.device_dep', $request->device_dep);
    }

    // Filter by Device Branch
    if ($request->filled('branch') && $request->branch != '0') {
        $query->where('users_logs.device_branch', $request->branch);
    }

    // Final result
    $logs = $query
        ->select(
          'users_logs.serialnumber',
        'users.username',
        'users.device_uid',
        'users_logs.checkindate',
        DB::raw('MIN(users_logs.timein) as timein'),
        DB::raw('MAX(users_logs.timeout) as timeout'),
        'users_logs.device_dep',
        'users_logs.device_branch',
        'users_logs.fingerprint_id'
    )
    ->groupBy(
        'users_logs.checkindate',
        'users_logs.serialnumber',
        'users.username',
        'users_logs.device_dep',
        'users_logs.device_branch',
        'users.device_uid',
        'users_logs.fingerprint_id'
    )
    ->orderBy('users_logs.checkindate', 'desc')
    ->orderBy(DB::raw('MIN(users_logs.timein)'), 'asc')
    ->get();

    return response()->json(['data' => $logs]);
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
        ->select([
            'users.serialnumber',
            'users.username',
            'users.device_uid',
            'devices.device_name',
            'users_logs.checkindate',
            'users_logs.device_dep',
            'users_logs.device_branch',
            'users_logs.fingerprint_id',
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
            'users_logs.fingerprint_id'
        )
        ->orderBy('users_logs.checkindate', 'desc')
        ->orderBy(DB::raw('MIN(users_logs.timein)'));

    if ($authUser->user_types_id == 2) {
        $companyIds = DB::table('company_users')
            ->where('users_id', $authUser->id)
            ->pluck('company_id');

        $branchIds = DB::table('branch')
            ->whereIn('company_id', $companyIds)
            ->pluck('id');

        $deviceUIDs = DB::table('branch_devices')
            ->join('devices', 'branch_devices.device_id', '=', 'devices.id')
            ->whereIn('branch_devices.branch_id', $branchIds)
            ->pluck('devices.device_uid');

        if ($deviceUIDs->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $query->whereIn('users.device_uid', $deviceUIDs);
    }

    if ($authUser->user_types_id == 3) {
        $branchIds = DB::table('branch_user')
            ->where('user_id', $authUser->id)
            ->pluck('branch_id');

        $deviceUIDs = DB::table('branch_devices')
            ->join('devices', 'branch_devices.device_id', '=', 'devices.id')
            ->whereIn('branch_devices.branch_id', $branchIds)
            ->pluck('devices.device_uid');

        if ($deviceUIDs->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $query->whereIn('users.device_uid', $deviceUIDs);
    }

    $logs = $query->get();

    return response()->json(['data' => $logs]);
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
        ->whereDate('users_logs.checkindate', $today);

    if ($authUser->user_types_id == 2) {
        // Company Admin
        $companyIds = DB::table('company_users')
            ->where('users_id', $authUser->id)
            ->pluck('company_id');

        if ($companyIds->isEmpty()) {
            return response()->json(['date' => $today, 'active_user_count' => 0]);
        }

        $branchIds = DB::table('branch')
            ->whereIn('company_id', $companyIds)
            ->pluck('id');

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

    if ($authUser->user_types_id == 3) {
        // Branch Admin
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

    $activeUserCount = $query->distinct('users.fingerprint_id')->count('users.fingerprint_id');

    return response()->json([
        'date' => $today,
        'active_user_count' => $activeUserCount
    ]);
}
}