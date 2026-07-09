<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'username',
        'serialnumber',
        'fingerprint_id',
        'gender',
        'device_dep',
        'device_uid',
        'device_branch',
        'user_date',
        'email',
        'password',
        'user_types_id',
        'fingerprint_select',
        'add_fingerid',
        'del_fingerid',
        'hourly_pay',
        'department_id',
        'company_id',   // ← fix
        'branch_id',    // ← fix
        'alternative_mobile',   // ← new
        'bank_name',            // ← new
        'account_number',       // ← new
        'ifsc_code',             // ← new        
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
    ];

    public $timestamps = false;
}