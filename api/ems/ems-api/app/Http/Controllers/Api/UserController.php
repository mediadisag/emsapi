<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    
    public function index()
    {
        $users=DB::table('users')
            ->select([
                'id',
                'username as name',
                'serialnumber', 
                'gender',
                'fingerprint_id',
                'user_date as date',
                'device_uid as device'
            ])
            ->get();
        return response()->json([
     
            'data' => $users
        ]);

    }
}