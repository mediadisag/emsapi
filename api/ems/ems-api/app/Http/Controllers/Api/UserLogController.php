<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UsersLog;

class UserLogController extends Controller
{
    public function index(Request $request)
    {
        $query = UsersLog::query();

        // Filter by Date Range
        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('checkindate', [$request->date_from, $request->date_to]);
        }

        // Filter by Time-in Range
        if ($request->filled('time_from') && $request->filled('time_to')) {
            $query->whereBetween('timein', [$request->time_from, $request->time_to]);
        }

        // Filter by Time-out Range
        if ($request->filled('timeout_from') && $request->filled('timeout_to')) {
            $query->whereBetween('timeout', [$request->timeout_from, $request->timeout_to]);
        }

        // Filter by Fingerprint ID (if not 'All')
        if ($request->filled('fingerprint_id') && $request->fingerprint_id != '0') {
            $query->where('fingerprint_id', $request->fingerprint_id);
        }

        // Filter by Username (if not 'All')
        if ($request->filled('username') && $request->username != '0') {
            $query->where('username', $request->username);
        }

        // Filter by Device Department (if not 'All')
        if ($request->filled('device_dep') && $request->device_dep != '0') {
            $query->where('device_dep', $request->device_dep);
        }

        // Filter by Device Branch (if not 'All')
        if ($request->filled('branch') && $request->branch != '0') {
            $query->where('device_branch', $request->branch);
        }

        // Final result
        $logs = $query->orderBy('checkindate', 'desc')
                      ->orderBy('timein', 'desc')
                      ->get();

        return response()->json(['data' => $logs]);
    }
}
