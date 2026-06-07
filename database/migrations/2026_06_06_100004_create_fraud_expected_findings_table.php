<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_expected_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id')->index();
            $table->string('finding_key');
            $table->string('finding_title');
            $table->text('finding_description');
            $table->integer('expected_risk_score')->nullable();
            $table->string('expected_confidence')->nullable();
            $table->text('recommended_action')->nullable();
            $table->text('expected_user_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_expected_findings');
    }
};
