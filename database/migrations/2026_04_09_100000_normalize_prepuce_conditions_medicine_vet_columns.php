<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace duplicated free-text drug / vet / route fields with FKs and dose/quantity
     * aligned with dewormings / treatments.
     */
    public function up(): void
    {
        if (! Schema::hasTable('prepuce_conditions')) {
            return;
        }

        if (! Schema::hasColumn('prepuce_conditions', 'drugName')) {
            return;
        }

        Schema::table('prepuce_conditions', function (Blueprint $table) {
            $table->unsignedBigInteger('medicineId')->nullable()->index();
            $table->unsignedBigInteger('administrationRouteId')->nullable()->index();
            $table->string('vetId', 191)->nullable()->index();
            $table->string('quantity')->nullable();
            $table->string('dose')->nullable();
        });

        foreach (DB::table('prepuce_conditions')->orderBy('id')->cursor() as $row) {
            $suffix = '';
            if (! empty($row->drugName)) {
                $suffix .= "\n[Legacy] drug: {$row->drugName}";
            }
            if (! empty($row->duration)) {
                $suffix .= "\n[Legacy] duration: {$row->duration}";
            }
            if (! empty($row->route)) {
                $suffix .= "\n[Legacy] route code: {$row->route}";
            }
            if (! empty($row->vetName)) {
                $suffix .= "\n[Legacy] vet: {$row->vetName}";
            }
            if (! empty($row->vetContact)) {
                $suffix .= "\n[Legacy] vet contact: {$row->vetContact}";
            }
            $notes = ($row->notes ?? '').$suffix;
            $notes = trim($notes) === '' ? null : trim($notes);

            DB::table('prepuce_conditions')->where('id', $row->id)->update([
                'dose' => $row->dosage,
                'notes' => $notes,
            ]);
        }

        Schema::table('prepuce_conditions', function (Blueprint $table) {
            $table->dropColumn([
                'drugName',
                'dosage',
                'route',
                'duration',
                'vetName',
                'vetContact',
            ]);
        });

        Schema::table('prepuce_conditions', function (Blueprint $table) {
            $table->foreign('medicineId')->references('id')->on('medicines')->nullOnDelete();
            $table->foreign('administrationRouteId')->references('id')->on('administration_routes')->nullOnDelete();
        });

        if (Schema::hasTable('prepuce_condition_lookups')) {
            DB::table('prepuce_condition_lookups')->where('category', 'route')->delete();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('prepuce_conditions')) {
            return;
        }

        if (Schema::hasColumn('prepuce_conditions', 'drugName')) {
            return;
        }

        Schema::table('prepuce_conditions', function (Blueprint $table) {
            $table->dropForeign(['medicineId']);
            $table->dropForeign(['administrationRouteId']);
        });

        Schema::table('prepuce_conditions', function (Blueprint $table) {
            $table->string('drugName')->nullable();
            $table->string('dosage')->nullable();
            $table->string('route', 32)->nullable();
            $table->string('duration')->nullable();
            $table->string('vetName')->nullable();
            $table->string('vetContact')->nullable();
        });

        Schema::table('prepuce_conditions', function (Blueprint $table) {
            $table->dropColumn([
                'medicineId',
                'administrationRouteId',
                'vetId',
                'quantity',
                'dose',
            ]);
        });
    }
};
