<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;



class Company extends Model
{
    use HasFactory;
    protected $table = 'company';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'website',
   ];


    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function branch()
    {
        return $this->hasMany(Branch::class, 'company_id');

    }
    public function users()
{
    return $this->belongsToMany(User::class, 'company_users', 'company_id', 'users_id');
}
 
}
