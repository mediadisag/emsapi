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
            ->leftJoin('department', 'users.department_id', '=', 'department.id')
            ->where('users.del_fingerid', 0);

        // Role-based filtering
        if ($authUser->user_types_id == 2) {
            // Company Admin
            $companyIds = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->pluck('company_id');

            if ($companyIds->isEmpty()) {
                return response()->json(['data' => [], 'message' => 'No company found for this user.'], 404);
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
                return response()->json(['data' => [], 'message' => 'No devices found for this company.'], 404);
            }
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

        if ($authUser->user_types_id == 4) {
            // Department Admin: only logs of users under assigned departments or users with no department but matching device_dep of those departments
            $departmentIds = DB::table('department_users')
                ->where('users_id', $authUser->id)
                ->pluck('department_id');

            if ($departmentIds->isEmpty()) {
                return response()->json(['data' => [], 'message' => 'No departments assigned to this user.']);
            }

            $query->where(function ($q) use ($departmentIds) {
                $q->whereIn('users.department_id', $departmentIds)
                  ->orWhere(function ($q2) use ($departmentIds) {
                      $q2->where(function ($q3) {
                          $q3->whereNull('users.department_id')
                             ->orWhere('users.department_id', 0);
                      })
                      ->whereIn('users_logs.device_dep', function ($sub) use ($departmentIds) {
                          $sub->select('department_name')
                              ->from('department')
                              ->whereIn('id', $departmentIds);
                      });
                  });
            });
        }

        // Filters by request parameters...

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('users_logs.checkindate', [$request->date_from, $request->date_to]);
        }

        if ($request->filled('time_from') && $request->filled('time_to')) {
            $query->whereBetween('users_logs.timein', [$request->time_from, $request->time_to]);
        }

        if ($request->filled('timeout_from') && $request->filled('timeout_to')) {
            $query->whereBetween('users_logs.timeout', [$request->timeout_from, $request->timeout_to]);
        }

        if ($request->filled('fingerprint_id') && $request->fingerprint_id != '0') {
            $query->where('users_logs.fingerprint_id', $request->fingerprint_id);
        }

        if ($request->filled('username') && $request->username != '0') {
            $query->where('users_logs.username', $request->username);
        }

        if ($request->filled('device_dep') && $request->device_dep != '0') {
            $query->where('users_logs.device_dep', $request->device_dep);
        }

        if ($request->filled('branch') && $request->branch != '0') {
            $query->where('users_logs.device_branch', $request->branch);
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
                'users.username',
                'users.device_uid',
                'users_logs.checkindate',
                DB::raw('MIN(users_logs.timein) as timein'),
                DB::raw('MAX(users_logs.timeout) as timeout'),
                'users_logs.device_dep',
                'users_logs.device_branch',
                'users_logs.fingerprint_id',
                'company.name as company_name',
                'department.department_name'  
            )
            ->groupBy(
                'users_logs.checkindate',
                'users_logs.serialnumber',
                'users.username',
                'users_logs.device_dep',
                'users_logs.device_branch',
                'users.device_uid',
                'users_logs.fingerprint_id',
                'company.name',
                'department.department_name' 
            )
            ->orderBy('users_logs.checkindate', 'desc')
            ->orderBy(DB::raw('MIN(users_logs.timein)'), 'desc')
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
            $companyIds = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->pluck('company_id');

            $deviceUIDs = DB::table('devices')
                ->whereIn('company_id', $companyIds)
                ->pluck('device_uid');

            if ($deviceUIDs->isEmpty()) {
                return response()->json(['data' => [], 'message' => 'No devices found for this company.']);
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
                return response()->json(['data' => [], 'message' => 'No devices found for this branch.']);
            }

            $query->whereIn('users.device_uid', $deviceUIDs);
        }

        if ($authUser->user_types_id == 4) {
            $departmentIds = DB::table('department_users')
                ->where('users_id', $authUser->id)
                ->pluck('department_id');

            if ($departmentIds->isEmpty()) {
                return response()->json(['data' => [], 'message' => 'No departments assigned to this user.']);
            }

            $query->where(function ($q) use ($departmentIds) {
                $q->whereIn('users.department_id', $departmentIds)
                  ->orWhere(function ($q2) use ($departmentIds) {
                      $q2->where(function ($q3) {
                          $q3->whereNull('users.department_id')
                             ->orWhere('users.department_id', 0);
                      })
                      ->whereIn('users_logs.device_dep', function ($sub) use ($departmentIds) {
                          $sub->select('department_name')
                              ->from('department')
                              ->whereIn('id', $departmentIds);
                      });
                  });
            });
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

        if ($authUser->user_types_id == 4) {
            // Department Admin
            $departmentIds = DB::table('department_users')
                ->where('users_id', $authUser->id)
                ->pluck('department_id');

            if ($departmentIds->isEmpty()) {
                return response()->json(['date' => $today, 'active_user_count' => 0]);
            }

            $query->where(function ($q) use ($departmentIds) {
                $q->whereIn('users.department_id', $departmentIds)
                  ->orWhere(function ($q2) use ($departmentIds) {
                      $q2->where(function ($q3) {
                          $q3->whereNull('users.department_id')
                             ->orWhere('users.department_id', 0);
                      })
                      ->whereIn('users_logs.device_dep', function ($sub) use ($departmentIds) {
                          $sub->select('department_name')
                              ->from('department')
                              ->whereIn('id', $departmentIds);
                      });
                  });
            });
        }

        // Count distinct users based on fingerprint ID
        $activeUserCount = $query->distinct('users.fingerprint_id')->count('users.fingerprint_id');

        return response()->json([
            'date' => $today,
            'active_user_count' => $activeUserCount
        ]);
    }
}
