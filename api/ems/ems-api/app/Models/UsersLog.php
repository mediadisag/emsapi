<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsersLog extends Model
{
    protected $table = 'users_logs';
    public $timestamps = false; 
    use HasFactory;
    protected $fillable = [
        'username',
        'serialnumber',
        'fingerprint_id',
        'device_uid',
        'device_branch',
        'device_dep',
        'checkindate',
        'timein',
        'timeout',
        'fingerout',
    ];
}
