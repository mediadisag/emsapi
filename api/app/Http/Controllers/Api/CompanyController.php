<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;


class CompanyController extends Controller
{
    public function index()
    {
        $authUser = auth()->user();

        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($authUser->user_types_id == 1) {
            // Super Admin: all companies
            $companies = Company::withCount('branch')->get();

        } elseif ($authUser->user_types_id == 2) {
            // Company Admin
            $companyId = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->value('company_id');

            if (!$companyId) {
                return response()->json(['message' => 'No company assigned.'], 404);
            }

            $companies = Company::withCount('branch')->where('id', $companyId)->get();

        } elseif ($authUser->user_types_id == 3) {
            // Branch Admin: company of their assigned branch — FIXED: branch_users, users_id
            $branchIds = DB::table('branch_users')
                ->where('users_id', $authUser->id)
                ->pluck('branch_id');

            if ($branchIds->isEmpty()) {
                return response()->json(['message' => 'No branches assigned to you.'], 404);
            }

            $companyIds = DB::table('branch')
                ->whereIn('id', $branchIds)
                ->pluck('company_id')
                ->filter()->unique();

            $companies = Company::withCount('branch')->whereIn('id', $companyIds)->get();

        } elseif ($authUser->user_types_id == 4) {
            // Department Admin: company of their assigned department
            $departmentIds = DB::table('department_users')
                ->where('users_id', $authUser->id)
                ->pluck('department_id');

            $companyIds = DB::table('department')
                ->whereIn('id', $departmentIds)
                ->pluck('company_id')
                ->filter()->unique();

            if ($companyIds->isEmpty()) {
                return response()->json(['message' => 'No company found for your department.'], 404);
            }

            $companies = Company::withCount('branch')->whereIn('id', $companyIds)->get();

        } else {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($companies, 200);
    }


    public function show($id)
    {
        $authUser = auth()->user();

        if (!$authUser || !in_array($authUser->user_types_id, [1, 2, 3, 4])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($authUser->user_types_id == 2) {
            $associated = DB::table('company_users')
                ->where('company_id', $id)
                ->where('users_id', $authUser->id)
                ->exists();

            if (!$associated) {
                return response()->json(['message' => 'Unauthorized. This company is not assigned to you.'], 403);
            }
        }

        if ($authUser->user_types_id == 3) {
            // FIXED: branch_users, users_id
            $branchIds = DB::table('branch_users')
                ->where('users_id', $authUser->id)
                ->pluck('branch_id');

            $companyIds = DB::table('branch')
                ->whereIn('id', $branchIds)
                ->pluck('company_id');

            if (!$companyIds->contains((int)$id)) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }
        }

        if ($authUser->user_types_id == 4) {
            $departmentIds = DB::table('department_users')
                ->where('users_id', $authUser->id)
                ->pluck('department_id');

            $companyIds = DB::table('department')
                ->whereIn('id', $departmentIds)
                ->pluck('company_id');

            if (!$companyIds->contains((int)$id)) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }
        }

        $company = Company::with('branch')->find($id);

        if (!$company) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        return response()->json($company, 200);
    }


    public function createCompanyWithAdmin(Request $request)
    {
        $authUser = Auth::user();

        if (!$authUser || $authUser->user_types_id !== 1) {
            return response()->json(['message' => 'Unauthorized. Only Super Admins can create companies.'], 403);
        }

        $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|unique:company,email',
            'address' => 'nullable|string',
            'phone'   => 'nullable|string',
            'website' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $company = Company::create([
                'name'    => $request->name,
                'email'   => $request->email,
                'address' => $request->address ?? null,
                'phone'   => $request->phone   ?? null,
                'website' => $request->website ?? null,
            ]);

            DB::table('branch')->insert([
                'branch_name' => $company->name . ' Main Branch',
                'company_id'  => $company->id,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Company created successfully.',
                'company' => $company,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create company.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    public function update(Request $request, $id)
    {
        $authUser = auth()->user();

        if (!$authUser || !in_array($authUser->user_types_id, [1, 2])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($authUser->user_types_id == 1) {
            $company = Company::find($id);
        } else {
            $company = Company::whereHas('users', function ($query) use ($authUser) {
                $query->where('users.id', $authUser->id);
            })->find($id);
        }

        if (!$company) {
            return response()->json(['message' => 'Company not found or not accessible'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'    => 'sometimes|required|string|max:255',
            'email'   => 'nullable|email',
            'address' => 'nullable|string|max:255',
            'phone'   => 'nullable|string|max:20',
            'website' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $company->update($request->only(['name', 'email', 'address', 'phone', 'website']));

        return response()->json(['message' => 'Company updated successfully', 'company' => $company], 200);
    }


    public function destroy($id)
    {
        $authUser = auth()->user();

        if ($authUser->user_types_id != 1) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $company = Company::find($id);

        if (!$company) {
            return response()->json(['error' => 'Company not found'], 404);
        }

        $company->users()->detach();
        $company->delete();

        return response()->json(['message' => 'Company deleted successfully']);
    }
}