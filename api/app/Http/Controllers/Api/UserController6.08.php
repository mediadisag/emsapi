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
   //list all users
    public function index()
    {
        $users = DB::table('users')
            ->select([
                'id',
                'username as name',
                'serialnumber', 
                'gender',
                'fingerprint_id',
                'user_date as date',
                'device_dep as device',
                'device_uid'
            ])
            ->get()
        ->map(function ($user) {
            $user->fingerprint_status = $user->fingerprint_id ? 'Added' : 'Not Added';
            return $user;
        });



        return response()->json([
     
            'data' => $users

        ]);

    }
    public function listUsersByDeviceAccess()
    {
    $authUser = Auth::user();

    if (!$authUser) {
        return response()->json(['message' => 'Unauthorized.'], 403);
    }

    $query = DB::table('users')
        ->join('devices', 'users.device_uid', '=', 'devices.device_uid')
        ->select([
            'users.id',
            'users.username as name',
            'users.serialnumber',
            'users.gender',
            'users.fingerprint_id',
            'users.user_date as date',
            'users.device_uid',
            'users.device_dep',
            'devices.device_name as device_name',
        ])
        ->orderBy('users.id', 'desc');

    if ($authUser->user_types_id == 1) {
        // Super Admin – no filters
    } elseif ($authUser->user_types_id == 2) {
        $companyIds = DB::table('company_users')
            ->where('users_id', $authUser->id)
            ->pluck('company_id');

        if ($companyIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $branchIds = DB::table('branch')
            ->whereIn('company_id', $companyIds)
            ->pluck('id');

        if ($branchIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $deviceUIDs = DB::table('branch_devices')
            ->join('devices', 'branch_devices.device_id', '=', 'devices.id')
            ->whereIn('branch_devices.branch_id', $branchIds)
            ->pluck('devices.device_uid');

        if ($deviceUIDs->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $query->whereIn('users.device_uid', $deviceUIDs);

    } elseif ($authUser->user_types_id == 3) {
        $branchIds = DB::table('branch_user')
            ->where('user_id', $authUser->id)
            ->pluck('branch_id');

        if ($branchIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $deviceUIDs = DB::table('branch_devices')
            ->join('devices', 'branch_devices.device_id', '=', 'devices.id')
            ->whereIn('branch_devices.branch_id', $branchIds)
            ->pluck('devices.device_uid');

        if ($deviceUIDs->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $query->whereIn('users.device_uid', $deviceUIDs);

    } else {
        return response()->json(['message' => 'Invalid user role.'], 403);
    }

    $users = $query->get()->map(function ($user) {
        $user->fingerprint_status = $user->fingerprint_id ? 'Added' : 'Not Added';
        return $user;
    });

    return response()->json(['data' => $users]);
}


    public function storeCompanyAdmin(Request $request) 
    {
    //Ensure only Super Admin (user_types_id = 1) can add company admin
        $authUser = Auth::user();

        if (!$authUser || $authUser->user_types_id != 1) {
            return response()->json([
            'message' => 'Unauthorized. Only super admin can add company admins.'
            ], 403);
        }

        $request->validate([
            'username'       => 'required|string|max:255',
            'email'          => 'required|email|unique:users,email',
            'password'       => 'required|string|min:6',
            'serialnumber'   => 'required|string|unique:users,serialnumber',
            'gender'         => 'required|in:Male,Female',
            'fingerprint_id' => 'nullable|integer|unique:users,fingerprint_id',
            'device_uid'     => 'nullable|string',
            'device_dep'     => 'nullable|string',
        ]);

        $admin = User::create([
            'username'        => $request->username,
            'email'           => $request->email,
            'password'        => Hash::make($request->password),
            'serialnumber'    => $request->serialnumber,
            'gender'          => $request->gender,
            'fingerprint_id'  => $request->fingerprint_id,
            'device_uid'      => $request->device_uid,
            'device_dep'      => $request->device_dep,
            'user_date'       => now(),
            'user_types_id'   => 2, // 2 = Company Admin
        ]);

        return response()->json([
            'message' => 'Company admin created successfully',
            'admin'   => $admin
        ], 201);
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
                'id',
                'username',
                'email',
                'serialnumber',
                'fingerprint_id',
                'device_uid',
                'device_dep',
                'user_types_id',
                
            ])
            ->where('user_types_id', 2)
            ->get();

        return response()->json([
            'data' => $companyAdmins
        ]);
    }
    
    public function branchAdmins()
    {
        $authUser = Auth::user();
        //only Super Admins (user_types_id = 1) and Company Admins (user_types_id = 2) can view branch admins
        if (!$authUser || !in_array($authUser->user_types_id, [1, 2])) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $branchAdmins = DB::table('users')
            ->select([
                'id',
                'username',
                'email',
                'user_types_id',
            ])
            ->where('user_types_id', 3)
            ->get();

             
        return response()->json([
            'data' => $branchAdmins
        ]);
    }

    public function storeBranchAdmin(Request $request)
    {
    // Allow only Super Admins to add Branch Admins
        $authUser = Auth::user();

        if (!$authUser || $authUser->user_types_id != 1) {
            return response()->json([
                'message' => 'Unauthorized. Only Super Admin can create Branch Admins.'
            ], 403);
        }
    

         $validated = $request->validate([
            'username'       => 'required|string|max:255',
            'email'          => 'required|email|unique:users,email',
            'password'       => 'required|string|min:6',
            'serialnumber'   => 'required|string|unique:users,serialnumber',
            'gender'         => 'required|in:Male,Female',
            'fingerprint_id' => 'required|integer|unique:users,fingerprint_id',
            'device_uid'     => 'nullable|string',
            'device_dep'     => 'nullable|string',
        ]);
         $isAssociated = DB::table('company_user')
            ->where('users_id', $authUser->id)
            ->where('company_id', $validated['company_id'])
            ->exists();

        if (!$isAssociated) {
            return response()->json([
                'message' => 'Unauthorized. You are not associated with this company.'
            ], 403);
        }

        $branch = Branch::findOrFail($validated['branch_id']);
        if ($branch->company_id !== $validated['company_id']) {
            return response()->json([
                'message' => 'Unauthorized. Branch does not belong to the specified company.'
            ], 403);
        }
    //  Create new Branch Admin (user_types_id = 3)
        $branchAdmin = User::create([
            'username'        => $validated['username'],
            'email'           => $validated['email'],
            'password'        => Hash::make($validated['password']),
            'serialnumber'    => $validated['serialnumber'],
            'gender'          => $validated['gender'],
            'fingerprint_id'  => $validated['fingerprint_id'],
            'device_uid'      => $validated['device_uid'] ?? null,
            'device_dep'      => $validated['device_dep'] ?? null,
            'user_date'       => now(),
            'user_types_id'   => 3, // Branch Admin
        ]);
        
        return response()->json([
            'message' => 'Branch admin created successfully',
            'branch_admin' => $branchAdmin
        ], 201);
    }

}