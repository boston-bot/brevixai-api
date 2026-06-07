<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_scenario_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('original_filename')->nullable();
            $table->string('storage_path')->nullable();
            $table->uuid('uploaded_by_id')->nullable();
            $table->string('status')->default('uploaded');
            $table->integer('total_rows')->default(0);
            $table->integer('successful_rows')->default(0);
            $table->integer('failed_rows')->default(0);
            $table->json('validation_errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_scenario_imports');
    }
};
