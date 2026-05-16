<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gnucash_imports', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('filename');
            $table->string('file_format'); // 'sqlite' | 'csv'
            $table->string('status')->default('completed'); // 'pending' | 'completed' | 'failed'
            $table->integer('transaction_count')->default(0);
            $table->integer('account_count')->default(0);
            $table->date('date_range_start')->nullable();
            $table->date('date_range_end')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('gnucash_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('import_id')->constrained('gnucash_imports')->cascadeOnDelete();
            $table->string('name');
            $table->string('full_name');
            $table->string('account_type');
            $table->string('commodity')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::table('gnucash_transactions', function (Blueprint $table) {
            $table->foreignUuid('import_id')->nullable()->constrained('gnucash_imports')->cascadeOnDelete();
        });

        // Indexes
        DB::statement('CREATE INDEX idx_gnucash_imports_company ON gnucash_imports(company_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::table('gnucash_transactions', function (Blueprint $table) {
            $table->dropColumn('import_id');
        });
        Schema::dropIfExists('gnucash_accounts');
        Schema::dropIfExists('gnucash_imports');
    }
};
