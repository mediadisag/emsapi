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

        // Ensure only Super Admin (user_types_id = 1) and Company Admin (user_types_id = 2) can view companies
        if (!$authUser || !in_array($authUser->user_types_id, [1, 2])) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }
        if ($authUser->user_types_id == 1) {
            $companies = Company::withCount('branch')->get();
        } else {
        // Company Admin – return only their assigned company
            $companyId = DB::table('company_users')
            ->where('users_id', $authUser->id)
            ->value('company_id');

            if (!$companyId) {
                return response()->json([
                    'message' => 'No company assigned.'
                ], 404);
            }
            $companies = Company::withCount('branch')->where('id', $companyId)->get();
        }
    
        return response()->json($companies, 200);
    }

    public function store(Request $request)
    {
        $authUser = auth()->user();

        //only Super Admin (user_types_id = 1) can add company
        if ($authUser->user_types_id != 1) {
            return response()->json([
                'message' => 'Unauthorized. Only super admin can add companies.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name'    => 'required|string|max:255',
            'email'   => 'nullable|email',
            'address' => 'nullable|string',
            'phone'   => 'nullable|string|max:20',
            'website' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $company = Company::create($request->only([
            'name',
            'email',
            'address',
            'phone',
            'website'
        ]));

        return response()->json([
            'message' => 'Company created',
            'company' => $company
        ], 201);
    }

    public function show($id)
{

    // Ensure only Super Admin (user_types_id = 1) and Company Admin (user_types_id = 2) can view company details
    $authUser = auth()->user();

    if (!$authUser || !in_array($authUser->user_types_id, [1, 2])) {
        return response()->json([
            'message' => 'Unauthorized'
        ], 403);
    }

    if ($authUser->user_types_id == 2) {
        // Check if this company belongs to the logged in company admin
        $associated = DB::table('company_users')
            ->where('company_id', $id)
            ->where('users_id', $authUser->id)
            ->exists();

        if (!$associated) {
            return response()->json([
                'message' => 'Unauthorized. This company is not assigned to you.'
            ], 403);
        }
    }
      $company = Company::with('branch')->find($id);

    if (!$company) {
        return response()->json([
            'message' => 'Company not found'
        ], 404);
    }

    return response()->json($company, 200);
}


    public function update(Request $request, $id)
    {
        // Super Admin and company admin can update company details
        $authUser = auth()->user();
        if (!$authUser || !in_array($authUser->user_types_id, [1, 2])) {
            return response()->json([
                'message' => 'Unauthorized. Only Super Admin and Company Admin can update companies.'
            ], 403);
        }

    $company = Company::find($id);

    if (!$company) {
        return response()->json([
            'message' => 'Company not found'
        ], 404);
    }

    // If Company Admin, ensure they are assigned to this company
    if ($authUser->user_types_id == 2) {
        $associated = DB::table('company_users')
            ->where('company_id', $id)
            ->where('users_id', $authUser->id)
            ->exists();

        if (!$associated) {
            return response()->json([
                'message' => 'Unauthorized. You are not assigned to this company.'
            ], 403);
        }
    }


        $validator = Validator::make($request->all(), [
            'name'    => 'sometimes|required|string|max:255',
            'email'   => 'nullable|email',
            'address' => 'nullable|string',
            'phone'   => 'nullable|string|max:20',
            'website' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $company->update($request->only([
            'name',
            'email',
            'address',
            'phone',
            'website'
        ]));

        return response()->json([
            'message' => 'Company updated',
            'company' => $company
        ], 200);

    }

    
    // DELETE /api/company/{id}
    public function destroy($id)
    {
         $authUser = auth()->user();

    if (!$authUser || $authUser->user_types_id != 1) {
        return response()->json([
            'message' => 'Unauthorized. Only Super Admin can delete companies.'
        ], 403);
    }

        $company = Company::find($id);

        if (!$company) {
            return response()->json([
                'message' => 'Company not found'
            ], 404);
        }

        $company->delete();

        return response()->json([
            'message' => 'Company deleted'
        ], 200);
    }


public function createCompanyWithAdmin(Request $request)
{

    $authUser = Auth::user();

    //  Check if user is a Super Admin (user_types_id == 1)
    if (!$authUser || $authUser->user_types_id !== 1) {
        return response()->json(['message' => 'Unauthorized. Only Super Admins can create companies.'], 403);
    }
    $validated = $request->validate([
        // Company fields
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:company,email',
        'address' => 'nullable|string',
        'phone' => 'nullable|string',
        'website' => 'nullable|string',

        // Admin user fields
        'username'     => 'required|string|max:255|unique:users,username',
        'email_user'   => 'required|email|unique:users,email',
        'password'     => 'required|string|min:6',
        'gender'       => 'required|in:Male,Female',
        'serialnumber' => 'required|string|unique:users,serialnumber',
        'device_uid'   => 'required|exists:devices,device_uid',
        'device_dep'   => 'required|string',
        
      ]);
      DB::beginTransaction();

    try {
        // create company
        $company=Company::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'address' => $validated['address'],
            'phone' => $validated['phone'],
            'website' => $validated['website'] ?? null,
        ]);

        // Create company admin user
        $admin = User::create([
            'username'     => $validated['username'],
            'email'        => $validated['email_user'],
            'password'     => Hash::make($validated['password']),
            'gender'       => $validated['gender'],
            'serialnumber' => $validated['serialnumber'],
            'device_uid'   => $validated['device_uid'],
            'device_dep'   => $validated['device_dep'],
            'user_types_id'=> 2, // Company Admin
            'user_date'    => now(),
        ]);
            
        DB::table('company_users')->insert([
            'company_id' => $company->id,
            'users_id'    => $admin->id,
        ]);

        DB::commit();

        return response()->json([
            'message' => 'Company and admin created successfully.',
            'company' => $company,
            'admin'   => $admin
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'message' => 'Failed to create company and admin.',
            'error'   => $e->getMessage()
        ], 500);
    }
}
public function viewCompanyProfile($companyId)
{
    $authUser = auth()->user();

    // Super Admin can view any company
    if ($authUser->user_types_id == 1) {
        $company = Company::with('users')->find($companyId);
    }
    // Company Admin can view only their assigned company
    elseif ($authUser->user_types_id == 2) {
        $company = Company::whereHas('users', function ($q) use ($authUser) {
            $q->where('users.id', $authUser->id);
        })->with('users')->find($companyId);
    } else {
        return response()->json(['error' => 'Unauthorized'], 403);
    }

    if (!$company) {
        return response()->json(['error' => 'Company not found or not accessible'], 404);
    }

    return response()->json([
        'id' => $company->id,
        'name' => $company->name,
        'admins' => $company->users->map(function ($user) {
            return [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
            ];
        }),
    ]);
}


}