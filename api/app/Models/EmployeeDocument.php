<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'file_name',
        'file_path',
        'status',
        'admin_reason',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
