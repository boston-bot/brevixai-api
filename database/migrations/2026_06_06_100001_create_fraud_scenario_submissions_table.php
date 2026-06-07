<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_scenario_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('import_id')->nullable()->index();
            $table->string('external_scenario_id')->nullable()->index();
            $table->string('title');
            $table->text('narrative');
            $table->string('source')->nullable();
            $table->string('severity')->nullable();
            $table->string('status')->default('imported');
            $table->string('extraction_status')->default('pending');
            $table->string('mock_data_status')->default('pending');
            $table->string('review_status')->default('unreviewed');
            $table->integer('row_number')->nullable();
            $table->json('raw_row')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_scenario_submissions');
    }
};
