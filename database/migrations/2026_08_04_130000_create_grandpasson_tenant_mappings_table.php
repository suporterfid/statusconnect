<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grandpasson_tenant_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->string('broker_tenant_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->json('role_mappings');
            $table->json('group_mappings');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grandpasson_tenant_mappings');
    }
};
