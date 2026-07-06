<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;




class DepartmentController extends Controller
{
    public function listDepartments()
    {
        $authUser = Auth::user();

        if (!$authUser) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
    
        $query = DB::table('department')
            ->select('department.id', 'department.department_name', 'department.company_id', 'department.branch_id','department.device_uid');

        if ($authUser->user_types_id == 2) {
            // Company Admin
            $companyIds = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->pluck('company_id');

            if ($companyIds->isEmpty()) {
                return response()->json(['data' => [], 'total' => 0]);
            }

            $query->whereIn('department.company_id', $companyIds);

        } elseif ($authUser->user_types_id == 3) {
            // Branch Admin
            $branchIds = DB::table('branch_user')
                ->where('users_id', $authUser->id)
                ->pluck('branch_id');

            if ($branchIds->isEmpty()) {
                return response()->json(['data' => [], 'total' => 0]);
            }

            $query->whereIn('department.branch_id', $branchIds);
        }elseif ($authUser->user_types_id == 1) {
        // Super Admin: no filters, show all departments
        } elseif ($authUser->user_types_id == 4) {
            // Dept Admin → show ALL departments in their own branch(es),
            // not just the single department they personally administer.
            // (Fixed: previously this filtered down to only the admin's
            // own department_id, which made the Add Employee department
            // dropdown appear to be "missing" every other department in
            // the same branch.)
            $departmentIds = DB::table('department_users')
                ->where('users_id', $authUser->id)
                ->pluck('department_id');

            if ($departmentIds->isEmpty()) {
                return response()->json(['data' => [], 'total' => 0]);
            }

            $branchIds = DB::table('department')
                ->whereIn('id', $departmentIds)
                ->pluck('branch_id')
                ->filter()
                ->unique();

            if ($branchIds->isEmpty()) {
                return response()->json(['data' => [], 'total' => 0]);
            }

            $query->whereIn('department.branch_id', $branchIds);
        }else {
            return response()->json(['error' => 'Unauthorized or no departments available'], 403);
        }

        $departments = $query->orderBy('department.department_name', 'asc')->get();
        $total = $departments->count();

        return response()->json([
            'data' => $departments,
            'total' => $total
        ]);
    }

   

