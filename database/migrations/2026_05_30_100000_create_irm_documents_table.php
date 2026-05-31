<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('irm_documents', function (Blueprint $table) {
            $table->id();
            $table->string('irm_reference')->unique(); // e.g. "5.1.24"
            $table->unsignedSmallInteger('part_number');
            $table->unsignedSmallInteger('chapter_number');
            $table->unsignedSmallInteger('section_number');
            $table->string('title');
            $table->string('catalog_number')->nullable();
            $table->date('effective_date')->nullable();
            $table->string('audience')->nullable();
            $table->string('s3_key');
            $table->string('file_hash', 64)->nullable(); // SHA-256 hex
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('irm_documents');
    }
};
