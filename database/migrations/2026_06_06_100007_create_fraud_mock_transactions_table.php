<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_mock_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scenario_submission_id')->index();
            $table->uuid('mock_company_id')->nullable()->index();
            $table->string('external_transaction_id')->nullable();
            $table->string('transaction_type');
            $table->date('transaction_date')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->uuid('party_id')->nullable();
            $table->string('account_category')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_fraudulent')->default(false);
            $table->string('fraud_pattern')->nullable();
            $table->string('expected_brevix_signal')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_mock_transactions');
    }
};
