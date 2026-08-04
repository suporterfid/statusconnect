<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->integer('confirmation_threshold')->default(3)->change();
            $table->string('current_state', 32)->default('pending')->change();
            $table->timestamp('first_failure_at')->nullable()->after('consecutive_successes');
            $table->timestamp('flap_notification_window_started_at')->nullable()->after('flapping_since');
        });

        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->index('tenant_id');
            $table->dropUnique(['tenant_id', 'key', 'route']);
            $table->unique(['environment_id', 'key', 'route']);
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained('environments')->cascadeOnDelete();
            $table->foreignId('monitor_id')->nullable()->constrained('monitors')->nullOnDelete();
            $table->boolean('manual')->default(false);
            // false marks the one open incident; NULL releases the unique key on resolution.
            $table->boolean('resolved_flag')->nullable()->default(false);
            $table->timestamp('started_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('severity', 32);
            $table->text('summary');
            $table->timestamps();

            $table->unique(['monitor_id', 'resolved_flag']);
            $table->index(['environment_id', 'started_at']);
        });

        Schema::create('incident_updates', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('status', 32)->nullable();
            $table->text('message');
            $table->timestamp('published_at');
            $table->timestamps();

            $table->index(['incident_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_updates');
        Schema::dropIfExists('incidents');

        Schema::table('monitors', function (Blueprint $table) {
            $table->dropColumn('flap_notification_window_started_at');
            $table->dropColumn('first_failure_at');
        });

        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropUnique(['environment_id', 'key', 'route']);
            $table->unique(['tenant_id', 'key', 'route']);
            $table->dropIndex(['tenant_id']);
        });
    }
};
