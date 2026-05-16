<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->uuid('transaction_id');
            $table->foreignUuid('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['company_id', 'transaction_id']);
        });

        DB::statement('CREATE INDEX idx_transaction_reviews_company ON transaction_reviews(company_id)');
        DB::statement('CREATE INDEX idx_transaction_reviews_transaction ON transaction_reviews(transaction_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_reviews');
    }
};
