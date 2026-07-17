<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Self-service task endpoints for the logged-in employee — mirrors the
 * pattern used by MyAttendanceController / MyDocumentsController: every
 * query is scoped by auth()->user()->id, no user_id param is accepted.
 *
 * Routes to add (routes/api.php), inside the same auth:sanctum group as
 * /my-attendance/*, /my-documents, /my-leave-requests, etc:
 *
 *   Route::get('/my-tasks', [MyTaskController::class, 'index']);
 *   Route::post('/my-tasks/{id}/complete', [MyTaskController::class, 'complete']);
 */
class MyTaskController extends Controller
{
    /**
     * GET /api/my-tasks
     * Returns every task assigned to the logged-in employee, newest first,
     * plus pending_count (Pending + In Progress) for the dashboard tile's
     * count pill.
     */
    public function index(Request $request)
    {
        $employee = Auth::user();

        $rows = Task::with('assignedBy:id,username')
            ->where('employee_id', $employee->id)
            ->latest()
            ->get()
            ->map(function (Task $task) {
                return [
                    'id'               => $task->id,
                    'task_name'        => $task->task_name,
                    'description'      => $task->description,
                    'priority'         => $task->priority,
                    'due_date'         => optional($task->due_date)->format('Y-m-d'),
                    'status'           => $task->status,
                    'completed_at'     => optional($task->completed_at)->toDateTimeString(),
                    'approval_status'  => $task->approval_status, // accessor on the model
                    'approval_note'    => $task->approval_note,
                    'attachment_name'  => $task->attachment_name,
                    'file_url'         => $task->file_url, // accessor on the model
                    'assigned_by_name' => optional($task->assignedBy)->username,
                ];
            });

        $pendingCount = $rows->filter(function ($row) {
            return strtolower($row['status']) !== 'completed';
        })->count();

        return response()->json([
            'status'        => true,
            'rows'          => $rows->values(),
            'pending_count' => $pendingCount,
        ]);
    }

    /**
     * POST /api/my-tasks/{id}/complete
     * Employee marks their own task done. Sets completed_at and flips
     * status to "Completed" — is_approved stays null until an admin
     * reviews it via TaskController::review(), same handoff already used
     * for that endpoint's "has this task been completed yet?" check.
     */
    public function complete(Request $request, $id)
    {
        $employee = Auth::user();

        $task = Task::where('employee_id', $employee->id)->find($id);

        if (!$task) {
            return response()->json([
                'status'  => false,
                'message' => 'Task not found.',
            ], 404);
        }

        if ($task->completed_at) {
            return response()->json([
                'status'  => false,
                'message' => 'This task has already been marked complete.',
            ], 422);
        }

        $task->update([
            'completed_at' => now(),
            'status'       => 'Completed',
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Task marked as complete.',
            'task'    => $task->fresh(),
        ]);
    }
}
