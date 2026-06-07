<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_mock_companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id')->index();
            $table->string('company_name');
            $table->string('industry')->nullable();
            $table->string('entity_type')->nullable();
            $table->decimal('annual_revenue', 15, 2)->nullable();
            $table->integer('employee_count')->nullable();
            $table->integer('vendor_count')->nullable();
            $table->integer('customer_count')->nullable();
            $table->integer('months_of_activity')->nullable();
            $table->json('profile_payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_mock_companies');
    }
};
