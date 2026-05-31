<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('disk', 64);
            $table->string('path');
            $table->string('url')->nullable();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('original_filename');
            $table->foreignUuid('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });

        Schema::create('site_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key')->unique()->default('default');
            $table->json('draft_payload');
            $table->json('published_payload')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('site_pages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('title');
            $table->json('draft_payload');
            $table->json('published_payload')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('site_articles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('status', 24)->default('draft')->index();
            $table->string('title');
            $table->string('category', 120);
            $table->text('description');
            $table->string('badge', 80)->nullable();
            $table->string('read_time', 60)->nullable();
            $table->string('accent_color', 7)->nullable();
            $table->integer('sort_order')->default(0)->index();
            $table->json('draft_payload');
            $table->json('published_payload')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('removed_at')->nullable();
            $table->foreignUuid('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('site_content_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('content_type', 24)->index();
            $table->uuid('content_id')->index();
            $table->string('event', 32)->index();
            $table->json('payload')->nullable();
            $table->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_content_revisions');
        Schema::dropIfExists('site_articles');
        Schema::dropIfExists('site_pages');
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('site_assets');
    }
};