    public function createDepartmentWithAdmin(Request $request)
{
    $authUser = Auth::user();

    if ($authUser->user_types_id == 1) {
        // Super Admin
        $request->validate([
            'department_name' => 'required|string|max:255|unique:department,department_name',
            'branch_id'       => 'required|exists:branch,id',
            'device_uid'      => 'nullable|string|exists:devices,device_uid',
        ]);

        // Derive company_id from the branch
        $companyId = DB::table('branch')
            ->where('id', $request->branch_id)
            ->value('company_id');

        if (!$companyId) {
            return response()->json(['message' => 'Branch not found.'], 422);
        }
        $request->merge(['company_id' => $companyId]);

    } elseif ($authUser->user_types_id == 2) {
        // Company Admin
        $companyIds = DB::table('company_users')
            ->where('users_id', $authUser->id)
            ->pluck('company_id')
            ->toArray();

        if (empty($companyIds)) {
            return response()->json(['message' => 'No assigned companies found.'], 403);
        }

        $request->validate([
            'department_name' => 'required|string|max:255|unique:department,department_name',
            'branch_id'       => 'required|exists:branch,id',
            'device_uid'      => 'nullable|string|exists:devices,device_uid',
        ]);

        $request->merge(['company_id' => $companyIds[0]]);

    } elseif ($authUser->user_types_id == 3) {
        // Branch Admin
        $branchId = DB::table('branch_user')
            ->where('users_id', $authUser->id)
            ->value('branch_id');

        if (!$branchId) {
            return response()->json(['message' => 'Branch assignment not found.'], 403);
        }

        $request->validate([
            'department_name' => 'required|string|max:255|unique:department,department_name',
            'device_uid'      => 'nullable|string|exists:devices,device_uid',
        ]);

        $request->merge([
            'branch_id'  => $branchId,
            'company_id' => DB::table('branch')->where('id', $branchId)->value('company_id'),
        ]);

    } else {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    try {
        $department = Department::create([
            'department_name' => $request->department_name,
            'company_id'      => $request->company_id,
            'branch_id'       => $request->branch_id,
            'device_uid'      => $request->device_uid ?? null,
        ]);

        return response()->json([
            'message'    => 'Department created successfully.',
            'department' => $department,
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Failed to create department.',
            'error'   => $e->getMessage(),
        ], 500);
    }
}


    // public function createDepartmentWithAdmin(Request $request)
    // {
    //     $authUser = Auth::user();

    //     // Role-based access control
    //     if ($authUser->user_types_id == 1) {
    //         // Super Admin: can access any company/branch
    //         $request->validate([
    //             'department_name' => 'required|string|max:255|unique:department,department_name',
    //             'company_id' => 'required|exists:company,id',
    //             'branch_id' => 'nullable|exists:branch,id',
    //             'device_name' => 'required|string|exists:devices,device_name',
    //             'email' => 'required|email|unique:users,email',
    //             'password' => 'required|string|min:6',
    //         ]);
    //     } elseif ($authUser->user_types_id == 2) {
    //     // Company Admin: can only create departments in their assigned companies
    //         $companyIds = DB::table('company_users')
    //             ->where('users_id', $authUser->id)
    //             ->pluck('company_id')
    //             ->toArray();
    //             if (empty($companyIds)) {
    //                 return response()->json(['message' => 'No assigned companies found.'], 403);
    //             }

    //         $request->validate([
    //             'department_name' => 'required|string|max:255|unique:department,department_name',
    //             'branch_id' => 'nullable|exists:branch,id',
    //             'device_name' => 'required|string|exists:devices,device_name',
    //             'email' => 'required|email|unique:users,email',
    //             'password' => 'required|string|min:6',
    //         ]);
    //          $request->merge([
    //             'company_id' => $companyIds[0],
    //         ]);
    //     } elseif ($authUser->user_types_id == 3) {
    //     // Branch Admin: only for their own branch
    //         $branchId = DB::table('branch_user')
    //             ->where('users_id', $authUser->id)
    //             ->value('branch_id');

    //         if (!$branchId) {
    //             return response()->json(['message' => 'Branch assignment not found.'], 403);
    //         }

    //         $request->validate([
    //             'department_name' => 'required|string|max:255|unique:department,department_name',
    //             'device_name' => 'required|string|exists:devices,device_name',
    //             'email' => 'required|email|unique:users,email',
    //             'password' => 'required|string|min:6',
    //         ]);

    //         // Force company and branch assignment
    //         $request->merge([
    //             'branch_id' => $branchId,
    //             'company_id' => DB::table('branch')->where('id', $branchId)->value('company_id'),
    //         ]);
    //     } else {
    //         return response()->json(['message' => 'Unauthorized'], 403);
    //     }

    //     DB::beginTransaction();

    //     try {
    //         $device = DB::table('devices')->where('device_name', $request->device_name)->first();
    //         if (!$device) {
    //             return response()->json(['message' => 'Invalid device name provided.'], 422);
    //         }
    //         $deviceUid = $device->device_uid;

    //     // Create department
    //         $department = Department::create([
    //             'department_name' => $request->department_name,
    //             'company_id' => $request->company_id,
    //             'branch_id' => $request->branch_id,
    //             'device_uid' => $deviceUid,

    //         ]);
            
    //         // Create admin user for the department
    //         $admin = User::create([
    //             'username' => $request->department_name.'Admin',
    //             'email' => $request->email,
    //             'password' => Hash::make($request->password),
    //             'user_types_id' => 4, // Department Admin
    //             'status' => 1,
    //             'user_date' => now()->toDateString(),
    //             'department_id' => $department->id,  // add this

    //         ]);

    //         // Link user to department
    //         DB::table('department_users')->insert([
    //             'department_id' => $department->id,
    //             'users_id' => $admin->id, // use correct column name
    //             'created_at' => now(),
    //             'updated_at' => now(),
    //         ]);

    //         DB::commit();

    //         return response()->json([
    //             'message' => 'Department and admin user created successfully.',
    //             'department' => $department,
    //             'admin_user' => $admin,
    //         ], 201);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'message' => 'Failed to create department and user.',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }


    public function update(Request $request, $id)
    {
        $department = Department::findOrFail($id);
        $user = auth()->user();

        // Authorization
        if ($user->user_types_id === 1) {
        // Super Admin - allowed
        } elseif ($user->user_types_id === 2) {
        // Company Admin - check if department's company matches user's company
            $companyIds = DB::table('company_users')
                ->where('users_id', $user->id)
                ->pluck('company_id');

        if (!$companyIds->contains($department->company_id)) {
            return response()->json(['message' => 'Unauthorized - Company mismatch.'], 403);
        }

        } elseif ($user->user_types_id === 3) {
        // Branch Admin - check if department's branch matches user's branch
            $branchId = DB::table('branch_user')
            ->where('users_id', $user->id)
            ->value('branch_id');

            if ($department->branch_id !== $branchId) {
                return response()->json(['message' => 'Unauthorized - Branch mismatch.'], 403);
            }

        } else {
            return response()->json(['message' => 'Unauthorized - Unknown role.'], 403);
        }

    // Validation
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:department,name,' . $id,
            'company_id' => 'sometimes|exists:company,id',
            'branch_id' => 'sometimes|exists:branch,id',
        ]);

    // Update only provided fields
        foreach ($validated as $field => $value) {
            $department->$field = $value;
        }

        $department->save();

        return response()->json([
            'message' => 'Department updated successfully.',
            'department' => $department,
        ]);
    }



    

    public function destroy($id)
    {
        $authUser = Auth::user();

        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Find the department with branch
        $department = DB::table('department')
            ->where('id', $id)
            ->first();

        if (!$department) {
            return response()->json(['message' => 'Department not found'], 404);
        }

        // Fetch branch
        $branch = DB::table('branch')
            ->where('id', $department->branch_id)
            ->first();

        if (!$branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }

        // Authorization based on user type
        if ($authUser->user_types_id == 2) {
        // Company Admin can only delete from their company branches
            $companyIds = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->pluck('company_id');

            if (!$companyIds->contains($branch->company_id)) {
                return response()->json(['message' => 'Unauthorized to delete this department'], 403);
            }
        }

        if ($authUser->user_types_id == 3) {
        // Branch Admin can only delete departments under their branch
            $allowedBranchIds = DB::table('branch_user')
                ->where('users_id', $authUser->id)
                ->pluck('branch_id');

            if(!$allowedBranchIds->contains($branch->id)) {
                return response()->json(['message' => 'Unauthorized to delete this department'], 403);
            }
        }
        // Passed all checks, delete
        DB::table('department')->where('id', $id)->delete();

        return response()->json(['message' => 'Department deleted successfully']);
    }

}