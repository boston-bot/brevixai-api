<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('playbook_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // ACFE, IRS, Brevix, etc.
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('investigation_playbooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained('playbook_sources')->nullOnDelete();
            $table->string('title');
            $table->string('category'); // e.g., 'payroll', 'expense', 'billing'
            $table->text('description')->nullable();
            $table->json('symptoms')->nullable(); // JSON array of symptoms
            $table->json('red_flags')->nullable(); // JSON array of red flags
            $table->json('tests')->nullable(); // Recommended tests
            $table->json('document_requests')->nullable(); // Documents to request
            $table->string('intent_key')->nullable(); // e.g., 'fraud_discovery'
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('playbook_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playbook_id')->constrained('investigation_playbooks')->cascadeOnDelete();
            $table->string('version_number');
            $table->json('content_snapshot');
            $table->timestamps();
        });

        Schema::create('playbook_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_playbook_id')->constrained('investigation_playbooks')->cascadeOnDelete();
            $table->foreignId('target_playbook_id')->constrained('investigation_playbooks')->cascadeOnDelete();
            $table->string('relationship_type'); // e.g., 'related_to', 'prerequisite_of'
            $table->timestamps();
        });

        Schema::create('playbook_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playbook_id')->constrained('investigation_playbooks')->cascadeOnDelete();
            $table->string('model_name')->default('text-embedding-3-small');
            $table->json('embedding');
            $table->text('content_chunk')->nullable();
            $table->timestamps();
        });

        Schema::create('retrieval_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playbook_id')->constrained('investigation_playbooks')->cascadeOnDelete();
            $table->string('query_text');
            $table->integer('relevance_score'); // e.g., 1 to 5, or 0/1
            $table->text('user_feedback')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retrieval_feedback');
        Schema::dropIfExists('playbook_embeddings');
        Schema::dropIfExists('playbook_relationships');
        Schema::dropIfExists('playbook_versions');
        Schema::dropIfExists('investigation_playbooks');
        Schema::dropIfExists('playbook_sources');
    }
};
