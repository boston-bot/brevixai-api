<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower((string) env('ADMIN_EMAIL', 'admin@admin.brevixai.com'));
        $password = (string) env('ADMIN_PASSWORD', '');

        if ($password === '') {
            $this->command?->warn('Skipping admin user seed because ADMIN_PASSWORD is not set.');

            return;
        }

        $company = Company::firstOrCreate(
            ['name' => env('ADMIN_COMPANY_NAME', 'Brevix Admin')],
            [
                'id' => (string) Str::uuid(),
                'has_completed_onboarding' => true,
            ],
        );

        Subscription::firstOrCreate(
            ['company_id' => $company->id],
            ['tier' => 'accounting', 'status' => 'active'],
        );

        $user = User::firstOrNew(['email' => $email]);
        if (! $user->exists) {
            $user->id = (string) Str::uuid();
        }

        $user->fill([
            'company_id' => $company->id,
            'password_hash' => Hash::make($password),
            'first_name' => 'Brevix',
            'last_name' => 'Admin',
            'role' => 'admin',
            'is_verified' => true,
        ]);
        $user->save();
    }
}
