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
        Schema::create('platform_stats', function (Blueprint $table) {
            $table->smallInteger('id')->primary();
            $table->bigInteger('tenants_count')->default(0);
            $table->bigInteger('suspended_tenants_count')->default(0);
            $table->bigInteger('users_count')->default(0);
            $table->bigInteger('customers_count')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_stats');
    }
};
