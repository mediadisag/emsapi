<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class MyLeaveController extends Controller
{
    /*
    |==========================================================================
    | GET /my-leave-requests
    | Employee's own Leave/WFH requests — scoped directly by
    | auth()->user()->id, latest first.
    |==========================================================================
    */
    public function index(Request $request)
    {
        $user = $request->user();

        $rows = LeaveRequest::where('user_id', $user->id)
            ->orderBy('from_date', 'desc')
            ->orderBy('id', 'desc')
            ->get([
                'id', 'type', 'from_date', 'to_date', 'reason',
                'status', 'admin_reason', 'reviewed_by', 'reviewed_at', 'created_at',
            ]);

        return response()->json([
            'status' => true,
            'rows'   => $rows,
        ]);
    }

    /*
    |==========================================================================
    | POST /my-leave-requests  { type, from_date, to_date, reason }
    | Employee submits a new Leave or WFH request — always created against
    | auth()->user()->id, always starts as 'Pending'.
    |
    | ✅ Notifications — scoped, NOT broadcast to every admin:
    |   1. The applicant's OWN department head (user_types_id = 4, same
    |      department_id as the applicant) — the person who should
    |      actually review this request.
    |   2. The applicant themselves — a confirmation that the request
    |      went in.
    | Super Admin / Company Admin / other department heads are NOT
    | notified — only the relevant department head + the employee.
    |==========================================================================
    */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'type'      => 'required|in:Leave,WFH',
            'from_date' => 'required|date_format:Y-m-d',
            'to_date'   => 'required|date_format:Y-m-d|after_or_equal:from_date',
            'reason'    => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();

        // ✅ overlap check — same employee, same date range already has a
        // Pending/Approved request (avoid duplicate submissions)
        $overlap = LeaveRequest::where('user_id', $user->id)
            ->whereIn('status', ['Pending', 'Approved'])
            ->where('from_date', '<=', $data['to_date'])
            ->where('to_date', '>=', $data['from_date'])
            ->exists();

        if ($overlap) {
            return response()->json([
                'status'  => false,
                'message' => 'You already have a request covering these dates.',
            ], 409);
        }

        DB::beginTransaction();
        try {
            $leave = LeaveRequest::create([
                'user_id'   => $user->id,
                'type'      => $data['type'],
                'from_date' => $data['from_date'],
                'to_date'   => $data['to_date'],
                'reason'    => $data['reason'],
                'status'    => 'Pending',
            ]);

            $now = now();
            $notifRows = [];

            // ✅ 1. Notify the applicant's own department head only
            //    (user_types_id = 4, matching department_id).
            $deptHeadIds = DB::table('users')
                ->where('user_types_id', 4)
                ->where('department_id', $user->department_id)
                ->pluck('id');

            foreach ($deptHeadIds as $adminId) {
                $notifRows[] = [
                    'user_id'        => $adminId,
                    'type'           => 'leave_request_submitted',
                    'title'          => 'New ' . $data['type'] . ' request',
                    'message'        => ($user->username ?? 'An employee') . " requested {$data['type']} from {$data['from_date']} to {$data['to_date']}.",
                    'reference_type' => 'leave_request',
                    'reference_id'   => $leave->id,
                    'is_read'        => 0,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }

            // ✅ 2. Notify the employee themselves — confirmation that
            //    their request was submitted and is pending.
            $notifRows[] = [
                'user_id'        => $user->id,
                'type'           => 'leave_request_submitted_self',
                'title'          => 'Request submitted',
                'message'        => "Your {$data['type']} request from {$data['from_date']} to {$data['to_date']} has been submitted and is pending approval.",
                'reference_type' => 'leave_request',
                'reference_id'   => $leave->id,
                'is_read'        => 0,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];

            if (!empty($notifRows)) {
                DB::table('notifications')->insert($notifRows);
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => ucfirst($data['type']) . ' request submitted.',
                'data'    => $leave,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}