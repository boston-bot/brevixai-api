<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('irm_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('irm_document_id')->constrained()->cascadeOnDelete();
            $table->string('xml_id')->nullable();     // id="" attribute from XML
            $table->string('irm_reference');          // e.g. "5.1.24.3.1"
            $table->unsignedTinyInteger('depth');     // 1=subsection1, 2=subsection2, 3=subsection3
            $table->string('title')->nullable();
            $table->date('effective_date')->nullable();
            $table->longText('body_text');            // plain text RAG chunk
            $table->index(['irm_document_id', 'depth']);
            $table->index('irm_reference');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('irm_sections');
    }
};
