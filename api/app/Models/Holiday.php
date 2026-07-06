<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HasFactory;

    protected $table = 'holidays';

    protected $fillable = [
        'company_id',
        'branch_id',
        'department_id',
        'holiday_name',
        'holiday_date',
        'description',
        'is_recurring',
        'created_by',
        'is_edited',
        'edited_by',
        'edited_at',
    ];

    protected $casts = [
        'holiday_date'  => 'date:Y-m-d',
        'is_recurring'  => 'boolean',
        'is_edited'     => 'boolean',
        'edited_at'     => 'datetime',
    ];

    // ✅ Adjust these relations if your Company/Branch/Department models
    // live in a different namespace or use different table names.
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}