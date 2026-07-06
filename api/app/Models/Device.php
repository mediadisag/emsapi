<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $fillable = [
        'device_name', 'device_dep', 'device_uid', 'device_date', 'device_mode'
    ];

    public $timestamps = false;
}
?>