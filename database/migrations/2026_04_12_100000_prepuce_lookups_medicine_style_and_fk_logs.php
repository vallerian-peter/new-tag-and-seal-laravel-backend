<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LOOKUP_TABLES = [
        'prepuce_condition_types',
        'prepuce_severities',
        'prepuce_clinical_signs',
        'prepuce_cause_risks',
        'prepuce_treatments_given',
        'prepuce_breeding_statuses',
        'prepuce_healing_statuses',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('prepuce_conditions')) {
            return;
        }

        Schema::table('prepuce_conditions', function (Blueprint $table) {
            if (! Schema::hasColumn('prepuce_conditions', 'conditionTypeId')) {
                $table->unsignedBigInteger('conditionTypeId')->nullable()->after('livestockUuid');
            }
            if (! Schema::hasColumn('prepuce_conditions', 'severityId')) {
                $table->unsignedBigInteger('severityId')->nullable()->after('conditionTypeId');
            }
            if (! Schema::hasColumn('prepuce_conditions', 'clinicalSignIds')) {
                $table->json('clinicalSignIds')->nullable()->after('severityId');
            }
            if (! Schema::hasColumn('prepuce_conditions', 'causeRiskId')) {
                $table->unsignedBigInteger('causeRiskId')->nullable()->after('clinicalSignIds');
            }
            if (! Schema::hasColumn('prepuce_conditions', 'treatmentGivenIds')) {
                $table->json('treatmentGivenIds')->nullable()->after('causeRiskId');
            }
            if (! Schema::hasColumn('prepuce_conditions', 'breedingStatusId')) {
                $table->unsignedBigInteger('breedingStatusId')->nullable()->after('dose');
            }
            if (! Schema::hasColumn('prepuce_conditions', 'healingStatusId')) {
                $table->unsignedBigInteger('healingStatusId')->nullable()->after('breedingStatusId');
            }
        });

        if (Schema::hasColumn('prepuce_condition_types', 'code')) {
            $this->backfillPrepuceConditionFksUsingLookupCodes();
            $this->fillRequiredPrepuceConditionFks();
        }

        Schema::table('prepuce_conditions', function (Blueprint $table) {
            $drops = [];
            foreach (['conditionType', 'severity', 'clinicalSigns', 'causeRisk', 'treatmentGiven', 'breedingStatus', 'healingStatus'] as $col) {
                if (Schema::hasColumn('prepuce_conditions', $col)) {
                    $drops[] = $col;
                }
            }
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });

        Schema::table('prepuce_conditions', function (Blueprint $table) {
            $table->foreign('conditionTypeId')->references('id')->on('prepuce_condition_types')->restrictOnDelete();
            $table->foreign('severityId')->references('id')->on('prepuce_severities')->restrictOnDelete();
            $table->foreign('causeRiskId')->references('id')->on('prepuce_cause_risks')->nullOnDelete();
            $table->foreign('breedingStatusId')->references('id')->on('prepuce_breeding_statuses')->restrictOnDelete();
            $table->foreign('healingStatusId')->references('id')->on('prepuce_healing_statuses')->nullOnDelete();
        });

        $this->promoteLookupTablesToNameColumns();
    }

    public function down(): void
    {
        throw new RuntimeException('This migration cannot be safely reversed.');
    }

    private function backfillPrepuceConditionFksUsingLookupCodes(): void
    {
        DB::table('prepuce_conditions')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $updates = [];

                if (! empty($row->conditionType)) {
                    $updates['conditionTypeId'] = DB::table('prepuce_condition_types')
                        ->where('code', $row->conditionType)->value('id');
                }
                if (! empty($row->severity)) {
                    $updates['severityId'] = DB::table('prepuce_severities')
                        ->where('code', $row->severity)->value('id');
                }
                if (! empty($row->causeRisk)) {
                    $updates['causeRiskId'] = DB::table('prepuce_cause_risks')
                        ->where('code', $row->causeRisk)->value('id');
                }
                if (! empty($row->breedingStatus)) {
                    $updates['breedingStatusId'] = DB::table('prepuce_breeding_statuses')
                        ->where('code', $row->breedingStatus)->value('id');
                }
                if (! empty($row->healingStatus)) {
                    $updates['healingStatusId'] = DB::table('prepuce_healing_statuses')
                        ->where('code', $row->healingStatus)->value('id');
                }
                $signIds = $this->mapCodesToIds($row->clinicalSigns ?? null, 'prepuce_clinical_signs');
                if ($signIds !== []) {
                    $updates['clinicalSignIds'] = json_encode($signIds);
                }
                $treatIds = $this->mapCodesToIds($row->treatmentGiven ?? null, 'prepuce_treatments_given');
                if ($treatIds !== []) {
                    $updates['treatmentGivenIds'] = json_encode($treatIds);
                }

                if ($updates !== []) {
                    DB::table('prepuce_conditions')->where('id', $row->id)->update(
                        array_merge($updates, ['updated_at' => now()])
                    );
                }
            }
        });
    }

    private function fillRequiredPrepuceConditionFks(): void
    {
        $otherCt = DB::table('prepuce_condition_types')->where('code', 'other')->value('id');
        if ($otherCt) {
            DB::table('prepuce_conditions')->whereNull('conditionTypeId')->update(['conditionTypeId' => $otherCt]);
        }

        $mild = DB::table('prepuce_severities')->where('code', 'mild')->value('id');
        if ($mild) {
            DB::table('prepuce_conditions')->whereNull('severityId')->update(['severityId' => $mild]);
        }

        $breeder = DB::table('prepuce_breeding_statuses')->where('code', 'active_breeder')->value('id');
        if ($breeder) {
            DB::table('prepuce_conditions')->whereNull('breedingStatusId')->update(['breedingStatusId' => $breeder]);
        }
    }

    /**
     * @return list<int>
     */
    private function mapCodesToIds(mixed $jsonOrArray, string $table): array
    {
        $codes = $this->decodeStringList($jsonOrArray);
        $ids = [];
        foreach ($codes as $code) {
            $id = DB::table($table)->where('code', $code)->value('id');
            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private function decodeStringList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $this->decodeStringList($decoded) : [];
        }
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if ($item === null) {
                continue;
            }
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }

    private function promoteLookupTablesToNameColumns(): void
    {
        foreach (self::LOOKUP_TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'name')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->string('name')->nullable();
                $table->string('name_sw')->nullable();
            });

            DB::table($tableName)->orderBy('id')->chunkById(200, function ($rows) use ($tableName) {
                foreach ($rows as $row) {
                    DB::table($tableName)->where('id', $row->id)->update([
                        'name' => $row->label_en,
                        'name_sw' => $row->label_sw,
                    ]);
                }
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['code', 'label_en', 'label_sw']);
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->string('name')->nullable(false)->change();
                $table->unique('name');
            });
        }
    }
};
