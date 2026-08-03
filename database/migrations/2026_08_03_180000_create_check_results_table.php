<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_results', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained('environments')->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();

            $table->string('state', 32);
            $table->integer('latency_ms')->default(0);
            $table->integer('status_code')->nullable();
            $table->text('failure_reason')->nullable();
            $table->text('failure_excerpt')->nullable();
            $table->timestamp('checked_at');
            $table->timestamp('created_at')->nullable();

            // Required index (§13.4)
            $table->index(['monitor_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_results');
    }
};
