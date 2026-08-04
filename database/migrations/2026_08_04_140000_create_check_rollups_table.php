<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            $table->timestamp('bucket_start');
            $table->string('bucket_kind', 8);
            $table->unsignedInteger('up_count')->default(0);
            $table->unsignedInteger('degraded_count')->default(0);
            $table->unsignedInteger('down_count')->default(0);
            $table->unsignedInteger('blocked_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('no_data_count')->default(0);
            $table->unsignedBigInteger('downtime_seconds')->default(0);
            $table->unsignedInteger('checks_total')->default(0);
            $table->unsignedInteger('latency_min_ms')->nullable();
            $table->unsignedInteger('latency_avg_ms')->nullable();
            $table->unsignedInteger('latency_p50_ms')->nullable();
            $table->unsignedInteger('latency_p95_ms')->nullable();
            $table->unsignedInteger('latency_max_ms')->nullable();
            $table->timestamps();
            $table->unique(['monitor_id', 'bucket_start', 'bucket_kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_rollups');
    }
};
