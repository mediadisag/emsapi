<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class BranchController extends Controller
{
    public function index()
    {
        $authUser = auth()->user();

        if ($authUser->user_types_id == 1) {
            // Super Admin → all branches
            $branches = Branch::with('company')->get();

        } elseif ($authUser->user_types_id == 2) {
            // Company Admin → their company's branches only
            $companyId = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->value('company_id');

            if (!$companyId) {
                return response()->json(['message' => 'No company assigned to you'], 403);
            }

            $branches = Branch::with('company')->where('company_id', $companyId)->get();

        } elseif ($authUser->user_types_id == 3) {
            // Branch Admin → via branch_user pivot table
            $branchIds = DB::table('branch_user')
                ->where('users_id', $authUser->id)
                ->pluck('branch_id');

            if ($branchIds->isEmpty()) {
                // Fallback: try direct branch_id on users table
                $branchId = $authUser->branch_id;
                if (!$branchId) {
                    return response()->json([], 200);
                }
                $branchIds = collect([$branchId]);
            }

            $branches = Branch::with('company')->whereIn('id', $branchIds)->get();

        } elseif ($authUser->user_types_id == 4) {
            // Dept Admin → get branch via department_users → department → branch_id
            $departmentIds = DB::table('department_users')
                ->where('users_id', $authUser->id)
                ->pluck('department_id');

            if ($departmentIds->isEmpty()) {
                return response()->json([], 200);
            }

            $branchIds = DB::table('department')
                ->whereIn('id', $departmentIds)
                ->pluck('branch_id')
                ->filter()
                ->unique();

            if ($branchIds->isEmpty()) {
                return response()->json([], 200);
            }

            $branches = Branch::with('company')->whereIn('id', $branchIds)->get();

        } else {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($branches, 200);
    }

    public function show($id)
    {
        $authUser = auth()->user();
        $branch   = Branch::with('company')->find($id);

        if (!$branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }

        if ($authUser->user_types_id == 1) {
            return response()->json($branch, 200);
        }

        if ($authUser->user_types_id == 2) {
            $companyId = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->value('company_id');

            if ($branch->company_id == $companyId) {
                return response()->json($branch, 200);
            }

            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($authUser->user_types_id == 3) {
            $branchIds = DB::table('branch_user')
                ->where('users_id', $authUser->id)
                ->pluck('branch_id');

            if ($branchIds->contains($branch->id)) {
                return response()->json($branch, 200);
            }

            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($authUser->user_types_id == 4) {
            $departmentIds = DB::table('department_users')
                ->where('users_id', $authUser->id)
                ->pluck('department_id');

            $branchIds = DB::table('department')
                ->whereIn('id', $departmentIds)
                ->pluck('branch_id')
                ->filter()->unique();

            if ($branchIds->contains((int)$id)) {
                return response()->json($branch, 200);
            }

            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json(['message' => 'Unauthorized'], 403);
    }

    public function store(Request $request)
    {
        $authUser = Auth::user();

        if (!in_array($authUser->user_types_id, [1, 2])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'company_id'  => 'required|exists:company,id',
            'branch_name' => 'required|string|max:255',
            'number'      => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $companyId = $request->company_id;

        if ($authUser->user_types_id === 2) {
            $isCompanyUser = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->where('company_id', $companyId)
                ->exists();

            if (!$isCompanyUser) {
                return response()->json(['message' => 'You are not associated with this company'], 403);
            }
        }

        $branch = Branch::create([
            'company_id'  => $companyId,
            'branch_name' => $request->branch_name,
            'number'      => $request->number ?? null,
        ]);

        return response()->json([
            'message' => 'Branch created successfully',
            'branch'  => $branch,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $authUser = Auth::user();
        $branch   = Branch::find($id);

        if (!$branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }

        if ($authUser->user_types_id == 2) {
            $companyId = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->value('company_id');

            if ($branch->company_id != $companyId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        } elseif ($authUser->user_types_id != 1) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'company_id'  => 'sometimes|exists:company,id',
            'branch_name' => 'sometimes|required|string|max:255',
            'number'      => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $branch->update($request->only(['company_id', 'branch_name', 'number']));

        return response()->json(['message' => 'Branch updated', 'branch' => $branch], 200);
    }

    public function destroy($id)
    {
        $authUser = Auth::user();
        $branch   = Branch::find($id);

        if (!$branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }

        if ($authUser->user_types_id == 2) {
            $companyId = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->value('company_id');

            if ($branch->company_id != $companyId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        } elseif ($authUser->user_types_id != 1) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $branch->delete();
        return response()->json(['message' => 'Branch deleted'], 200);
    }

    public function getByCompany($company_id)
    {
        $authUser = Auth::user();

        if (!Company::find($company_id)) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        if ($authUser->user_types_id == 1) {
            $branches = Branch::where('company_id', $company_id)->get();
            return response()->json($branches, 200);
        }

        if ($authUser->user_types_id == 2) {
            $myCompanyId = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->value('company_id');

            if ($company_id != $myCompanyId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $branches = Branch::where('company_id', $company_id)->get();
            return response()->json($branches, 200);
        }

        return response()->json(['message' => 'Unauthorized'], 403);
    }
}