<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('users', ['role', 'status', 'created_at'], 'users_admin_filter_index');
        $this->addIndex('users', ['role', 'roleId'], 'users_role_profile_index');
        $this->addIndex('farms', ['status', 'created_at'], 'farms_status_created_index');
        $this->addIndex('farmers', ['status', 'created_at'], 'farmers_status_created_index');
        $this->addIndex('extension_officers', ['isVerified', 'created_at'], 'extension_officers_verified_created_index');
        $this->addIndex('farm_users', ['status', 'created_at'], 'farm_users_status_created_index');
        $this->addIndex('system_users', ['status', 'created_at'], 'system_users_status_created_index');
        $this->addIndex('event_records', ['record_status', 'occurred_at'], 'event_records_status_occurred_index');
        $this->addIndex('event_records', ['event_code', 'record_status', 'occurred_at'], 'event_records_code_status_occurred_index');
        $this->addIndex('animal_subjects', ['deleted_at', 'created_at'], 'animal_subjects_deleted_created_index');
        $this->addIndex('milkings', ['eventDate'], 'milkings_event_date_index');
    }

    public function down(): void
    {
        $this->dropIndex('users', 'users_admin_filter_index');
        $this->dropIndex('users', 'users_role_profile_index');
        $this->dropIndex('farms', 'farms_status_created_index');
        $this->dropIndex('farmers', 'farmers_status_created_index');
        $this->dropIndex('extension_officers', 'extension_officers_verified_created_index');
        $this->dropIndex('farm_users', 'farm_users_status_created_index');
        $this->dropIndex('system_users', 'system_users_status_created_index');
        $this->dropIndex('event_records', 'event_records_status_occurred_index');
        $this->dropIndex('event_records', 'event_records_code_status_occurred_index');
        $this->dropIndex('animal_subjects', 'animal_subjects_deleted_created_index');
        $this->dropIndex('milkings', 'milkings_event_date_index');
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
            return;
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
            return;
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
    }
};

