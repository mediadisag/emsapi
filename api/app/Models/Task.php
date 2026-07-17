<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'assigned_by',
        'task_name',
        'description',
        'priority',
        'due_date',
        'attachment_path',
        'attachment_name',
        'status',
        'completed_at',
        'is_approved',
        'approved_by',
        'approved_at',
        'approval_note',
        'notified',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'completed_at' => 'datetime',
        'approved_at'  => 'datetime',
        'is_approved'  => 'boolean',
        'notified'     => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Human-readable approval state for the frontend badge:
    // "Awaiting Review" | "Approved" | "Rejected" | null (not completed yet)
    public function getApprovalStatusAttribute(): ?string
    {
        if (!$this->completed_at) {
            return null;
        }
        if (is_null($this->is_approved)) {
            return 'Awaiting Review';
        }
        return $this->is_approved ? 'Approved' : 'Rejected';
    }

    // Convenience accessor so controllers can return a ready-to-use URL
    // the same way documents/leave attachments do.
    public function getFileUrlAttribute(): ?string
    {
        return $this->attachment_path
            ? asset('storage/' . $this->attachment_path)
            : null;
    }
}