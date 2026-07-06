<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ManageUserController;
use App\Http\Controllers\Api\UserLogController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\DeviceController;


Route::post('/adminlogin', [AdminController::class, 'login'])->name('login');
Route::post('/adminlogout', [AdminController::class, 'logout'])->name('logout');

Route::get('/users', [UserController::class, 'index'])->name('users.index');      // List users

Route::post('/manageusers', [ManageUserController::class, 'store'])->name('manageusers.store');     // Add user
Route::put('/manageusers/{id}', [ManageUserController::class, 'update'])->name('manageusers.update'); // Update user
Route::delete('/manageusers/{id}', [ManageUserController::class, 'destroy'])->name('manageusers.destroy'); // Remove user

Route::get('/logs', [UserLogController::class, 'index'])->name('logs.index'); // filter logs



Route::get('/devices/list', [DeviceController::class, 'list'])->name('list'); // List devices
Route::post('/devices/store', [DeviceController::class, 'store'])->name('store'); // Add device
Route::delete('/devices/destroy', [DeviceController::class, 'destroy'])->name('destroy'); // Remove device

Route::post('/attendance/start', [AttendanceController::class, 'login'])->name('attendance.login');
Route::get('/attendance/end', [AttendanceController::class, 'logout'])->name('attendance.logout');


Route::post('/login', [AuthController::class, 'login'])->name('login'); // User login
Route::post('/logout', [AuthController::class, 'logout'])->name('logout'); // User logout

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

