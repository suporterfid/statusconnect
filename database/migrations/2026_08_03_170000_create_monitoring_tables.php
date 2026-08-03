<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained('environments')->cascadeOnDelete();

            $table->string('name', 255);
            $table->string('kind', 32)->default('http');
            $table->string('target', 1024);
            $table->string('http_method', 16)->default('GET');
            $table->json('request_headers_json')->nullable();
            $table->text('request_body')->nullable();

            $table->integer('interval_seconds')->default(60);
            $table->integer('timeout_ms')->default(10000);
            $table->integer('confirmation_threshold')->default(2);
            $table->integer('recovery_threshold')->default(2);
            $table->boolean('follow_redirects')->default(true);
            $table->boolean('verify_tls')->default(true);
            $table->string('egress_profile', 32)->default('internal');

            $table->boolean('public_safe')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamp('paused_at')->nullable();

            $table->string('current_state', 32)->default('up');
            $table->integer('consecutive_failures')->default(0);
            $table->integer('consecutive_successes')->default(0);

            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->integer('last_latency_ms')->nullable();
            $table->timestamp('tls_expires_at')->nullable();

            $table->string('heartbeat_token', 64)->nullable()->unique();
            $table->integer('heartbeat_grace_seconds')->default(300);
            $table->timestamp('last_ping_at')->nullable();

            $table->timestamp('flapping_since')->nullable();
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();

            $table->timestamps();

            // Required indexes (§13.4)
            $table->index(['enabled', 'next_check_at']);
            $table->index(['claim_expires_at']);
            $table->index(['environment_id', 'current_state']);
        });

        Schema::create('monitor_assertions', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();

            $table->string('type', 32);
            $table->string('operator', 32);
            $table->string('target_property', 255)->nullable();
            $table->text('expected_value')->nullable();
            $table->boolean('case_sensitive')->default(false);
            $table->integer('order')->default(0);

            $table->timestamps();

            $table->index(['monitor_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_assertions');
        Schema::dropIfExists('monitors');
    }
};
