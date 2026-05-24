<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->jsonb('reason_codes')->default('[]');
            $table->text('source_system')->nullable();
            $table->uuid('source_recommendation_id')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->jsonb('evidence_refs')->default('[]');
            $table->jsonb('comparison_window')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropColumn([
                'reason_codes',
                'source_system',
                'source_recommendation_id',
                'confidence_score',
                'evidence_refs',
                'comparison_window',
            ]);
        });
    }
};
