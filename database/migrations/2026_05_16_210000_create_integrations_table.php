<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('integrations')) {
            return;
        }

        Schema::create('integrations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('realm_id', 255)->nullable();
            $table->text('access_token_enc')->nullable();
            $table->text('refresh_token_enc')->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->text('client_id_enc')->nullable();
            $table->text('client_secret_enc')->nullable();
            $table->string('environment', 50)->nullable();
            $table->string('sync_status', 50)->nullable();
            $table->unsignedSmallInteger('sync_progress')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'provider', 'realm_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
