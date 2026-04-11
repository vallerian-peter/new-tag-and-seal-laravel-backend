<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Align with dewormings / vaccinations: treatment provider is vetId OR extensionOfficerId (license),
     * not separate reported_by / attended_by lookup codes.
     */
    public function up(): void
    {
        if (! Schema::hasTable('prepuce_conditions')) {
            return;
        }

        if (! Schema::hasColumn('prepuce_conditions', 'reportedBy')) {
            if (! Schema::hasColumn('prepuce_conditions', 'extensionOfficerId')) {
                Schema::table('prepuce_conditions', function (Blueprint $table) {
                    $table->string('extensionOfficerId', 191)->nullable()->index()->after('vetId');
                });
            }

            return;
        }

        Schema::table('prepuce_conditions', function (Blueprint $table) {
            $table->string('extensionOfficerId', 191)->nullable()->index()->after('vetId');
        });

        foreach (DB::table('prepuce_conditions')->orderBy('id')->cursor() as $row) {
            $suffix = '';
            if (! empty($row->reportedBy)) {
                $suffix .= "\n[Legacy] reported_by: {$row->reportedBy}";
            }
            if (! empty($row->attendedBy)) {
                $suffix .= "\n[Legacy] attended_by: {$row->attendedBy}";
            }
            if ($suffix === '') {
                continue;
            }
            $notes = ($row->notes ?? '').$suffix;
            $notes = trim($notes) === '' ? null : trim($notes);
            DB::table('prepuce_conditions')->where('id', $row->id)->update(['notes' => $notes]);
        }

        Schema::table('prepuce_conditions', function (Blueprint $table) {
            $table->dropColumn(['reportedBy', 'attendedBy']);
        });

        if (Schema::hasTable('prepuce_condition_lookups')) {
            DB::table('prepuce_condition_lookups')
                ->whereIn('category', ['reported_by', 'attended_by'])
                ->delete();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('prepuce_conditions')) {
            return;
        }

        if (Schema::hasColumn('prepuce_conditions', 'reportedBy')) {
            return;
        }

        Schema::table('prepuce_conditions', function (Blueprint $table) {
            $table->string('reportedBy', 32)->default('farmer');
            $table->string('attendedBy', 32)->nullable();
        });

        if (Schema::hasColumn('prepuce_conditions', 'extensionOfficerId')) {
            Schema::table('prepuce_conditions', function (Blueprint $table) {
                $table->dropColumn('extensionOfficerId');
            });
        }
    }
};
