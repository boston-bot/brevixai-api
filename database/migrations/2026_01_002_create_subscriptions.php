<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('company_id')->primary();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->string('tier', 50)->default('starter'); // starter | growth | accounting
            $table->string('status', 50)->default('active');
            $table->string('stripe_customer_id', 255)->nullable();
            $table->string('stripe_subscription_id', 255)->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_tier_check CHECK (tier IN ('starter', 'growth', 'accounting'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
