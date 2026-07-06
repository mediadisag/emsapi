<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Device;
use App\Models\UsersLog;
use Carbon\Carbon;

class GetdataController extends Controller
{
    public function getdata(Request $request)
    {
        date_default_timezone_set('Asia/Kolkata');
        $date = date("Y-m-d");
        $time = date("H:i:s");

        $fingerID = $request->query('FingerID');
        $device_uid = $request->query('device_token');
        $getFingerID = $request->query('Get_Fingerid');
        $checkMode = $request->query('Check_mode');
        $confirmId = $request->query('confirm_id');
        $deleteId = $request->query('DeleteID');

        // 0. Validate device
        $device = Device::where('device_uid', $device_uid)->first();
        if (!$device) {
            return response("Invalid Device", 400);
        }

        // 1. FingerID for attendance (Mode 1) or fingerprint scan (Mode 0)
        if ($fingerID) {
            if ($device->device_mode == 1) {
                // Attendance Mode
                $user = User::where('fingerprint_id', $fingerID)->first();
                if (!$user) return response("Not found!", 404);

                if ($user->username !== 'None' && $user->add_fingerid == 0) {
                    $existingLog = UsersLog::where([
                        ['fingerprint_id', $fingerID],
                        ['checkindate', $date],
                        ['timeout', '00:00:00'],
                    ])->first();

                    if (!$existingLog) {
                        // Login
                        UsersLog::create([
                            'username' => $user->username,
                            'serialnumber' => $user->serialnumber,
                            'fingerprint_id' => $fingerID,
                            'device_uid' => $device_uid,
                            'device_dep' => $device->device_dep,
                            'checkindate' => $date,
                            'timein' => $time,
                            'timeout' => '00:00:00',
                            'fingerout' => 0,
                        ]);
                        return response("login" . $user->username);
                    } else {
                        // Logout
                        $existingLog->update([
                            'timeout' => $time,
                            'fingerout' => 1,
                        ]);
                        return response("logout" . $user->username);
                    }
                } else {
                    return response("Not registered!", 403);
                }
            } else {
                // Enrollment Mode (device_mode = 0)

                // If fingerprint ID already exists for device
                $exists = User::where('fingerprint_id', $fingerID)
                    ->where('device_uid', $device_uid)
                    ->exists();

                if ($exists) {
                    return response("available");
                }

                // Assign to selected user
                $selectedUser = User::where('fingerprint_select', 1)
                    ->where('device_uid', $device_uid)
                    ->first();

                if ($selectedUser) {
                    $selectedUser->update([
                        'fingerprint_id' => $fingerID,
                        'fingerprint_select' => 0,
                        'add_fingerid' => 0,
                    ]);
                    return response("successful");
                }

                return response("no-user-selected");
            }
        }

        // 2. Get selected fingerprint ID
        if ($getFingerID === "get_id") {
            $user = User::where('fingerprint_select', 1)
                ->where('device_uid', $device_uid)
                ->first();

            if ($user) {
                return response("add-id" . ($user->fingerprint_id ?? 0));
            }
            return response("Nothing");
        }

        // 3. Return device mode
        if ($checkMode === "get_mode") {
            return response("mode" . $device->device_mode);
        }

        // 4. Confirm fingerprint addition manually (not needed with new logic)
        if ($confirmId) {
            User::where('device_uid', $device_uid)
                ->where('fingerprint_select', 1)
                ->update(['fingerprint_select' => 0]);

            User::where('fingerprint_id', $confirmId)
                ->where('device_uid', $device_uid)
                ->update([
                    'add_fingerid' => 0,
                    'fingerprint_select' => 1
                ]);

            return response("Fingerprint has been added!");
        }

        // 5. Delete fingerprint (used for manual deletion)
        if ($deleteId === "check") {
            $user = User::where('del_fingerid', 1)
                ->where('device_uid', $device_uid)
                ->first();
            if ($user) {
                $id = $user->fingerprint_id;
                $user->delete();
                return response("del-id" . $id);
            } else {
                return response("nothing");
            }
        }

        // Invalid or unrecognized request
        return response("Invalid Request", 400);
    }
}
