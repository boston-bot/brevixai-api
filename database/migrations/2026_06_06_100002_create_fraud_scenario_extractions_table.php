<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_scenario_extractions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id')->index();
            $table->string('fraud_category')->nullable();
            $table->string('industry')->nullable();
            $table->string('actor_type')->nullable();
            $table->string('concealment_method')->nullable();
            $table->text('summary')->nullable();
            $table->json('structured_payload');
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('model_name')->nullable();
            $table->string('prompt_version')->nullable();
            $table->json('extraction_errors')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_scenario_extractions');
    }
};
