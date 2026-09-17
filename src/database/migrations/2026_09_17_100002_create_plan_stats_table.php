<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plan_stats', function (Blueprint $table) {
            $table->foreignId('plan_id')->primary()->constrained()->cascadeOnDelete();
            $table->bigInteger('tenants_count')->default(0);
            $table->bigInteger('mrr_cents')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_stats');
    }
};
