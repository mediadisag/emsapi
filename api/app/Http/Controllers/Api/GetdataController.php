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

        $fingerID    = $request->query('FingerID');
        $device_uid  = $request->query('device_token');
        $getFingerID = $request->query('Get_Fingerid');
        $checkMode   = $request->query('Check_mode');
        $confirmId   = $request->query('confirm_id');
        $deleteId    = $request->query('DeleteID');

        // 0. Validate device
        $device = Device::where('device_uid', $device_uid)->first();
        if (!$device) {
            return response("Invalid Device", 400);
        }

        // 1. Fingerprint scanned
        if ($fingerID !== null && $getFingerID === null && $checkMode === null && $confirmId === null && $deleteId === null) {
            if ($device->device_mode == 1) {
                // ===== Attendance Mode =====
                $user = User::where('fingerprint_id', $fingerID)
                    ->where('device_uid', $device_uid)
                    ->first();

                if (!$user) return response("Not found!", 404);

                if ($user->username !== 'None' && $user->add_fingerid == 0) {
                    $existingLog = UsersLog::where('fingerprint_id', $fingerID)
                        ->where('checkindate', $date)
                        ->where(function ($q) {
                            $q->where('timeout', '00:00:00')
                              ->orWhereNull('timeout')
                              ->orWhere('timeout', '');
                        })->first();

                    if (!$existingLog) {
                        // Login
                        UsersLog::create([
                            'username'       => $user->username,
                            'serialnumber'   => $user->serialnumber,
                            'fingerprint_id' => $fingerID,
                            'device_uid'     => $device_uid,
                            'device_dep'     => $device->device_dep,
                            'checkindate'    => $date,
                            'timein'         => $time,
                            'timeout'        => '00:00:00',
                            'fingerout'      => 0,
                        ]);
                        return response("login" . $user->username);
                    } else {
                        // Logout
                        $existingLog->update([
                            'timeout'   => $time,
                            'fingerout' => 1,
                        ]);
                        return response("logout" . $user->username);
                    }
                } else {
                    return response("Not registered!", 403);
                }
            } else {
                // ===== Enrollment Mode =====
                $exists = User::where('fingerprint_id', $fingerID)
                    ->where('device_uid', $device_uid)
                    ->exists();

                if ($exists) return response("available");

                $selectedUser = User::where('fingerprint_select', 1)
                    ->where('add_fingerid', 1)
                    ->where('device_uid', $device_uid)
                    ->first();

                if ($selectedUser) {
                    $selectedUser->update([
                        'fingerprint_id'     => $fingerID,
                        'add_fingerid'       => 0,
                        'fingerprint_select' => 0,
                    ]);
                    return response("successful");
                }

                // No user selected, auto-create
                User::create([
                    'username'       => 'None',
                    'serialnumber'   => null,
                    'device_uid'     => $device_uid,
                    'device_dep'     => $device->device_dep,
                    'fingerprint_id' => $fingerID,
                    'user_date'      => now(),
                    'add_fingerid'   => 0,
                ]);
                return response("successful");
            }
        }

        // 2. Get selected fingerprint ID
        if ($getFingerID === "get_id") {
            $user = User::where('fingerprint_select', 1)
                ->where('device_uid', $device_uid)
                ->first();

            return $user
                ? response("add-id" . ($user->fingerprint_id ?? 0))
                : response("Nothing");
        }

        // 3. Return device mode
        if ($checkMode === "get_mode") {
            return response("mode" . $device->device_mode);
        }

        // 4. Confirm manually (deprecated)
        if ($confirmId) {
            User::where('device_uid', $device_uid)
                ->where('fingerprint_select', 1)
                ->update(['fingerprint_select' => 0]);

            User::where('fingerprint_id', $confirmId)
                ->where('device_uid', $device_uid)
                ->update([
                    'add_fingerid'       => 0,
                    'fingerprint_select' => 1
                ]);

            return response("Fingerprint has been added!");
        }

        // 5. Delete fingerprint
        if ($deleteId === "check") {
            $user = User::where('del_fingerid', 1)
                ->where('device_uid', $device_uid)
                ->first();

            if ($user) {
                $id = $user->fingerprint_id;
                $user->delete();
                return response("del-id" . $id);
            }
            return response("nothing");
        }

        return response("Invalid Request", 400);
    }
}
