<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\Admin;

class AdminController extends Controller
{
 public function login(Request $request)
    {
        $request->validate([
            'admin_email' => 'required|email',
            'admin_pwd' => 'required',
        ]);

        $admin = Admin::where('admin_email', $request->admin_email)->first();
        if (!$admin) {
            return response()->json(['message' => 'Admin not found'], 404);
        }
        if (!Hash::check($request->admin_pwd, $admin->admin_pwd)) {
            return response()->json(['message' => 'Password mismatch'], 401);
        }

        Auth::guard('admin')->login($admin);

        return response()->json([
            'message' => 'Login successful',
            'admin' => $admin,
        ], 200);
    }

    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();
        return response()->json([
            'message' => 'Logout successful',
        ], 200);
    }  
   }
