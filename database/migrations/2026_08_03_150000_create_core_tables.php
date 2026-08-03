<?php

// Ported / mirrored from TaskConnect: database/migrations/2026_07_18_150000_phase0_core_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
            $table->timestamp('archived_at')->nullable();
        });

        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('environments', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
            $table->timestamp('archived_at')->nullable();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('locale')->default('en');
            $table->string('timezone')->default('UTC');
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('resource_type');
            $table->string('resource_id')->nullable();
            $table->string('request_id')->nullable();
            $table->json('summary_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('environments');
        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('tenants');
    }
};
