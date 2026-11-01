<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('livestocks', function (Blueprint $table): void {
            if (! Schema::hasColumn('livestocks', 'deleted_at')) {
                $table->softDeletes();
            }
            if (! Schema::hasColumn('livestocks', 'deleted_by_uuid')) {
                $table->uuid('deleted_by_uuid')->nullable();
            }
            if (! Schema::hasColumn('livestocks', 'delete_reason')) {
                $table->text('delete_reason')->nullable();
            }
            if (! Schema::hasColumn('livestocks', 'restored_at')) {
                $table->timestamp('restored_at')->nullable();
            }
            if (! Schema::hasColumn('livestocks', 'restored_by_uuid')) {
                $table->uuid('restored_by_uuid')->nullable();
            }
            if (! Schema::hasColumn('livestocks', 'revision')) {
                $table->unsignedBigInteger('revision')->default(1);
            }
        });

        $indexes = collect(Schema::getIndexes('livestocks'));

        if (! $indexes->contains(fn (array $index): bool => in_array('deleted_at', $index['columns'], true))) {
            Schema::table('livestocks', function (Blueprint $table): void {
                $table->index('deleted_at');
            });
        }
    }

    public function down(): void
    {
        // Deletion and restoration metadata is retained to prevent history loss.
    }
};
