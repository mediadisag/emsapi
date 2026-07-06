<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    // ────────────────────────────────────────────────────────────────────────
    // HELPER: resolve department_id for a type-4 (Dept Admin) user
    // Resolution order:
    //   1. users.department_id  (direct column)
    //   2. department_users table
    // (mirrors ManageUserController::resolveDeptIdForDeptAdmin so both
    //  controllers agree on what "own department" means)
    // ────────────────────────────────────────────────────────────────────────
    private function resolveDeptIdForDeptAdmin($authUser): ?int
    {
        // 1. Direct department_id on the user row
        if (!empty($authUser->department_id)) {
            return (int) $authUser->department_id;
        }

        // 2. Via department_users table
        $deptId = DB::table('department_users')
            ->where('users_id', $authUser->id)
            ->value('department_id');

        return $deptId ? (int) $deptId : null;
    }

    public function listUsersByAccessAndDepartmentOrDevice(Request $request)
    {
        $authUser = Auth::user();

        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $departmentId = $request->input('department_id');
        $deviceDep    = $request->input('device_dep');

        $query = DB::table('users')
            ->leftJoin('department', 'users.department_id', '=', 'department.id')
            ->leftJoin('devices', 'users.device_uid', '=', 'devices.device_uid')
            ->leftJoin('company as department_company', 'department.company_id', '=', 'department_company.id')
            ->leftJoin('company as device_company', 'devices.company_id', '=', 'device_company.id')
            // ✅ NEW: join user_types to resolve the role name for the User Type column
            ->leftJoin('user_types', 'users.user_types_id', '=', 'user_types.id')
            ->select([
                'users.id',
                'users.username',
                'users.serialnumber',
                'users.gender',
                'users.fingerprint_id',
                'users.user_date as date',
                'users.device_uid',
                'users.device_dep',
                'users.department_id',
                'users.company_id',
                'users.branch_id',
                'department.department_name',
                'devices.device_name',
                'users.email',
                'users.user_types_id',
                // ✅ NEW: human-readable user type name (e.g. "Employee", "Dept Admin")
                'user_types.name as user_type_name',
                DB::raw('COALESCE(department_company.name, device_company.name) as company_name'),
            ])
            ->where('users.del_fingerid', 0)
            // ✅ REMOVED: previously excluded user_types_id [1,2,3,4] (Super Admin,
            // Company Admin, Branch Admin, Dept Admin), showing ONLY Employees (type 5).
            // Now ALL user types within the resolved scope (department/company/branch)
            // are shown, so a Dept Admin sees every type of user in their department.
            ->orderBy('users.id', 'desc');

        // ── Request-level filters ──────────────────────────────────────────
        if ($departmentId) {
            $query->where('users.department_id', $departmentId);
        } elseif ($deviceDep) {
            $query->where(function ($q) use ($deviceDep) {
                $q->whereNull('users.department_id')
                    ->orWhere('users.department_id', 0);
            })->where('users.device_dep', $deviceDep);
        }

        // ── Role-based filtering ───────────────────────────────────────────
        if ($authUser->user_types_id == 1) {
            // Super Admin — sees everything

        } elseif ($authUser->user_types_id == 2) {
            // Company Admin — sees employees belonging to their companies
            $companyIds = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->pluck('company_id');

            if ($companyIds->isEmpty()) {
                return response()->json([
                    'data'    => [],
                    'message' => 'No companies assigned to this user.'
                ]);
            }

            $query->where(function ($q) use ($companyIds) {
                $q->whereIn('department.company_id', $companyIds)
                    ->orWhereIn('devices.company_id', $companyIds);
            });

        } elseif ($authUser->user_types_id == 3) {
            // Branch Admin — sees employees belonging to their branches
            $branchIds = DB::table('branch_user')
                ->where('users_id', $authUser->id)
                ->pluck('branch_id');

            if ($branchIds->isEmpty()) {
                return response()->json(['data' => []]);
            }

            $query->where(function ($q) use ($branchIds) {
                $q->whereIn('department.branch_id', $branchIds)
                    ->orWhereIn('devices.branch_id', $branchIds);
            });

        } elseif ($authUser->user_types_id == 4) {
            // ✅ FIX: Dept Admin — sees ONLY their own department's employees.
            // Previously this gave full company scope (via department.company_id
            // OR devices.company_id), which was too broad. Now matches the
            // corrected behaviour in ManageUserController::index.
            $deptId = $this->resolveDeptIdForDeptAdmin($authUser);

            if (!$deptId) {
                return response()->json([
                    'data'    => [],
                    'message' => 'Could not determine department for this user.'
                ]);
            }

            $query->where('users.department_id', $deptId);

        } else {
            return response()->json(['message' => 'Invalid user role.'], 403);
        }

        $users = $query->get()->map(function ($user) {
            $user->fingerprint_status = $user->fingerprint_id ? 'Added' : 'Not Added';
            // ✅ Fallback in case user_types_id is null/unmatched, so the
            // frontend always has something sensible to display.
            if (empty($user->user_type_name)) {
                $user->user_type_name = 'Employee';
            }
            return $user;
        });

        return response()->json(['data' => $users]);
    }

    public function companyAdmins()
    {
        $authUser = Auth::user();
        if (!$authUser || $authUser->user_types_id != 1) {
            return response()->json([
                'message' => 'Unauthorized. Only Super Admin can view company admins.'
            ], 403);
        }

        $companyAdmins = DB::table('users')
            ->select([
                'id', 'username', 'email', 'serialnumber',
                'fingerprint_id', 'device_uid', 'device_dep', 'user_types_id',
            ])
            ->where('user_types_id', 2)
            ->get();

        return response()->json(['data' => $companyAdmins]);
    }

    public function branchAdmins()
    {
        $authUser = Auth::user();
        if (!$authUser || !in_array($authUser->user_types_id, [1, 2])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $branchAdmins = DB::table('users')
            ->select(['id', 'username', 'email', 'user_types_id'])
            ->where('user_types_id', 3)
            ->get();

        return response()->json(['data' => $branchAdmins]);
    }
}