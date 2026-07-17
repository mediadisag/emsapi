<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class MyProfileController extends Controller
{
    // ────────────────────────────────────────────────────────────────────────
    // GET /my-profile — return the logged-in user's own profile fields.
    // Strictly scoped to auth()->user() — no user_id param accepted.
    // ────────────────────────────────────────────────────────────────────────
    public function show(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'email'              => $user->email,
                'alternative_mobile' => $user->alternative_mobile,
                'bank_name'          => $user->bank_name,
                'account_number'     => $user->account_number,
                'ifsc_code'          => $user->ifsc_code,
            ],
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // PUT /my-profile — update the logged-in user's own profile fields.
    // Strictly scoped to auth()->user() — no user_id param accepted, so an
    // employee can never edit anyone else's record via this endpoint.
    // ────────────────────────────────────────────────────────────────────────
    public function update(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'email'              => 'required|email|unique:users,email,' . $user->id,
            'alternative_mobile' => 'nullable|string|max:15',
            'bank_name'          => 'nullable|string|max:100',
            'account_number'     => 'nullable|string|max:30',
            'ifsc_code'          => ['nullable', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
        ]);

        $user->update([
            'email'              => $request->email,
            'alternative_mobile' => $request->alternative_mobile,
            'bank_name'          => $request->bank_name,
            'account_number'     => $request->account_number,
            'ifsc_code'          => $request->ifsc_code ? strtoupper($request->ifsc_code) : null,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Profile updated successfully.',
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // POST /change-password — change the logged-in user's own password.
    // Kept here since the frontend's "Change Password" modal already posts
    // to this route; strictly scoped to auth()->user().
    // ────────────────────────────────────────────────────────────────────────
    public function changePassword(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'current_password'          => 'required|string',
            'new_password'              => 'required|string|min:8',
            'new_password_confirmation' => 'required|string|same:new_password',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        if (Hash::check($request->new_password, $user->password)) {
            return response()->json(['message' => 'New password must be different from the current password.'], 422);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Password updated successfully.',
        ]);
    }
}
