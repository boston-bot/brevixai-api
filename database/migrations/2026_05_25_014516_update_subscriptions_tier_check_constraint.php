<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_tier_check');
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_tier_check CHECK (tier IN ('starter', 'growth', 'accounting', 'risk-advisory'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_tier_check');
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_tier_check CHECK (tier IN ('starter', 'growth', 'accounting'))");
    }
};
