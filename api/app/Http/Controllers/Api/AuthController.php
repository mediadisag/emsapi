<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Company;
use App\Models\CompanyUser;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    /**
     * Build the role-specific extra fields (company_id/name, branch_id,
     * department_id/name, fingerprint_id) that get merged into the user
     * payload for both login() and user().
     *
     * Table confirmed from DB: `department` (singular), column `department_name`.
     */
    private function buildExtraData(User $user): array
    {
        $extraData = [];

        // Always expose the user's own device_uid (used by frontend to scope
        // the fingerprint-enroll device dropdown for non-Super-Admin roles)
        $extraData['device_uid'] = $user->device_uid ?? null;

        // Company Admin → show company_id + company_name
        if ($user->user_types_id == 2) {
            $company = DB::table('company_users')
                ->join('company', 'company.id', '=', 'company_users.company_id')
                ->where('company_users.users_id', $user->id)
                ->select('company.id as company_id', 'company.name')
                ->first();

            if ($company) {
                $extraData['company_id']   = $company->company_id;
                $extraData['company_name'] = $company->name;
            } else {
                $extraData['company_id']   = $user->company_id ?? null;
                $extraData['company_name'] = null;
            }
        }

        // Branch Admin → show branch_id
        if ($user->user_types_id == 3) {
            $extraData['branch_id'] = $user->branch_id ?? null;
        }

        // Department Admin → show department_id + department_name
        // ✅ FIX: department_name added so the frontend (dashboard.html) can
        // scope Firebase attendance data (which only stores a "company"
        // string per user, not an id) to the logged-in admin's own
        // department instead of showing every employee on the shared device.
        if ($user->user_types_id == 4) {
            $extraData['department_id'] = $user->department_id ?? null;

            $dept = DB::table('department')
                ->where('id', $user->department_id)
                ->select('department_name')
                ->first();

            $extraData['department_name'] = $dept->department_name ?? null;
        }

        // Employee (Role 5) → show fingerprint_id so Dashboard.html
        // (and any other page) can scope attendance queries to this
        // employee's own records only. Without this, buildScopeParams()
        // on the frontend always found currentUser.fingerprint_id undefined,
        // so Employees silently fell through to "no scope" → saw EVERYONE's
        // attendance data in DB mode (previous dates).
        if ($user->user_types_id == 5) {
            $extraData['fingerprint_id'] = $user->fingerprint_id ?? null;
        }

        return $extraData;
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Password mismatch'], 401);
        }

        // NOTE: previously, Company Admin's "name" was overwritten with the
        // company name here. That's kept as-is for backward compatibility
        // (in case other pages rely on `user.name` showing the company),
        // but the company name is now ALSO available separately as
        // `company_name` so it doesn't need to be inferred from `name`.
        $name      = $user->username;
        $extraData = $this->buildExtraData($user);

        if ($user->user_types_id == 2 && !empty($extraData['company_name'])) {
            $name = $extraData['company_name'];
        }

        $token = $user->createToken('user_token')->plainTextToken;

        return response()->json([
            'message'      => 'Login successful',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => array_merge([
                'id'           => $user->id,
                'name'         => $name,
                'email'        => $user->email,
                'username'     => $user->username,
                'user_types_id'=> $user->user_types_id,
                'user_types'   => $user->user_types_id == 1 ? 'Super Admin' : (
                    $user->user_types_id == 2 ? 'Company Admin' : (
                        $user->user_types_id == 3 ? 'Branch Admin' : (
                            $user->user_types_id == 4 ? 'Department Admin' : 'Employee'
                        )
                    )
                ),
            ], $extraData),
        ], 200);
    }

    public function user(Request $request)
    {
        $user = $request->user(); // Gets authenticated user from Sanctum token

        $name      = $user->username;
        $extraData = $this->buildExtraData($user);

        if ($user->user_types_id == 2 && !empty($extraData['company_name'])) {
            $name = $extraData['company_name'];
        }

        return response()->json([
            'user' => array_merge([
                'id'           => $user->id,
                'name'         => $name,
                'email'        => $user->email,
                'username'     => $user->username,
                'user_types_id'=> $user->user_types_id,
                'user_types'   => $user->user_types_id == 1 ? 'Super Admin' : (
                    $user->user_types_id == 2 ? 'Company Admin' : (
                        $user->user_types_id == 3 ? 'Branch Admin' : (
                            $user->user_types_id == 4 ? 'Department Admin' : 'Employee'
                        )
                    )
                ),
            ], $extraData),
        ], 200);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logout successful'], 200);
    }

    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:191|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json(['message' => 'Password reset link sent successfully.'], 200);
        }

        return response()->json(['message' => 'Unable to send password reset link. Please check the email address.'], 400);
    }

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token'    => 'required|string',
            'email'    => 'required|email|max:191|exists:users,email',
            'password' => 'required|string|min:6|max:191|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => \Illuminate\Support\Str::random(60),
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password has been reset successfully.'], 200);
        }

        return response()->json(['message' => 'Failed to reset password. The token may be invalid or expired.'], 400);
    }
}