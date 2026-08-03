<?php

// Ported / mirrored from TaskConnect: database/migrations/2026_07_18_160000_phase1_security_config_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secrets', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('encrypted_payload');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->timestamp('archived_at')->nullable();

            $table->unique(['environment_id', 'name']);
            $table->index(['tenant_id', 'environment_id']);
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('key_prefix', 8);
            $table->string('key_hash', 64);
            $table->json('permissions');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at']);
            $table->index('key_prefix');
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key');
            $table->string('route');
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_code');
            $table->json('response_body');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at');

            $table->unique(['tenant_id', 'key', 'route']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('secrets');
    }
};
