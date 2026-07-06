<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\Device;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    public function list()
{
    $devices = Device::all([
        'device_name',
        'device_dep',
        'branch_code',
        'location',
        'state',    
        'device_uid',
        'device_mode',
        'device_date'
    ]);

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
        $validated = $request->validate([
            'device_name' => 'required|string|max:255',
            'device_dep' => 'nullable|string|max:255',
            'device UID' => 'nullable|string|max:255', 
            'branch_code' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'state' => 'required|string|max:255', 
            'device_mode' => 'nullable|in:0,1', // 0 for normal, 1 for admin
            'device_date' => 'nullable|date',
            
        ]);

        $validated['device_uid'] = Str::random(16);
        $validated['device_date'] = now()->toDateString();

        $device = Device::create($validated);

        return response()->json(['message' => 'Device created successfully', 'device' => $device], 201);
    }

    public function destroy(Request $request)
    {
        
        $request->validate(['device_name' => 'required|string']);

    $device = Device::where('device_name', $request->device_name)->first();

    if (!$device) {
        return response()->json(['error' => 'Device not found'], 404);
    }

    $device->delete();

    return response()->json(['message' => 'Device deleted successfully']);;
    }

    public function regenerateUID(Request $request)
    {
        $request->validate(['id' => 'required|integer']);

        $device = Device::find($request->id);
        if (!$device) {
            return response()->json(['error' => 'Device not found'], 404);
        }

        $device->device_uid = Str::random(16);
        $device->save();

        return response()->json(['message' => 'Device UID regenerated', 'device_uid' => $device->device_uid]);
    }

    public function updateMode(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'device_mode' => 'required|in:0,1'
        ]);

        $device = Device::find($request->id);
        if (!$device) {
            return response()->json(['error' => 'Device not found'], 404);
        }

        $device->device_mode = $request->device_mode;
        $device->save();

        return response()->json(['message' => 'Device mode updated']);
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

        $device = Device::find($validated['id']);
        if (!$device) {
            return response()->json(['error' => 'Device not found'], 404);
        }

        $device->update($validated);

        return response()->json(['message' => 'Device updated successfully', 'device' => $device]);
    }
}
