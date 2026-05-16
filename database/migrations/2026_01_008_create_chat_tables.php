<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // -- Chat Sessions --
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title')->default('New Chat with Rex');
            $table->timestampsTz();
        });

        // -- Chat Messages --
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('session_id')->constrained('chat_sessions')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('role'); // user, assistant, system
            $table->text('content');
            $table->jsonb('structured_payload')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        // -- Chat Usage Daily --
        Schema::create('chat_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->date('date');
            $table->integer('message_count')->default(0);
            $table->timestampsTz();

            $table->unique(['company_id', 'date']);
        });

        // -- Rex Pending Actions --
        Schema::create('rex_pending_actions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('session_id')->constrained('chat_sessions')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('action_type');
            $table->jsonb('preview')->default('{}');
            $table->string('status')->default('pending'); // pending, confirmed, rejected
            $table->foreignUuid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rex_pending_actions');
        Schema::dropIfExists('chat_usage_daily');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_sessions');
    }
};
