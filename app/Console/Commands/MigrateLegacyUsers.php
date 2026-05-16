<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class MigrateLegacyUsers extends Command
{
    protected $signature = 'migrate:legacy-users';
    protected $description = 'Migrate users and companies from the legacy brevix database';

    public function handle()
    {
        $this->info('Starting migration from legacy "brevix" database...');

        try {
            // Connect to legacy database
            $sourcePdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=brevix", 'joe.eagan', '');
            $sourcePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\Exception $e) {
            $this->error('Failed to connect to legacy database: ' . $e->getMessage());
            return 1;
        }

        // 1. Migrate Companies
        $this->info('Migrating companies...');
        $companies = $sourcePdo->query("SELECT * FROM companies")->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($companies as $company) {
            DB::table('companies')->updateOrInsert(
                ['id' => $company['id']],
                [
                    'name' => $company['name'],
                    'industry' => $company['industry'] ?? null,
                    'size' => $company['size'] ?? null,
                    'website' => $company['website'] ?? null,
                    'entity_type' => $company['entity_type'] ?? null,
                    'has_completed_onboarding' => $company['has_completed_onboarding'] ?? false,
                    'created_at' => $company['created_at'],
                    'updated_at' => $company['updated_at'],
                ]
            );
        }
        $this->info('Migrated ' . count($companies) . ' companies.');

        // 2. Migrate Users
        $this->info('Migrating users...');
        $users = $sourcePdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($users as $user) {
            $passwordHash = $user['password_hash'];
            // Node.js bcrypt often uses $2b$ prefix, Laravel expects $2y$ 
            // They are functionally equivalent but Laravel's hasher is picky.
            if (str_starts_with($passwordHash, '$2b$')) {
                $passwordHash = str_replace('$2b$', '$2y$', $passwordHash);
            }

            DB::table('users')->updateOrInsert(
                ['id' => $user['id']],
                [
                    'company_id' => $user['company_id'],
                    'email' => $user['email'],
                    'password_hash' => $passwordHash,
                    'first_name' => $user['first_name'] ?? null,
                    'last_name' => $user['last_name'] ?? null,
                    'role' => $user['role'] ?? 'owner',
                    'is_verified' => $user['is_verified'] ?? false,
                    'last_login_at' => $user['last_login_at'] ?? null,
                    'created_at' => $user['created_at'],
                    'updated_at' => $user['updated_at'],
                ]
            );
        }
        $this->info('Migrated ' . count($users) . ' users.');

        // 3. Migrate Subscriptions
        $this->info('Migrating subscriptions...');
        $subscriptions = $sourcePdo->query("SELECT * FROM subscriptions")->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($subscriptions as $sub) {
            DB::table('subscriptions')->updateOrInsert(
                ['company_id' => $sub['company_id']],
                [
                    'tier' => $sub['tier'] ?? 'starter',
                    'status' => $sub['status'] ?? 'active',
                    'stripe_customer_id' => $sub['stripe_customer_id'] ?? null,
                    'stripe_subscription_id' => $sub['stripe_subscription_id'] ?? null,
                    'current_period_end' => $sub['current_period_end'] ?? null,
                    'updated_at' => $sub['updated_at'],
                ]
            );
        }
        $this->info('Migrated ' . count($subscriptions) . ' subscriptions.');

        $this->info('User migration complete!');
        return 0;
    }
}
