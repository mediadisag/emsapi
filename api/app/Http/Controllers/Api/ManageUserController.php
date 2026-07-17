<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Models\Device;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;


class ManageUserController extends Controller
{
    // ────────────────────────────────────────────────────────────────────────
    // HELPER: resolve department_id for a type-4 (Dept Admin) user
    // Resolution order:
    //   1. users.department_id  (direct column)
    //   2. department_users table
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


    // ────────────────────────────────────────────────────────────────────────
    // HELPER: resolve branch_id for a type-3 (Branch Manager) user
    // Resolution order:
    //   1. users.branch_id  (direct column)  ← primary source
    //   2. branch_users table (fallback, table may be empty)
    // ────────────────────────────────────────────────────────────────────────
    private function resolveBranchIdForBranchManager($authUser): ?int
    {
        // 1. Direct branch_id on the user row
        if (!empty($authUser->branch_id)) {
            return (int) $authUser->branch_id;
        }

        // 2. Fallback via branch_users table (plural — correct table name)
        $branchId = DB::table('branch_users')
            ->where('users_id', $authUser->id)
            ->value('branch_id');

        return $branchId ? (int) $branchId : null;
    }


    // ────────────────────────────────────────────────────────────────────────
    // INDEX — list employees
    // ────────────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $query = DB::table('users')
            ->leftJoin('department', 'users.department_id', '=', 'department.id')
            ->leftJoin('devices', 'users.device_uid', '=', 'devices.device_uid')
            ->leftJoin('company as dept_company', 'department.company_id', '=', 'dept_company.id')
            ->leftJoin('company as dev_company', 'devices.company_id', '=', 'dev_company.id')
            ->select([
                'users.id',
                'users.username',
                'users.serialnumber',
                'users.gender',
                'users.fingerprint_id',
                'users.user_date',
                'users.device_uid',
                'users.device_dep',
                'users.department_id',
                'users.branch_id',
                'users.company_id',
                'department.department_name',
                'devices.device_name',
                'users.email',
                'users.alternative_mobile',
                'users.bank_name',
                'users.account_number',
                'users.ifsc_code',
                DB::raw('COALESCE(dept_company.name, dev_company.name) as company_name'),
            ])
            ->where('users.del_fingerid', 0)
            ->where(function ($q) {
                $q->whereNull('users.user_types_id')
                  ->orWhereNotIn('users.user_types_id', [1, 2, 3, 4]);
            })
            ->orderBy('users.id', 'desc');

        // ── Role-based filtering ───────────────────────────────────────────
        if ($authUser->user_types_id == 1) {
            // Super Admin — sees everything, no filter needed

        } elseif ($authUser->user_types_id == 2) {
            // Company Admin — sees all employees in their company
            $companyIds = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->pluck('company_id');

            $query->where(function ($q) use ($companyIds) {
                $q->whereIn('department.company_id', $companyIds)
                  ->orWhereIn('devices.company_id', $companyIds);
            });

        } elseif ($authUser->user_types_id == 3) {
            // ✅ FIX: Branch Manager — use users.branch_id directly
            // Old code used 'branch_user' (wrong name) and table was empty anyway
            $branchId = $this->resolveBranchIdForBranchManager($authUser);

            if (!$branchId) {
                return response()->json([
                    'data'    => [],
                    'users'   => [],
                    'message' => 'Could not determine branch for this user.'
                ]);
            }

            $query->where('department.branch_id', $branchId);

        } elseif ($authUser->user_types_id == 4) {
            // ✅ FIX: Department Admin — sees ONLY their own department's employees
            // Old code gave full company scope — wrong!
            $deptId = $this->resolveDeptIdForDeptAdmin($authUser);

            if (!$deptId) {
                return response()->json([
                    'data'    => [],
                    'users'   => [],
                    'message' => 'Could not determine department for this user.'
                ]);
            }

            $query->where('users.department_id', $deptId);
        }

