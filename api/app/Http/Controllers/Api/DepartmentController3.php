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
        ->select('department.id', 'department.department_name', 'department.company_id', 'department.branch_id');

    if ($authUser->user_types_id == 2) {
        // Company Admin
        $companyIds = DB::table('company_users')
            ->where('users_id', $authUser->id)
            ->pluck('company_id');

        if ($companyIds->isEmpty()) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query->whereIn('company_id', $companyIds);

    } elseif ($authUser->user_types_id == 3) {
        // Branch Admin
        $branchIds = DB::table('branch_user')
            ->where('user_id', $authUser->id)
            ->pluck('branch_id');

        if ($branchIds->isEmpty()) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query->whereIn('branch_id', $branchIds);
    }elseif ($authUser->user_types_id == 1) {
    // Super Admin: no filters, show all departments
     } elseif ($authUser->user_types_id == 4) {
    $departmentIds = DB::table('department_users')
        ->where('users_id', $authUser->id)
        ->pluck('department_id');

    if ($departmentIds->isEmpty()) {
        return response()->json(['data' => [], 'total' => 0]);
    }

    $query->whereIn('id', $departmentIds);
}
else {
    // Handle unauthorized user types (optional)
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

    // Role-based access control
    if ($authUser->user_types_id == 1) {
        // Super Admin: can access any company/branch
        $request->validate([
            'department_name' => 'required|string|max:255|unique:department,name',
            'company_id' => 'required|exists:company,id',
            'branch_id' => 'nullable|exists:branch,id',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
        ]);
    } elseif ($authUser->user_types_id == 2) {
        // Company Admin: can only create departments in their assigned companies
        $companyIds = DB::table('company_users')
            ->where('users_id', $authUser->id)
            ->pluck('company_id')
            ->toArray();

        $request->validate([
            'department_name' => 'required|string|max:255|unique:department,name',
            'company_id' => ['required', Rule::in($companyIds)],
            'branch_id' => 'nullable|exists:branch,id',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
        ]);
    } elseif ($authUser->user_types_id == 3) {
        // Branch Admin: only for their own branch
        $branchId = DB::table('branch_user')
            ->where('user_id', $authUser->id)
            ->value('branch_id');

        if (!$branchId) {
            return response()->json(['message' => 'Branch assignment not found.'], 403);
        }

        $request->validate([
            'department_name' => 'required|string|max:255|unique:department,name',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
        ]);

        // Force company and branch assignment
        $request->merge([
            'branch_id' => $branchId,
            'company_id' => DB::table('branch')->where('id', $branchId)->value('company_id'),
        ]);
    } else {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    DB::beginTransaction();

    try {
        // Create department
        $department = Department::create([
            'name' => $request->department_name,
            'company_id' => $request->company_id,
            'branch_id' => $request->branch_id,
        ]);

        // Create admin user for the department
        $admin = User::create([
            'username' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'user_types_id' => 4, // Department Admin
            'status' => 1,
            'user_date' => now()->toDateString(),
        ]);

        // Link user to department
        DB::table('department_users')->insert([
            'department_id' => $department->id,
            'users_id' => $admin->id, // use correct column name
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::commit();

        return response()->json([
            'message' => 'Department and admin user created successfully.',
            'department' => $department,
            'admin_user' => $admin,
        ], 201);
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'message' => 'Failed to create department and user.',
            'error' => $e->getMessage(),
        ], 500);
    }
}
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
            ->where('user_id', $user->id)
            ->pluck('company_id');

        if (!$companyIds->contains($department->company_id)) {
            return response()->json(['message' => 'Unauthorized - Company mismatch.'], 403);
        }

    } elseif ($user->user_types_id === 3) {
        // Branch Admin - check if department's branch matches user's branch
        $branchId = DB::table('branch_user')
            ->where('user_id', $user->id)
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



    public function store(Request $request)
    {
        $authUser = Auth::user();

        $request->validate([
            'name' => 'required|string|unique:department,name',
        ]);

        // Super Admin: company_id required, branch_id optional
        if ($authUser->user_types_id == 1) {
            $request->validate([
                'company_id' => 'required|exists:company,id',
                'branch_id' => 'nullable|exists:branch,id',
            ]);

            $department = Department::create([
                'name' => $request->name,
                'company_id' => $request->company_id,
                'branch_id' => $request->branch_id,
            ]);

            return response()->json(['message' => 'Department created.', 'department' => $department], 201);
        }

        // Company Admin: auto-fetch company ID
        if ($authUser->user_types_id == 2) {
            $companyId = DB::table('company_users')->where('users_id', $authUser->id)->value('company_id');

            if (!$companyId) {
                return response()->json(['error' => 'No company assigned.'], 403);
            }

            // If branches exist, expect frontend to select one
            $branch = DB::table('branch')->where('company_id', $companyId)->first();

            if ($branch) {
                return response()->json(['error' => 'Branch exists. Please select one before adding department.'], 422);
            }

            // Else, auto-create default branch
            $branchId = DB::table('branch')->insertGetId([
                'name' => 'Default Branch',
                'company_id' => $companyId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $department = Department::create([
                'name' => $request->name,
                'company_id' => $companyId,
                'branch_id' => $branchId,
            ]);

            return response()->json(['message' => 'Department created with default branch.', 'department' => $department], 201);
        }

        // Branch Admin: auto-fetch branch and company ID
        if ($authUser->user_types_id == 3) {
            $branchId = DB::table('branch_user')->where('user_id', $authUser->id)->value('branch_id');

            if (!$branchId) {
                return response()->json(['error' => 'No branch assigned to branch admin'], 403);
            }

            $companyId = DB::table('branch')->where('id', $branchId)->value('company_id');

            $department = Department::create([
                'name' => $request->name,
                'company_id' => $companyId,
                'branch_id' => $branchId,
            ]);

            return response()->json(['message' => 'Department created.', 'department' => $department], 201);
        }

        return response()->json(['error' => 'Unauthorized user type'], 403);
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
            ->where('user_id', $authUser->id)
            ->pluck('branch_id');

        if (!$allowedBranchIds->contains($branch->id)) {
            return response()->json(['message' => 'Unauthorized to delete this department'], 403);
        }
    }

    // Passed all checks, delete
    DB::table('department')->where('id', $id)->delete();

    return response()->json(['message' => 'Department deleted successfully']);
}

}
