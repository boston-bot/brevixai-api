<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_expected_indicators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id')->index();
            $table->string('indicator_key');
            $table->string('indicator_name');
            $table->string('indicator_category')->nullable();
            $table->text('description')->nullable();
            $table->string('severity')->nullable();
            $table->json('data_needed')->nullable();
            $table->boolean('should_detect')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_expected_indicators');
    }
};
