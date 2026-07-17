<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class TaskController extends Controller
{
    /**
     * POST /api/admin/tasks
     * multipart/form-data: employee_id, task_name, priority, due_date,
     * description, notify (1/0), attachment (file, optional)
     *
     * Mirrors AdminLeaveController::store — scope-checks the target
     * employee, saves the task, and (if requested) drops a notification
     * into the employee's own notification list.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id'  => ['required', 'exists:users,id'],
            'task_name'    => ['required', 'string', 'max:255'],
            'priority'     => ['nullable', 'in:Low,Medium,High'],
            'due_date'     => ['nullable', 'date'],
            'description'  => ['nullable', 'string'],
            'notify'       => ['nullable', 'boolean'],
            'attachment'   => ['nullable', 'file', 'max:5120'], // 5MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $admin    = Auth::user();
        $employee = User::findOrFail($request->employee_id);

        // ── Scope check ──────────────────────────────────────────────
        // Same idea as AdminLeaveController: a non-super-admin can only
        // assign tasks to employees inside their own scope.
        // Adjust the user_types_id numbers / column names below to match
        // your actual users table if they differ.
        if (!$this->employeeInAdminScope($admin, $employee)) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not allowed to assign tasks to this employee.',
            ], 403);
        }

        // ── Handle optional attachment ───────────────────────────────
        $attachmentPath = null;
        $attachmentName = null;
        if ($request->hasFile('attachment')) {
            $file           = $request->file('attachment');
            $attachmentName = $file->getClientOriginalName();
            $attachmentPath = $file->store('tasks/' . $employee->id, 'public');
        }

        $task = Task::create([
            'employee_id'     => $employee->id,
            'assigned_by'     => $admin->id,
            'task_name'       => $request->task_name,
            'description'     => $request->description,
            'priority'        => $request->priority ?? 'Low',
            'due_date'        => $request->due_date,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'status'          => 'Pending',
            'notified'        => (bool) $request->boolean('notify'),
        ]);

        // ── Notify the employee ──────────────────────────────────────
        if ($request->boolean('notify')) {
            Notification::create([
                'user_id'      => $employee->id,
                'title'        => 'New task assigned',
                'message'      => $task->task_name . ($task->due_date ? ' · due ' . $task->due_date->format('d M Y') : ''),
                'type'         => 'task_assigned',
                'reference_id' => $task->id,
                'is_read'      => false,
            ]);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Task assigned successfully.',
            'task'    => array_merge($task->toArray(), [
                'file_url' => $task->file_url,
            ]),
        ], 201);
    }

    /**
     * GET /api/admin/tasks — optional listing endpoint (scoped the same
     * way as the store() check below), handy for a future "My Assigned
     * Tasks" panel on this dashboard.
     */
    public function index(Request $request)
    {
        $admin = Auth::user();

        $query = Task::with(['employee:id,username,department_id', 'assignedBy:id,username'])
            ->latest();

        if ((int) $admin->user_types_id !== 1) {
            $query->whereHas('employee', function ($q) use ($admin) {
                $this->applyScopeToEmployeeQuery($q, $admin);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json([
            'status' => true,
            'rows'   => $query->get(),
        ]);
    }

    /**
     * PUT /api/admin/tasks/{id}/review
     * body: { decision: "Approved" | "Rejected", note?: string }
     *
     * Mirrors AdminLeaveController::review — only makes sense once the
     * employee has marked the task completed (task.completed_at is set).
     * Rejecting requires a note (same UX as the leave/document reject flow:
     * a reason box that appears on first click, then a second click submits).
     */
    public function review(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'decision' => ['required', 'in:Approved,Rejected'],
            'note'     => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $admin = Auth::user();
        $task  = Task::with('employee')->findOrFail($id);

        if (!$this->employeeInAdminScope($admin, $task->employee)) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not allowed to review this task.',
            ], 403);
        }

        if (!$task->completed_at) {
            return response()->json([
                'status'  => false,
                'message' => 'This task has not been marked complete by the employee yet.',
            ], 422);
        }

        if (!is_null($task->is_approved)) {
            return response()->json([
                'status'  => false,
                'message' => 'This task has already been reviewed.',
            ], 422);
        }

        $decision = $request->decision;
        $note     = trim((string) $request->note);

        if ($decision === 'Rejected' && $note === '') {
            return response()->json([
                'status'  => false,
                'message' => 'Please provide a reason for rejecting this task.',
            ], 422);
        }

        $task->update([
            'is_approved'   => $decision === 'Approved',
            'approved_by'   => $admin->id,
            'approved_at'   => now(),
            'approval_note' => $note !== '' ? $note : null,
            // Rejected work goes back to "In Progress" so the employee can redo it.
            'status'        => $decision === 'Approved' ? 'Completed' : 'In Progress',
        ]);

        // Notify the employee of the outcome.
        Notification::create([
            'user_id'      => $task->employee_id,
            'title'        => $decision === 'Approved' ? 'Task approved' : 'Task needs rework',
            'message'      => $task->task_name . ($note !== '' ? ' — ' . $note : ''),
            'type'         => 'task_reviewed',
            'reference_id' => $task->id,
            'is_read'      => false,
        ]);

        return response()->json([
            'status'  => true,
            'message' => "Task {$decision}.",
            'task'    => $task->fresh(),
        ]);
    }

    /**
     * Scope check used by store(): can $admin assign a task to $employee?
     * user_types_id: 1 = Super Admin, 2 = Company Admin, 3 = Branch Admin,
     * 4 = Department Admin, 5 = Employee (same enum used in the dashboard's
     * buildScopeParams()). Adjust column names (company_id/branch_id/
     * department_id) if your users table uses different ones.
     */
    private function employeeInAdminScope(User $admin, User $employee): bool
    {
        switch ((int) $admin->user_types_id) {
            case 1: // Super Admin — no restriction
                return true;
            case 2: // Company Admin
                return $employee->company_id == $admin->company_id;
            case 3: // Branch Admin
                return $employee->branch_id == $admin->branch_id;
            case 4: // Department Admin
                return $employee->department_id == $admin->department_id;
            default:
                return false;
        }
    }

    private function applyScopeToEmployeeQuery($query, User $admin): void
    {
        switch ((int) $admin->user_types_id) {
            case 2:
                $query->where('company_id', $admin->company_id);
                break;
            case 3:
                $query->where('branch_id', $admin->branch_id);
                break;
            case 4:
                $query->where('department_id', $admin->department_id);
                break;
        }
    }
}