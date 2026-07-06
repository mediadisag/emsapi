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
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Password mismatch'], 401);
        }

        $name = $user->username;
        // // If Company Admin, replace name with company name
        if ($user->user_types_id == 2) {
            $company = DB::table('company_users')
            ->join('company', 'company.id', '=', 'company_users.company_id')
            ->where('company_users.users_id', $user->id)
            ->select('company.name')
            ->first();

            if ($company && isset($company->name)) {
                $name = $company->name;
            }

        }
        $token = $user->createToken('user_token')->plainTextToken;
        return response()->json([
            'message' => 'Login successful',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $name,//company nam
                'email' => $user->email,
                'username' => $user->username,
                'user_types_id' => $user->user_types_id,
                'user_types' => $user->user_types_id == 1 ? 'Super Admin' : ($user->user_types_id == 2 
                 ? 'Company Admin' : ($user->user_types_id == 3  ? 'Branch Admin' : 'Employee')),

            ],
        ], 200);
 }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json([
            'message' => 'Logout successful',
        ], 200);
    }  


    
   public function forgotPassword(Request $request)
    {
    // Validate the email input
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:191|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

    // Send password reset link
        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => 'Password reset link sent successfully.',
            ], 200);
        }

        return response()->json([
            'message' => 'Unable to send password reset link. Please check the email address.',
        ], 400);    
    }


    public function resetPassword(Request $request)
    {
    // Validate the reset request
         $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|email|max:191|exists:users,email',
            'password' => 'required|string|min:6|max:191|confirmed',
        ]);

         if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

    // Attempt to reset the password
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Password has been reset successfully.',
            ], 200);
        }

        return response()->json([
            'message' => 'Failed to reset password. The token may be invalid or expired.',
        ], 400);
    }
}