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

        // FIX: 'allowed_leaves' was missing from this select, so every
        // department returned by this endpoint always looked like it had
        // no allowed_leaves value — which made the Edit Department page
        // fall back to the default of 20 for every department, instead of
        // showing/saving that department's real, previously-set value.
        $query = DB::table('department')
            ->select(
                'department.id',
                'department.department_name',
                'department.company_id',
                'department.branch_id',
                'department.device_uid',
                'department.allowed_leaves'
            );

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
            // Dept Admin → show ONLY the department(s) they personally
            // administer, not every department in their branch.
            // (Reverted a previous change that broadened this to the whole
            // branch — that made Department Admins see other departments'
            // data, e.g. an admin scoped to KITEZ seeing DISAG too.)
            $departmentIds = DB::table('department_users')
                ->where('users_id', $authUser->id)
                ->pluck('department_id');

            if ($departmentIds->isEmpty()) {
                return response()->json(['data' => [], 'total' => 0]);
            }

            $query->whereIn('department.id', $departmentIds);
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
            'allowed_leaves' => 'nullable|integer|min:0',
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
            'allowed_leaves'  => $request->allowed_leaves ?? null,
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

        // FIX: validation keys now match what the frontend actually sends.
        // Previously this validated a 'name' field (and never validated
        // 'device_uid' at all), but the frontend sends 'department_name'
        // and 'device_uid' — so those two never made it into $validated
        // and were silently skipped on every update, even though the API
        // returned a 200 success response.
        $validated = $request->validate([
            'department_name' => 'sometimes|string|max:255|unique:department,department_name,' . $id,
            'company_id'      => 'sometimes|exists:company,id',
            'branch_id'       => 'sometimes|exists:branch,id',
            'device_uid'      => 'nullable|sometimes|string|exists:devices,device_uid',
            'allowed_leaves'  => 'sometimes|integer|min:0',
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