        $users = $query->get();
        return response()->json(['data' => $users, 'users' => $users]);
    }


    // ────────────────────────────────────────────────────────────────────────
    // STORE — add new employee
    // ────────────────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // ✅ FIX: Login credentials (email/password) are now required for
        // EVERY user type, including Employee (5) — the frontend
        // (add-employee.html) already collects and sends them for all
        // types, but this validation previously only required them when
        // user_types_id !== 5, so Employee-type requests could be saved
        // without email/password. That mismatch is what was silently
        // dropping email/password when adding an Employee.
        $rules = [
            'username'        => 'required|string',
            'gender'          => 'required|in:Male,Female',
            'department_name' => 'required|string|exists:department,department_name',
            'user_types_id'   => 'required|integer|exists:user_types,id',
            'email'           => 'required|email|unique:users,email',
            'password'        => 'required|string|min:8',
            // ✅ bank / contact details (all optional)
            'alternative_mobile' => 'nullable|string|max:15',
            'bank_name'           => 'nullable|string|max:100',
            'account_number'      => 'nullable|string|max:30',
            'ifsc_code'           => ['nullable', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
        ];

        $userTypeId = (int) $request->input('user_types_id');

        $request->validate($rules);

        $department = DB::table('department')
            ->where('department_name', $request->department_name)
            ->first();

        if (!$department) {
            return response()->json(['message' => 'Department not found'], 422);
        }

        // Role-based access check
        switch ($authUser->user_types_id) {
            case 1:
                // Super Admin — no restriction
                break;

            case 2:
                // Company Admin — must be their company
                $companyIds = DB::table('company_users')
                    ->where('users_id', $authUser->id)
                    ->pluck('company_id')->toArray();
                if (!in_array($department->company_id, $companyIds)) {
                    return response()->json(['message' => 'Permission denied: department not in your company'], 403);
                }
                break;

            case 3:
                // ✅ FIX: Branch Manager — use users.branch_id, not branch_user table
                $branchId = $this->resolveBranchIdForBranchManager($authUser);
                if (!$branchId || (int) $department->branch_id !== $branchId) {
                    return response()->json(['message' => 'Permission denied: department not in your branch'], 403);
                }
                break;

            case 4:
                // ✅ FIX: Dept Admin — can only add to their OWN department
                $deptId = $this->resolveDeptIdForDeptAdmin($authUser);
                if (!$deptId || (int) $department->id !== $deptId) {
                    return response()->json(['message' => 'Permission denied: you can only add employees to your own department'], 403);
                }
                break;

            default:
                return response()->json(['message' => 'Invalid user role'], 403);
        }

        // Build user data
        $userData = [
            'username'       => $request->username,
            'fingerprint_id' => $request->serialnumber,
            'gender'         => $request->gender,
            'user_date'      => now(),
            'department_id'  => $department->id,
            'device_uid'     => $department->device_uid,
            'add_fingerid'   => 2,
            'user_types_id'  => $userTypeId,
            'company_id'     => $department->company_id,
            'branch_id'      => $department->branch_id,
            // ✅ Login credentials — always saved now, for every user type
            'email'          => $request->email,
            'password'       => \Illuminate\Support\Facades\Hash::make($request->password),
            // ✅ bank / contact details
            'alternative_mobile' => $request->alternative_mobile,
            'bank_name'           => $request->bank_name,
            'account_number'      => $request->account_number,
            'ifsc_code'           => $request->ifsc_code ? strtoupper($request->ifsc_code) : null,
        ];

        $user = User::create($userData);

        DB::table('department_users')->insert([
            'users_id'      => $user->id,
            'department_id' => $department->id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return response()->json([
            'message' => 'User added successfully',
            'user'    => $user,
        ], 201);
    }


    // ────────────────────────────────────────────────────────────────────────
    // UPDATE — edit employee
    // ────────────────────────────────────────────────────────────────────────
    public function update(Request $request, $id)
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $user = User::findOrFail($id);

        // Before editing, verify this user is in the admin's scope
        switch ($authUser->user_types_id) {
            case 1:
                break; // Super Admin — can edit anyone

            case 2:
                $companyIds = DB::table('company_users')
                    ->where('users_id', $authUser->id)
                    ->pluck('company_id')->toArray();
                $userDept = DB::table('department')->where('id', $user->department_id)->first();
                if (!$userDept || !in_array($userDept->company_id, $companyIds)) {
                    return response()->json(['message' => 'Permission denied'], 403);
                }
                break;

            case 3:
                // ✅ FIX: use branch_id direct
                $branchId = $this->resolveBranchIdForBranchManager($authUser);
                if (!$branchId || (int) $user->branch_id !== $branchId) {
                    return response()->json(['message' => 'Permission denied: user not in your branch'], 403);
                }
                break;

            case 4:
                // ✅ FIX: Dept Admin — can only edit their own dept's employees
                $deptId = $this->resolveDeptIdForDeptAdmin($authUser);
                if (!$deptId || (int) $user->department_id !== $deptId) {
                    return response()->json(['message' => 'Permission denied: user not in your department'], 403);
                }
                break;

            default:
                return response()->json(['message' => 'Invalid user role'], 403);
        }

        $data = $request->all();

        // ✅ validate bank / contact details when present on update too
        $request->validate([
            'alternative_mobile' => 'nullable|string|max:15',
            'bank_name'           => 'nullable|string|max:100',
            'account_number'      => 'nullable|string|max:30',
            'ifsc_code'           => ['nullable', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
        ]);

        if (isset($data['ifsc_code']) && $data['ifsc_code']) {
            $data['ifsc_code'] = strtoupper($data['ifsc_code']);
        }

        // ✅ FIX: hash the password if the admin sent a new one on update.
        // Previously $request->all() was passed straight into $user->update(),
        // which would have written a plaintext password over the hashed one
        // whenever a 'password' field was included. Now: hash if present &
        // non-empty, drop the field entirely if blank (meaning "no change").
        if (array_key_exists('password', $data)) {
            if (!empty($data['password'])) {
                $data['password'] = \Illuminate\Support\Facades\Hash::make($data['password']);
            } else {
                unset($data['password']);
            }
        }

        if (isset($data['department_name'])) {
            $request->validate([
                'department_name' => 'string|exists:department,department_name',
            ]);

            $department = DB::table('department')
                ->where('department_name', $data['department_name'])
                ->first();

            if (!$department) {
                return response()->json(['message' => 'Department not found'], 422);
            }

            // Verify permission to assign to new department
            switch ($authUser->user_types_id) {
                case 1:
                    break;

                case 2:
                    $companyIds = DB::table('company_users')
                        ->where('users_id', $authUser->id)
                        ->pluck('company_id')->toArray();
                    if (!in_array($department->company_id, $companyIds)) {
                        return response()->json(['message' => 'Permission denied: target department not in your company'], 403);
                    }
                    break;

                case 3:
                    $branchId = $this->resolveBranchIdForBranchManager($authUser);
                    if (!$branchId || (int) $department->branch_id !== $branchId) {
                        return response()->json(['message' => 'Permission denied: target department not in your branch'], 403);
                    }
                    break;

                case 4:
                    // Dept Admin — can only keep user in their own department
                    $deptId = $this->resolveDeptIdForDeptAdmin($authUser);
                    if (!$deptId || (int) $department->id !== $deptId) {
                        return response()->json(['message' => 'Permission denied: you can only assign users to your own department'], 403);
                    }
                    break;
            }

            $data['department_id'] = $department->id;

            DB::table('department_users')
                ->where('users_id', $user->id)
                ->update(['department_id' => $department->id]);
        }

        if (!isset($data['user_date'])) {
            $data['user_date'] = now();
        }

        $user->update($data);

        return response()->json(['message' => 'User updated', 'user' => $user]);
    }


    // ────────────────────────────────────────────────────────────────────────
    // DESTROY — soft delete
    // ────────────────────────────────────────────────────────────────────────
    public function destroy($id)
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $user = User::findOrFail($id);

        // Scope check before deleting
        switch ($authUser->user_types_id) {
            case 1:
                break;

            case 2:
                $companyIds = DB::table('company_users')
                    ->where('users_id', $authUser->id)
                    ->pluck('company_id')->toArray();
                $userDept = DB::table('department')->where('id', $user->department_id)->first();
                if (!$userDept || !in_array($userDept->company_id, $companyIds)) {
                    return response()->json(['message' => 'Permission denied'], 403);
                }
                break;

            case 3:
                $branchId = $this->resolveBranchIdForBranchManager($authUser);
                if (!$branchId || (int) $user->branch_id !== $branchId) {
                    return response()->json(['message' => 'Permission denied'], 403);
                }
                break;

            case 4:
                $deptId = $this->resolveDeptIdForDeptAdmin($authUser);
                if (!$deptId || (int) $user->department_id !== $deptId) {
                    return response()->json(['message' => 'Permission denied'], 403);
                }
                break;

            default:
                return response()->json(['message' => 'Invalid user role'], 403);
        }

        $user->del_fingerid = 1;
        $user->save();

        return response()->json(['message' => 'User removed']);
    }


    public function availableFingerprintIds(Request $request)
    {
        $request->validate([
            'device_uid' => 'required|string'
        ]);

        $usedIds = User::where('device_uid', $request->device_uid)
            ->pluck('fingerprint_id')
            ->toArray();

        $allIds       = range(1, 127);
        $availableIds = array_values(array_diff($allIds, array_map('intval', $usedIds)));

        return response()->json([
            'device_uid'                => $request->device_uid,
            'available_fingerprint_ids' => $availableIds
        ]);
    }


    public function selectUserForFingerprint(Request $request)
    {
        $request->validate([
            'serialnumber' => 'required|numeric',
            'device_uid'   => 'required|string',
        ]);

        $user = User::where('serialnumber', $request->serialnumber)
            ->where('device_uid', $request->device_uid)
            ->first();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        User::where('device_uid', $user->device_uid)
            ->update([
                'fingerprint_select' => 0,
                'add_fingerid'       => 0,
            ]);

        $user->update([
            'fingerprint_select' => 1,
            'add_fingerid'       => 1,
        ]);

        return response()->json([
            'message' => 'User selected for fingerprint enrollment',
            'user'    => $user
        ]);
    }


    public function addFingerprintId(Request $request)
    {
        $request->validate([
            'fingerprint_id' => 'required|integer|min:1|max:127',
            'device_uid'     => 'required|string',
            'serialnumber'   => 'required|numeric',
        ]);

        $alreadyUsed = User::where('fingerprint_id', $request->fingerprint_id)
            ->where('device_uid', $request->device_uid)
            ->exists();

        if ($alreadyUsed) {
            return response()->json(['message' => 'Fingerprint ID already in use'], 409);
        }

        $user = User::where('serialnumber', $request->serialnumber)
            ->where('device_uid', $request->device_uid)
            ->where('fingerprint_select', 1)
            ->where('add_fingerid', 1)
            ->first();

        if (!$user) {
            return response()->json(['message' => 'No valid user found for assigning fingerprint'], 404);
        }

        $user->update(['fingerprint_id' => $request->fingerprint_id]);

        return response()->json([
            'message' => 'Fingerprint ID assigned. Now scan fingerprint on device.',
            'user'    => $user
        ]);
    }


    public function checkScanStatus(Request $request)
    {
        $request->validate([
            'serialnumber' => 'required|numeric',
            'device_uid'   => 'required|string',
        ]);

        $user = User::where('serialnumber', $request->serialnumber)
            ->where('device_uid', $request->device_uid)
            ->first();

        if (!$user) {
            return response()->json(['status' => 'user_not_found', 'message' => 'User not found'], 404);
        }

        if ($user->add_fingerid == 0) {
            return response()->json([
                'status'         => 'completed',
                'message'        => 'Fingerprint scan completed and saved',
                'fingerprint_id' => $user->fingerprint_id,
                'serialnumber'   => $user->serialnumber,
                'username'       => $user->username,
                'id'             => $user->id
            ]);
        }

        if ($user->add_fingerid == 1) {
            if ($user->fingerprint_id) {
                return response()->json([
                    'status'         => 'scanning',
                    'message'        => 'Fingerprint ID assigned but scan not completed yet',
                    'fingerprint_id' => $user->fingerprint_id
                ], 202);
            } else {
                return response()->json([
                    'status'  => 'id_pending',
                    'message' => 'User selected but fingerprint ID not assigned yet'
                ], 202);
            }
        }

        return response()->json([
            'status'  => 'not_selected',
            'message' => 'Fingerprint enrollment not started for this user'
        ], 200);
    }


    public function assignUserToFingerprint(Request $request)
    {
        $request->validate([
            'serialnumber'   => 'required|numeric',
            'device_uid'     => 'required|string',
            'fingerprint_id' => 'required|integer|min:1|max:127',
        ]);

        $user = User::where('serialnumber', $request->serialnumber)
            ->where('device_uid', $request->device_uid)
            ->first();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        User::where('device_uid', $request->device_uid)
            ->update([
                'fingerprint_select' => 0,
                'add_fingerid'       => 0,
            ]);

        $user->update([
            'fingerprint_select' => 1,
            'add_fingerid'       => 1,
            'fingerprint_id'     => $request->fingerprint_id
        ]);

        return response()->json([
            'message' => 'User selected and fingerprint ID assigned. Please scan fingerprint on device.',
            'user'    => $user
        ]);
    }


    public function deselectUserForFingerprint(Request $request)
    {
        $request->validate([
            'device_uid' => 'required|string',
        ]);

        User::where('device_uid', $request->device_uid)
            ->update(['fingerprint_select' => 0]);

        return response()->json(['message' => 'Fingerprint deselected']);
    }


    public function cancelEnrollment(Request $request)
    {
        $request->validate([
            'serialnumber' => 'required|numeric',
            'device_uid'   => 'required|string',
        ]);

        $user = User::where('serialnumber', $request->serialnumber)
            ->where('device_uid', $request->device_uid)
            ->first();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $user->update([
            'fingerprint_select' => 0,
            'add_fingerid'       => 2,
            'fingerprint_id'     => null
        ]);

        return response()->json([
            'message' => 'Fingerprint enrollment cancelled',
            'user'    => $user
        ]);
    }
}