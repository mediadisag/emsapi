<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CompanyService
{
    public function createCompanyWithAdmin(array $data)
    {
        DB::beginTransaction();

        try {
            // Create company
            $company = Company::create([
                'name'    => $data['name'],
                'email'   => $data['email'],
                'address' => $data['address'],
                'phone'   => $data['phone'],
                'website' => $data['website'] ?? null,
            ]);

            // Create admin user
            $admin = User::create([
                'username'     => $data['username'],
                'email'        => $data['email_user'],
                'password'     => bcrypt($data['password']),
                'gender'       => $data['gender'],
                'serialnumber' => $data['serialnumber'],
                'device_uid'   => $data['device_uid'],
                'device_dep'   => $data['device_dep'],
                'user_types_id'=> 2, // Company Admin
                'user_date'    => now(),
            ]);

            // Attach admin to company
            $company->users()->attach($admin->id);

            DB::commit();

            return ['company' => $company, 'admin' => $admin];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
