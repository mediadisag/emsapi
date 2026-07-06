<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Device;


class ManageUserController extends Controller
{
public function store(Request $request)
    {
        $request->validate([
        'username' => 'required|string',
        'serialnumber' => 'required|string|unique:users,serialnumber',
        'fingerprint_id' => 'nullable|string|integer|min:1|max:127',
        'gender' => 'required|in:Male,Female',
        'device_dep' => 'required|string',
        ]);

        $data = $request->all();
        $data['user_date'] = now(); // Set current date/time

        Log::info($data);
        $user = User::create($data);

        return response()->json(['message' => 'User added', 'user' => $user], 201);
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
   
   public function availableFingerprintIds()
    {
        $usedIds = User::pluck('fingerprint_id')->toArray();
        $allIds = range(1, 127);
        $availableIds = array_values(array_diff($allIds, array_map('intval', $usedIds)));

        return response()->json([
            'available_fingerprint_ids' => $availableIds
        ]);
    }
   public function availableDevices()
    {
        $deviceDepartments = Device::select('device_dep')
                        ->distinct()
                        ->pluck('device_dep');

            return response()->json([
                'available_device_departments' => $deviceDepartments
            ]);
            
    }
}    