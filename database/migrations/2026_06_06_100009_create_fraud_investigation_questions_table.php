<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_investigation_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id')->index();
            $table->text('question');
            $table->string('asked_to')->nullable();
            $table->text('why_question_matters')->nullable();
            $table->string('priority')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_investigation_questions');
    }
};
