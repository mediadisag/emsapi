<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class UserTypeController extends Controller
{
    public function index()
    {
        $types = DB::table('user_types')->orderBy('id')->get();
        return response()->json($types);
    }
}