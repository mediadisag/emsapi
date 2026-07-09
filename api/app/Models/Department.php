<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;
    protected $table = 'department';
    protected $fillable = [
    'department_name',
    'company_id',
    'branch_id',
    'device_uid',
    'allowed_leaves'   // ← new
];
}
