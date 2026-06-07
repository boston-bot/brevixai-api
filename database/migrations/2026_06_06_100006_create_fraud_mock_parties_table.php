<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_mock_parties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id')->index();
            $table->uuid('mock_company_id')->nullable()->index();
            $table->string('external_party_id')->nullable();
            $table->string('party_type');
            $table->string('party_name');
            $table->string('role')->nullable();
            $table->boolean('is_fraud_actor')->default(false);
            $table->boolean('is_related_party')->default(false);
            $table->json('attributes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_mock_parties');
    }
};
