<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_tier_check');

        // Fold every legacy paid tier into 'pro'. 'free' was previously
        // written by code but never allowed by the constraint — fix that too.
        DB::table('subscriptions')
            ->whereIn('tier', ['starter', 'growth', 'accounting', 'accounting-firm', 'risk-advisory'])
            ->update(['tier' => 'pro']);

        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_tier_check CHECK (tier IN ('free', 'pro'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_tier_check');
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_tier_check CHECK (tier IN ('starter', 'growth', 'accounting', 'risk-advisory'))");
    }
};
