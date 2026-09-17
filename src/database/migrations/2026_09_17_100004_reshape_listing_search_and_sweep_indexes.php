<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Each listing index ends in the listing's full order, so a filtered page is read off the index with no sort;
        // lower() LIKE 'term%' can use only a text_pattern_ops expression index under the en_US.utf8 collation (database.md §5).
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_tenant_id_created_at_index');
            $table->dropIndex(['tenant_id', 'status']);
            $table->rawIndex('tenant_id, created_at DESC, id DESC', 'customers_tenant_id_created_at_id_index');
            $table->rawIndex('tenant_id, status, created_at DESC, id DESC', 'customers_tenant_id_status_created_at_id_index');
            $table->rawIndex('tenant_id, lower(name) text_pattern_ops', 'customers_tenant_id_lower_name_index');
            $table->rawIndex('tenant_id, lower(email) text_pattern_ops', 'customers_tenant_id_lower_email_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropIndex(['tenant_id', 'role']);
            $table->rawIndex('tenant_id, created_at DESC, id DESC', 'users_tenant_id_created_at_id_index');
            $table->rawIndex('tenant_id, role, created_at DESC, id DESC', 'users_tenant_id_role_created_at_id_index');
            $table->rawIndex('tenant_id, status, created_at DESC, id DESC', 'users_tenant_id_status_created_at_id_index');
            $table->rawIndex('tenant_id, lower(name) text_pattern_ops', 'users_tenant_id_lower_name_index');
            $table->rawIndex('tenant_id, lower(email) text_pattern_ops', 'users_tenant_id_lower_email_index');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->rawIndex('lower(name) text_pattern_ops', 'tenants_lower_name_index');
            $table->rawIndex('lower(slug) text_pattern_ops', 'tenants_lower_slug_index');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['status', 'ends_at']);
        });

        // Only canceled live rows carry an ends_at: history past its ends_at would make the planner expect due rows everywhere,
        // and every running subscription's null would only bloat an index the sweep's `ends_at <=` can never match through.
        DB::statement("CREATE INDEX subscriptions_active_ends_at_index ON subscriptions (ends_at) WHERE status = 'active' AND ends_at IS NOT NULL");

        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->index(['is_active', 'sort_order']);
        });

        DB::statement('DROP INDEX subscriptions_active_ends_at_index');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(['status', 'ends_at']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex('tenants_lower_slug_index');
            $table->dropIndex('tenants_lower_name_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_tenant_id_lower_email_index');
            $table->dropIndex('users_tenant_id_lower_name_index');
            $table->dropIndex('users_tenant_id_status_created_at_id_index');
            $table->dropIndex('users_tenant_id_role_created_at_id_index');
            $table->dropIndex('users_tenant_id_created_at_id_index');
            $table->index(['tenant_id', 'role']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_tenant_id_lower_email_index');
            $table->dropIndex('customers_tenant_id_lower_name_index');
            $table->dropIndex('customers_tenant_id_status_created_at_id_index');
            $table->dropIndex('customers_tenant_id_created_at_id_index');
            $table->index(['tenant_id', 'status']);
            $table->rawIndex('tenant_id, created_at DESC', 'customers_tenant_id_created_at_index');
        });
    }
};
