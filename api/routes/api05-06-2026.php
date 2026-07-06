<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ManageUserController;
use App\Http\Controllers\Api\UserLogController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\GetdataController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\AttendanceReportController;


Route::post('/login', [AuthController::class, 'login'])->name('login');
// Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->name('password.email');
// Route::post('/password/reset', [AuthController::class, 'resetPassword'])->name('password.reset');
    Route::get('/getdata', [GetdataController::class, 'getdata']); 

Route::middleware('auth:sanctum')->group(function () {
    
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
   
    
    Route::get('/users',[UserController::class,'listUsersByAccessAndDepartmentOrDevice']);
    Route::get('/company-admins', [UserController::class, 'companyAdmins'])->name('company-admins.index'); // List company admins by super admin
    Route::get('/branch-admins', [UserController::class, 'branchAdmins'])->name('branch-admins.index'); // List branch admins by super admin and company admin

    Route::post('/companies',[CompanyController::class,'createCompanyWithAdmin'])->name('company.detail');//Add company and admin by superadmin.
    Route::get('/company', [CompanyController::class, 'index'])->name('company.index'); //  admin can view companies and company admins can view their assigned company
    Route::get('/company/{id}', [CompanyController::class, 'show'])->name('company.show');// Show company details of specific company for super admin
    Route::put('/company/{id}', [CompanyController::class, 'update'])->name('company.update');// Update specific company by super admin and company admin
    Route::delete('/company/{id}', [CompanyController::class, 'destroy'])->name('company.destroy');// Remove specific company by super admin

    Route::get('/branch', [BranchController::class, 'index'])->name('branch.index'); // list all branches for super admin and company branches for company admin
    Route::get('/branch/{id}', [BranchController::class, 'show'])->name('branch.show'); // Show details of specific branch for super admin and company admin if they belong to the same company
    Route::post('/branch', [BranchController::class, 'store'])->name('branch.store'); // Creates a new branch by super admin and company admin if they belong to the same company
    Route::put('/branch/{id}', [BranchController::class, 'update'])->name('branch.update'); // Update specific branch 
    Route::delete('/branch/{id}', [BranchController::class, 'destroy'])->name('branch.destroy'); // Remove specific branch

    Route::post('/manageusers', [ManageUserController::class, 'store'])->name('manageusers.store');     // Add user
    Route::put('/manageusers/{id}', [ManageUserController::class, 'update'])->name('manageusers.update'); // Update user
    Route::delete('/manageusers/{id}', [ManageUserController::class, 'destroy'])->name('manageusers.destroy'); // Remove user
    Route::get('/manageusers/fingerprint-ids', [ManageUserController::class, 'availableFingerprintIds']);//show available fingerprint id
    Route::get('/manageusers/check-scan-status', [ManageUserController::class, 'checkScanStatus']);// Check if fingerprint scan is in progress
    Route::post('/manageusers/assign-fingerprint', [ManageUserController::class, 'assignUserToFingerprint']);// Assign user to fingerprint ID
    Route::post('/manageusers/deselect-fingerprint', [ManageUserController::class, 'deselectUserForFingerprint']);// Deselect user for fingerprint enrollment
    Route::post('/manageusers/cancel-enrollment', [ManageUserController::class, 'cancelEnrollment']);// Cancel fingerprint enrollment


    Route::get('/logs', [UserLogController::class, 'index'])->name('logs.index'); // filter logs
    Route::get('/logs/today', [UserLogController::class, 'todayUsers'])->name('logs.today'); // Get today's users
    Route::get('/logs/today/active-count', [UserLogController::class, 'countTodayActiveUsers']);//count today's active users

    Route::get('/attendance-status', [AttendanceReportController::class, 'attendanceStatus']);//Attendence report 
   // Route::get('/attendance-export', [AttendanceReportController::class, 'exportAttendance']);


    Route::get('/devices/list', [DeviceController::class, 'list'])->name('list'); // List devices under the logged in admin
    Route::post('/devices/store', [DeviceController::class, 'store'])->name('store'); // Add device
    Route::delete('/devices/destroy', [DeviceController::class, 'destroy'])->name('destroy'); // Remove device
   // Route::post('/devices/regenerate-uid', [DeviceController::class, 'regenerateUID']);// Regenerate device UID
    Route::put('/devices/update-mode', [DeviceController::class, 'updateMode']); // Update device mode
    Route::put('/devices/update', [DeviceController::class, 'updateDetails']); // Update device details


    Route::post('/departments',[DepartmentController::class,'createDepartmentWithAdmin']);

    Route::put('/departments/{id}',[DepartmentController::class,'update']);
    Route::get('/departments', [DepartmentController::class, 'listDepartments'])->name('listDeviceDeps'); // List device departments
    //Route::post('/device-deps',[DepartmentController::class,'store'])->name('addDeviceDeps');//Add device depts
    //Route::delete('/departments/{id}',[DepartmentController::class,'destroy'])->name('deleteDeviceDeps');//Delete device deps

});

