<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Models\Device;


class ManageUserController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'serialnumber' => 'required|string|unique:users,serialnumber',
            'gender' => 'required|in:Male,Female',
            'device_name' => 'required|string|exists:devices,device_name',
        ]);

        // Get the device details
        $device = Device::where('device_name', $request->device_name)->firstOrFail();
        $userData = [
            'username' => $request->username,
            'serialnumber' => $request->serialnumber,
            'gender' => $request->gender,
            'user_date' => now(),
            'device_name' => $device->device_name,
            'device_dep' => $device->device_dep,
            'device_uid' => $device->device_uid, // Store device UID from devices table
            'add_fingerid' => 2, // Set default to 2 (no fingerprint)
        ];

        $user=User::create($userData);
        
        return response()->json([
            'message' => 'User added successfully',
            'user' => $user,
        ], 201);
    }
    // Update a user
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $data = $request->all();
        if (!isset($data['user_date'])) {
            $data['user_date'] = now(); // Set current date/time if not provided
        }

        $user->update($data);

        return response()->json(['message' => 'User updated', 'user' => $user]);
    }

    // Remove a user
    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json(['message' => 'User removed']);
    }
    //available
   
   public function availableFingerprintIds(Request $request)
    {
        $request->validate([
            'device_uid' => 'required|string'
        ]);

    // Get all used fingerprint IDs for the specific device
        $usedIds = User::where('device_uid', $request->device_uid)
                    ->pluck('fingerprint_id')
                    ->toArray();

    // All possible fingerprint IDs
        $allIds = range(1, 127);

    // Filter out used IDs
        $availableIds = array_values(array_diff($allIds, array_map('intval', $usedIds)));

        return response()->json([
            'device_uid' => $request->device_uid,
            'available_fingerprint_ids' => $availableIds
        ]);
    }

    
    public function selectUserForFingerprint(Request $request)
    {
        $request->validate([
            'serialnumber' => 'required|numeric',
            'device_uid' => 'required|string',

        ]);

        // Find user (ensure they're not already enrolled)
         $user = User::where('serialnumber', $request->serialnumber)
                ->where('device_uid', $request->device_uid)
                ->first();
        if (!$user) {
        return response()->json(['message' => 'User not found'], 404);
        }

        // Step 1: Deselect  all other users
        User::where('device_uid', $user->device_uid)
            ->update([
                'fingerprint_select' => 0,
                'add_fingerid' => 0,
            ]);

            // Step 2: Select the user for fingerprint enrollment
        $user->update([
            'fingerprint_select' => 1,
            'add_fingerid' => 1,// Set add_fingerid to 1 to indicate fingerprint enrollment
        ]);

        return response()->json([
            'message' => 'User selected for fingerprint enrollment',
            'user' => $user
        ]);
    }


    
    public function addFingerprintId(Request $request)
    {
        $request->validate([
            'fingerprint_id' => 'required|integer|min:1|max:127',
            'device_uid' => 'required|string',
            'serialnumber' => 'required|numeric',
        ]);
        // Check if fingerprint ID is already used
         $alreadyUsed = User::where('fingerprint_id', $request->fingerprint_id)
            ->where('device_uid', $request->device_uid)
            ->exists();

        if ($alreadyUsed) {
             return response()->json([
                'message' => 'Fingerprint ID already in use',
            ], 409);
        }

        //  Find selected user with fingerprint_select = 1 and add_fingerid = 1
        $user = User::where('serialnumber', $request->serialnumber)
            ->where('device_uid', $request->device_uid)
            ->where('fingerprint_select', 1)
            ->where('add_fingerid', 1)
            ->first();

        if (!$user) {
            return response()->json(['message' => 'No valid user found for assigning fingerprint'], 404);
        }

        // Step 3: Assign fingerprint ID (only assigning — not setting add_fingerid or fingerprint_select yet)
        $user->update([
            'fingerprint_id' => $request->fingerprint_id
        ]);

        return response()->json([
            'message' => 'Fingerprint ID assigned. Now scan fingerprint on device.',
            'user' => $user
        ]);
    }

    
    public function checkScanStatus(Request $request)
    {
    $request->validate([
        'device_uid' => 'required|string',
        'serialnumber' => 'required|string',
    ]);

    $user = User::where('serialnumber', $request->serialnumber)
                ->where('device_uid', $request->device_uid)
                ->first();

    if (!$user) {
        return response()->json([
            'message' => 'User not found'
        ], 404);
    }

    //  Case 1: Fully scanned and saved
    if ($user->fingerprint_id && $user->add_fingerid == 0 && $user->fingerprint_select == 0) {
        return response()->json([
            'message' => 'Fingerprint scan successfully completed and saved to DB',
            'status' => 'completed',
            'fingerprint_id' => $user->fingerprint_id,
            'user' => $user
        ]);
    }

    //  Case 2: Scan was saved to fallback user (username = 'None') — try to recover it
    if ($user->fingerprint_id && ($user->fingerprint_select == 1 || $user->add_fingerid == 1)) {
        $ghost = User::where('fingerprint_id', $user->fingerprint_id)
                     ->where('username', 'None')
                     ->where('device_uid', $request->device_uid)
                     ->first();

        if ($ghost) {
            $ghost->delete();
            $user->update([
                'add_fingerid' => 0,
                'fingerprint_select' => 0
            ]);

            return response()->json([
                'message' => 'Fingerprint scanned and reassigned from fallback',
                'status' => 'recovered',
                'fingerprint_id' => $user->fingerprint_id,
                'user' => $user
            ]);
        }

        return response()->json([
            'message' => 'Fingerprint ID assigned but not yet scanned on device',
            'status' => 'pending_scan',
            'fingerprint_id' => $user->fingerprint_id,
        ], 202);
    }

    // Case 3: No fingerprint ID assigned yet
    if (!$user->fingerprint_id && $user->fingerprint_select == 1 && $user->add_fingerid == 1) {
        return response()->json([
            'message' => 'User selected but fingerprint ID not yet reserved',
            'status' => 'pending_id',
        ], 202);
    }

    //  Nothing in progress
    return response()->json([
        'message' => 'Fingerprint enrollment not completed',
        'status' => 'not_enrolled',
    ], 202);
}

        

    public function assignUserToFingerprint(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'serialnumber' => 'required|string',
            'gender' => 'required|in:Male,Female',
            'start_time' => 'nullable|string',
            'end_time' => 'nullable|string',
            'device_uid' => 'required|string',
        ]);

        $user = User::where('fingerprint_select', 1)
            ->where('device_uid', $request->device_uid)
            ->first();

        if (!$user) {
            return response()->json(['message' => 'No selected fingerprint found'], 404);
        }

        if ($user->add_fingerid == 0) {
            return response()->json(['message' => 'This fingerprint is already registered'], 409);
        }

        $serialExists = User::where('serialnumber', $request->serialnumber)
            ->where('device_uid', $request->device_uid)
            ->exists();

        if ($serialExists) {
            return response()->json(['message' => 'Serial number already taken'], 409);
        }

        $user->update([
            'username' => $request->username,
            'serialnumber' => $request->serialnumber,
            'gender' => $request->gender,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'add_fingerid' => 0,
        ]);

        return response()->json(['message' => 'User assigned to fingerprint', 'user' => $user]);
    }

    
        

    public function deleteByFingerprint(Request $request)
    {
        $request->validate([
            'fingerprint_id' => 'required|integer',
            'device_uid' => 'required|string',
        ]);

        $user = User::where('fingerprint_id', $request->fingerprint_id)
            ->where('device_uid', $request->device_uid)
            ->first();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $user->delete();
        return response()->json(['message' => 'User deleted']);
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
    
}