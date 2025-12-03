<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Updates farmUuid column to TEXT to support JSON array of multiple farm UUIDs.
     * Converts existing single UUID values to JSON array format.
     */
    public function up(): void
    {
        // Change column type to TEXT to support JSON arrays
        Schema::table('farm_users', function (Blueprint $table) {
            // Drop the index first
            $table->dropIndex(['farmUuid']);
        });

        // Change column type to TEXT
        DB::statement('ALTER TABLE farm_users MODIFY farmUuid TEXT');

        // Convert existing single UUID values to JSON array format
        $farmUsers = DB::table('farm_users')->get();
        foreach ($farmUsers as $farmUser) {
            if (!empty($farmUser->farmUuid)) {
                // Check if already JSON array
                $decoded = json_decode($farmUser->farmUuid, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    // Already JSON, skip
                    continue;
                }
                
                // Convert single UUID to JSON array
                DB::table('farm_users')
                    ->where('id', $farmUser->id)
                    ->update([
                        'farmUuid' => json_encode([$farmUser->farmUuid])
                    ]);
            }
        }

        // Note: We don't recreate the index since TEXT columns can't be indexed efficiently
        // For querying, we'll use JSON functions instead
    }

    /**
     * Reverse the migrations.
     * 
     * Reverts farmUuid back to string (single UUID).
     * Takes the first UUID from JSON array if multiple exist.
     */
    public function down(): void
    {
        // Convert JSON arrays back to single UUID (take first one)
        $farmUsers = DB::table('farm_users')->get();
        foreach ($farmUsers as $farmUser) {
            if (!empty($farmUser->farmUuid)) {
                $decoded = json_decode($farmUser->farmUuid, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && !empty($decoded)) {
                    // Extract first UUID from array
                    DB::table('farm_users')
                        ->where('id', $farmUser->id)
                        ->update([
                            'farmUuid' => $decoded[0]
                        ]);
                }
            }
        }

        // Change back to string type
        Schema::table('farm_users', function (Blueprint $table) {
            DB::statement('ALTER TABLE farm_users MODIFY farmUuid VARCHAR(255)');
            $table->index('farmUuid');
        });
    }
};

