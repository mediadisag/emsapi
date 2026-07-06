<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\Device;
use App\Models\User; 
use App\Models\UsersLog;


class AttendanceController extends Controller
{
    //start time=login
    public function login(Request $request)
    {
        $request->validate([
            'fingerprint_id' => 'required|string',
            
        ]);

        $fingerprint_id = $request->fingerprint_id;
        
        $user = User::where('fingerprint_id', $request->fingerprint_id)->first();
        
        if(!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $today=Carbon::now('Asia/Calcutta')->toDateString();
        $now = Carbon::now('Asia/Calcutta')->toTimeString();

        $alreadyLogged = UsersLog::where('fingerprint_id', $fingerprint_id)
            ->whereDate('checkindate', $today)
            ->exists(); 

        if ($alreadyLogged) {
            return response()->json(['message' => 'User already logged in today'], 400);
        }


        UsersLog::create([
            'fingerprint_id' => $fingerprint_id,
            'username' => $user->username,
            'device_dep' => $user->device_dep,
            'device_branch' => $user->device_branch,
            'checkindate' => $today,
            'timein' => $now,
            'timeout' => '00:00:00', //placeholder for login
            'device_uid' => $user->device_uid,
            'serialnumber' => $user->serialnumber,
        ]);
        // Logic to handle attendance login
        // This should include checking the device UID, mode, department, and date

        return response()->json([
            'message' => 'Attendance logged successfully',
            'user' => [
                'username' => $user->username,
                'device_dep' => $user->device_dep,
                'device_branch' => $user->device_branch,
                'checkindate' => $today,
                'timein' => $now,
            ]
    ], 200);
    }

    //end time=logout
    public function logout(Request $request)
    {
        $request->validate([
            'fingerprint_id' => 'required|string',
        ]);

        $fingerprint_id = $request->fingerprint_id;
        $today=Carbon::now('Asia/Calcutta')->toDateString();
        $now = Carbon::now('Asia/Calcutta')->toTimeString();

        $log=UsersLog::where('fingerprint_id', $fingerprint_id)
            ->whereDate('checkindate', $today)
            ->where('timeout', '00:00:00') // Ensure we only update logs that haven't been logged out yet
            ->first();

        if (!$log) {
            return response()->json(['message' => 'No active attendance found for today'], 404);
        }
        if($log) {
            $log->timeout = $now;
            $log->save();
        }

        return response()->json(['message' => 'Logout successful'], 200);
    }
}
