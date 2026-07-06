<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\Device;
use App\Models\BranchDevice;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceController extends Controller
{

    public function list()
    {
        $authUser = Auth::user();

        if (!$authUser) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = DB::table('devices')
            ->select([
                'devices.device_name',
                'devices.device_dep',
                'devices.branch_code',
                'devices.location',
                'devices.state',
                'devices.device_uid',
                'devices.device_mode',
                'devices.device_date'
            ]);

        if ($authUser->user_types_id == 2) {
        // Company Admin
            $companyIds = DB::table('company_users')
                ->where('users_id', $authUser->id)
                ->pluck('company_id');

            $deviceIdsViaBranches = DB::table('branch_devices')
                ->join('branch', 'branch_devices.branch_id', '=', 'branch.id')
                ->whereIn('branch.company_id', $companyIds)
                ->pluck('branch_devices.device_id');

            $deviceIdsDirect = DB::table('devices')
                ->whereIn('company_id', $companyIds)
                ->pluck('id'); 
             
             $allDeviceIds = $deviceIdsViaBranches->merge($deviceIdsDirect)->unique();


            if ($companyIds->isEmpty()) {
                return response()->json(['message' => 'No companies assigned.']);
            }   
            //get devices via branch
            $branchIds = DB::table('branch')
                ->whereIn('company_id', $companyIds)
                ->pluck('id');

            $deviceIds = DB::table('branch_devices')
                ->whereIn('branch_id', $branchIds)
                ->pluck('device_id');

            //get devices directly assigned to company admin    
            $deviceIdsViaCompany= DB::table('devices')
                ->whereIn('company_id', $companyIds)
                ->pluck('id');

            $allDeviceIds=$deviceIdsViaBranches->merge($deviceIdsViaCompany)->unique();
            if ($allDeviceIds->isNotEmpty()) {
                $query->whereIn('devices.id', $allDeviceIds);
            } else {
                return response()->json([]);
            }
        }elseif ($authUser->user_types_id == 3) {
        // Branch Admin
            $branchIds = DB::table('branch_user')
                ->where('user_id', $authUser->id)
                ->pluck('branch_id');

            $deviceIds = DB::table('branch_devices')
                ->whereIn('branch_id', $branchIds)
                ->pluck('device_id');

            $query->whereIn('devices.id', $deviceIds);
        }
    

        $devices = $query->get();
        $formatted = $devices->map(function ($device) {
            return [
                'Device Name'       => $device->device_name,
                'Device Department' => $device->device_dep,
                'Branch Code'       => $device->branch_code,
                'Location'          => $device->location,
                'State'             => $device->state,
                'Device UID'        => $device->device_uid,
                'Device Date'       => $device->device_date ?? now()->toDateString(),
                'Device Mode'       => $device->device_mode,
            ];
        });
        return response()->json($formatted);
    }
    
    

    
    public function store(Request $request)
    {
        $authUser = Auth::user();

        if (!$authUser || !in_array($authUser->user_types_id, [1, 2])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'device_name' => 'required|string|max:255',
            'device_dep' => 'required|string|max:255',
            'device_uid' => 'nullable|string|max:255',
            'branch_code' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'device_mode' => 'nullable|in:0,1',
            'device_date' => 'nullable|date',
            'branch_id' => 'required|exists:branch,id', // new requirement
        ]);

        $validated['device_date'] = now()->toDateString();

        // Company Admin check
        if ($authUser->user_types_id == 2) {
            $allowedBranches = DB::table('branch')
                ->whereIn('company_id', function ($q) use ($authUser) {
                     $q->select('company_id')->from('company_users')->where('users_id', $authUser->id);
                })
                ->pluck('id');

            if (!$allowedBranches->contains($validated['branch_id'])) {
                return response()->json(['message' => 'Access denied to this branch'], 403);
            }
        }
    
        $device = Device::create($validated);

    // Save to branch_devices mapping
        BranchDevice::create([
            'branch_id' => $validated['branch_id'],
            'device_id' => $device->id,
            'company_id' => $authUser->user_types_id == 2
            ? DB::table('company_users')->where('users_id', $authUser->id)->value('company_id')
            : null,
        ]);

        return response()->json([
            'message' => 'Device created successfully',
            'device' => $device
        ], 201);
    }
    public function destroy(Request $request)
    {
        $authUser = Auth::user();
        $request->validate(['device_name' => 'required|string']);
        $device = Device::where('device_name', $request->device_name)->first();

        if (!$device) {
            return response()->json(['error' => 'Device not found'], 404);
        }

        if ($authUser->user_types_id == 2) {
        // Check if device belongs to a branch under their company
            $allowedDeviceIds = DB::table('branch_devices')
            ->whereIn('branch_id', function ($q) use ($authUser) {
                $q->select('id')->from('branch')
                    ->whereIn('company_id', function ($q2) use ($authUser) {
                        $q2->select('company_id')
                           ->from('company_users')
                           ->where('users_id', $authUser->id);
                    });
            })
            ->pluck('device_id');

            if (!$allowedDeviceIds->contains($device->id)) {
                return response()->json(['message' => 'Access denied to delete this device'], 403);
            }
        
        } elseif ($authUser->user_types_id != 1) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $device->delete();

        return response()->json([
            'message' => 'Device deleted successfully'
        ]);
    }

    public function regenerateUID(Request $request)
    {
        $request->validate([
            'id' => 'required|integer'
        ]);

        $device = Device::find($request->id);
        if (!$device) {
            return response()->json([
                'error' => 'Device not found'
            ], 404);
        }

        $device->device_uid = Str::random(16);
        $device->save();

        return response()->json([
            'message' => 'Device UID regenerated',
            'device_uid' => $device->device_uid
        ]);
    }

    
    public function updateMode(Request $request)
    {
        $request->validate([
            'device_uid' => 'required|string|max:255',
            'device_mode' => 'required|in:0,1'
        ]);

        $authUser = Auth::user();

        if (!$authUser) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Find the device by UID
        $device = Device::where('device_uid', $request->device_uid)->first();

        if (!$device) {
            return response()->json(['error' => 'Device not found'], 404);
        }

        // Access Control
        if ($authUser->user_types_id == 1) {
        // Super Admin: full access
        } elseif ($authUser->user_types_id == 2) {
        // Company Admin: devices under their companies
        $allowedUIDs = DB::table('branch_devices')
            ->join('devices', 'branch_devices.device_id', '=', 'devices.id')
            ->whereIn('branch_devices.branch_id', function ($q) use ($authUser) {
                $q->select('branch.id')
                    ->from('branch')
                    ->join('company_users', 'branch.company_id', '=', 'company_users.company_id')
                    ->where('company_users.users_id', $authUser->id);
            })
            ->pluck('devices.device_uid');

            if (!$allowedUIDs->contains($request->device_uid)) {
                return response()->json(['error' => 'Access denied to modify this device'], 403);
            }
        } elseif ($authUser->user_types_id == 3) {
            // Branch Admin: devices under assigned branches
            $allowedUIDs = DB::table('branch_devices') 
                ->join('devices', 'branch_devices.device_id', '=', 'devices.id')
                ->whereIn('branch_devices.branch_id', function ($q) use ($authUser) {
                    $q->select('branch_id')->from('branch_user')->where('user_id', $authUser->id);
                })
                ->pluck('devices.device_uid');

            if (!$allowedUIDs->contains($request->device_uid)) {
                return response()->json(['error' => 'Access denied to modify this device'], 403);
            }
        } else {
            return response()->json(['error' => 'Invalid role'], 403);
        }

        // Update mode
        $device->device_mode = $request->device_mode;
        $device->save();

        return response()->json([
            'message' => 'Device mode updated',
            'device' => $device,
            'device_uid' => $device->device_uid,
            'device_mode' => $device->device_mode
        ]);
    }



    public function updateDetails(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|integer',
            'device_name' => 'required|string|max:255',
            'device_dep' => 'required|string|max:255',
            'branch_code' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'state' => 'required|string|max:255',
        ]);

        $authUser = Auth::user();

        if (!$authUser || $authUser->user_types_id != 1) {  
            return response()->json(['error' => 'Unauthorized. Only Super Admins can update devices.'], 403);
        }

        $device = Device::find($validated['id']);

        if (!$device) {
            return response()->json(['error' => 'Device not found.'], 404);
        }

        $device->update($validated);

        return response()->json([
            'message' => 'Device updated successfully',
            'device' => $device
        ]);
    }
}