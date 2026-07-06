<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsersLog extends Model
{
  protected $table = 'users_logs';
    public $timestamps = false;

    protected $fillable = [
    'username',
    'user_id',          
    'serialnumber',
    'fingerprint_id',
    'device_uid',
    'device_branch',
    'device_dep',
    'checkindate',
    'timein',
    'timeout',
    'fingerout',
    'is_edited',
    'edited_by',
    'edited_at',
    ];

    protected $casts = [
        'is_edited' => 'integer',
        'edited_at' => 'datetime',
    ];
}
