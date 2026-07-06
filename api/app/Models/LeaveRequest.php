<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    protected $table = 'leave_requests';

    protected $fillable = [
        'user_id',
        'type',
        'from_date',
        'to_date',
        'reason',
        'status',
        'admin_reason',
        'reviewed_by',
        'reviewed_at',
    ];
}