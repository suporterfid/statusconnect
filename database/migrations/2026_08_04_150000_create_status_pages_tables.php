<?php

// Ported / mirrored from TaskConnect: database/migrations/2026_07_22_250000_add_submit_rate_limit_buckets.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 120)->unique();
            $table->string('title', 255);
            $table->string('visibility', 16)->default('public');
            $table->string('locale', 8)->default('en');
            $table->string('timezone', 64)->default('UTC');
            $table->timestamps();

            $table->index(['environment_id', 'visibility']);
        });

        Schema::create('status_page_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status_page_id', 'sort_order']);
        });

        Schema::create('status_page_component_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_page_component_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['status_page_component_id', 'monitor_id']);
        });

        Schema::create('status_page_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_page_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('payload_json');
            $table->string('etag', 64);
            $table->timestamp('generated_at');
            $table->timestamps();
        });

        Schema::create('rate_limit_buckets', function (Blueprint $table) {
            $table->id();
            $table->string('bucket_key', 191)->unique();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('resets_at');
            $table->timestamps();

            $table->index('resets_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limit_buckets');
        Schema::dropIfExists('status_page_cache');
        Schema::dropIfExists('status_page_component_monitors');
        Schema::dropIfExists('status_page_components');
        Schema::dropIfExists('status_pages');
    }
};
