<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/*
|==============================================================================
| ⚠️ CONFIRM BEFORE DEPLOYING: replace $adminTypeIds with your real
| company-admin / branch-admin user_types_id values.
|==============================================================================
*/

class AdminLeaveController extends Controller
{
    // ✅ Confirmed role mapping (from Dashboard.html's buildScopeParams):
    // 1 = Super Admin, 2 = Company Admin, 3 = Branch Admin,
    // 4 = Department Admin, 5 = Employee. All except Employees can review.
    private array $adminTypeIds = [1, 2, 3, 4];



    private function assertIsAdmin(Request $request)
{
    $user = $request->user();
    if (!in_array((int) $user->user_types_id, $this->adminTypeIds, true)) {
        return response()->json([
            'status'  => false,
            'message' => 'You are not authorized to review leave requests.',
        ], 403);
    }
    return null;
}

    /*
    |==========================================================================
    | GET /admin/leave-requests?status=Pending
    |==========================================================================
    */
    public function index(Request $request)
    {
        if ($deny = $this->assertIsAdmin($request)) {
            return $deny;
        }

        $status = $request->query('status', 'Pending');

        $validator = Validator::make(['status' => $status], [
            'status' => 'in:Pending,Approved,Rejected,All',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        $query = LeaveRequest::query()
            ->join('users', 'users.id', '=', 'leave_requests.user_id')
            ->select([
                'leave_requests.id',
                'leave_requests.user_id',
                'users.username',
                'leave_requests.type',
                'leave_requests.from_date',
                'leave_requests.to_date',
                'leave_requests.reason',
                'leave_requests.status',
                'leave_requests.admin_reason',
                'leave_requests.reviewed_by',
                'leave_requests.reviewed_at',
                'leave_requests.created_at',
            ]);

        if ($status !== 'All') {
            $query->where('leave_requests.status', $status);
        }

        $rows = $query->orderBy('leave_requests.created_at', 'desc')->get();

        return response()->json([
            'status' => true,
            'rows'   => $rows,
        ]);
    }

    /*
    |==========================================================================
    | PUT /admin/leave-requests/{id}/review
    | Body: { decision: 'Approved'|'Rejected', reason?: string }
    |
    | ✅ NEW — `reason` is REQUIRED when decision = 'Rejected' (so the
    | employee always knows why), and OPTIONAL when Approved (e.g. "approved,
    | please share your WFH plan for the day").
    | Stores into leave_requests.admin_reason, and also fires a
    | notification back to the employee about the decision.
    |==========================================================================
    */
    public function review(Request $request, $id)
    {
        if ($deny = $this->assertIsAdmin($request)) {
            return $deny;
        }

        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:Approved,Rejected',
            'reason'   => $request->input('decision') === 'Rejected'
                ? 'required|string|max:500'
                : 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        $leave = LeaveRequest::find($id);
        if (!$leave) {
            return response()->json(['status' => false, 'message' => 'Leave request not found.'], 404);
        }

        if ($leave->status !== 'Pending') {
            return response()->json([
                'status'  => false,
                'message' => "This request has already been {$leave->status}.",
            ], 409);
        }

        $decision = $request->input('decision');
        $reason   = $request->input('reason');

        DB::beginTransaction();
        try {
            // ⚠️ reviewed_by is varchar(100) in your DB — storing the
            // reviewer's username/name (a display string), not their
            // numeric user_id. Adjust the field name below if your users
            // table uses something other than ->username.
            $leave->update([
                'status'       => $decision,
                'admin_reason' => $reason,
                'reviewed_by'  => $request->user()->username ?? $request->user()->name ?? (string) $request->user()->id,
                'reviewed_at'  => now(),
            ]);

            // ✅ Notify the employee about the decision.
            $message = $decision === 'Approved'
                ? "Your {$leave->type} request ({$leave->from_date} to {$leave->to_date}) was approved." . ($reason ? " Note: {$reason}" : '')
                : "Your {$leave->type} request ({$leave->from_date} to {$leave->to_date}) was rejected. Reason: {$reason}";

            DB::table('notifications')->insert([
                'user_id'        => $leave->user_id,
                'type'           => 'leave_request_reviewed',
                'title'          => "{$leave->type} request {$decision}",
                'message'        => $message,
                'reference_type' => 'leave_request',
                'reference_id'   => $leave->id,
                'is_read'        => 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => "Request {$decision}.",
                'data'    => $leave->fresh(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }
}