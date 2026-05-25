<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('channel'); // slack | email | sms
            $table->jsonb('config')->default('{}'); // channel-specific: webhook_url, email, phone
            $table->jsonb('events')->default('[]'); // alert_created | case_created | recommendation_approved
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_configs');
    }
};
