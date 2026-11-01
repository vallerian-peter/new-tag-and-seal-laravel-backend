<?php

namespace App\Services\Ledger;

use App\Enums\DefinitionStatus;
use App\Services\Upgrade\DynamicV1Installer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy reference tables (birth types, medicines, diseases…) published as lookup sets.
 * Each option remembers its legacy id so values convert both ways without guessing.
 * Tables with a livestockTypeId get one set per species.
 */
class LegacyLookups
{
    /** @var array<string, array{byId: array<int, string>, byCode: array<string, int>}> */
    private static array $cache = [];

    public function __construct(private readonly DynamicV1Installer $installer) {}

    public static function setCode(string $table, ?string $speciesCode): string
    {
        return self::perSpecies($table) && $speciesCode ? "legacy.$table.$speciesCode" : "legacy.$table";
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** Publishes or refreshes every reference table the catalog uses. */
    public function mirror(): int
    {
        $count = 0;
        foreach (LegacyCatalog::referenceTables() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $species = self::perSpecies($table) ? LegacyCatalog::LEGACY_SPECIES : [null];
            foreach ($species as $speciesCode) {
                $count += $this->mirrorSet($table, $speciesCode);
            }
        }
        self::flush();

        return $count;
    }

    public function code(string $table, ?string $speciesCode, mixed $legacyId): ?string
    {
        if ($legacyId === null || $legacyId === '' || ! is_numeric($legacyId)) {
            return null;
        }

        return $this->options($table, $speciesCode)['byId'][(int) $legacyId] ?? null;
    }

    public function legacyId(string $table, ?string $speciesCode, mixed $code): ?int
    {
        if (! is_string($code) || $code === '') {
            return null;
        }

        return $this->options($table, $speciesCode)['byCode'][$code] ?? null;
    }

    private function options(string $table, ?string $speciesCode): array
    {
        $setCode = self::setCode($table, $speciesCode);
        if (! isset(self::$cache[$setCode])) {
            $rows = DB::table('lookup_options')
                ->join('lookup_sets', 'lookup_sets.uuid', '=', 'lookup_options.lookup_set_uuid')
                ->where('lookup_sets.code', $setCode)
                ->whereNotNull('lookup_options.legacy_id')
                ->get(['lookup_options.code', 'lookup_options.legacy_id']);
            self::$cache[$setCode] = [
                'byId' => $rows->mapWithKeys(fn ($row) => [(int) $row->legacy_id => $row->code])->all(),
                'byCode' => $rows->mapWithKeys(fn ($row) => [$row->code => (int) $row->legacy_id])->all(),
            ];
        }

        return self::$cache[$setCode];
    }

    private function mirrorSet(string $table, ?string $speciesCode): int
    {
        $code = self::setCode($table, $speciesCode);
        $setUuid = $this->installer->uuid("lookup/$code");
        $title = str($table)->replace('_', ' ')->title()->toString();
        $name = $speciesCode ? "$title ($speciesCode)" : $title;
        $existing = DB::table('lookup_sets')->where('uuid', $setUuid)->first();
        $values = [
            'code' => $code, 'version' => $existing->version ?? 1, 'name' => $name,
            'translations_json' => json_encode(['en' => $name], JSON_THROW_ON_ERROR),
            'status' => DefinitionStatus::PUBLISHED->value, 'is_system' => true, 'is_extensible' => false,
            'legacy_table' => $table, 'published_at' => $existing->published_at ?? now(), 'updated_at' => now(),
        ];
        $existing
            ? DB::table('lookup_sets')->where('uuid', $setUuid)->update($values)
            : DB::table('lookup_sets')->insert($values + ['uuid' => $setUuid, 'created_at' => now()]);

        $query = DB::table($table)->orderBy('id');
        if ($speciesCode && self::perSpecies($table)) {
            $typeId = DB::table('animal_species')->where('code', $speciesCode)->value('legacy_livestock_type_id');
            $query->where(fn ($where) => $where->whereNull('livestockTypeId')->when($typeId, fn ($q) => $q->orWhere('livestockTypeId', $typeId)));
        }
        $hasSwahili = Schema::hasColumn($table, 'name_sw');
        // Options are edited in the Portal and written through to the legacy table, so an
        // existing option keeps its code (stored in event payloads), status and order;
        // only rows the Portal has not seen yet are added.
        $existing = DB::table('lookup_options')->where('lookup_set_uuid', $setUuid)->get()->keyBy('legacy_id');
        $used = DB::table('lookup_options')->where('lookup_set_uuid', $setUuid)->pluck('code')->flip()->all();
        $sort = (int) DB::table('lookup_options')->where('lookup_set_uuid', $setUuid)->max('sort_order');
        $kept = [];
        foreach ($query->get() as $row) {
            $translations = json_encode(array_filter(['en' => (string) $row->name, 'sw' => $hasSwahili ? $row->name_sw : null]), JSON_THROW_ON_ERROR);
            if ($option = $existing->get($row->id)) {
                $kept[] = $option->uuid;
                DB::table('lookup_options')->where('uuid', $option->uuid)->update(['label' => (string) $row->name, 'translations_json' => $translations, 'updated_at' => now()]);

                continue;
            }
            $optionCode = str((string) $row->name)->ascii()->lower()->slug('_')->toString() ?: "option_{$row->id}";
            if (isset($used[$optionCode])) {
                $optionCode .= "_{$row->id}";
            }
            $used[$optionCode] = true;
            $uuid = $this->installer->uuid("lookup/$code/{$row->id}");
            $kept[] = $uuid;
            DB::table('lookup_options')->insert([
                'uuid' => $uuid, 'lookup_set_uuid' => $setUuid, 'code' => $optionCode, 'legacy_id' => $row->id,
                'label' => (string) $row->name, 'translations_json' => $translations,
                'sort_order' => ++$sort, 'status' => 'active', 'retired_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('lookup_options')->where('lookup_set_uuid', $setUuid)->whereNotNull('legacy_id')->whereNotIn('uuid', $kept)->where('status', 'active')
            ->update(['status' => 'retired', 'retired_at' => now(), 'updated_at' => now()]);

        return count($kept);
    }

    /**
     * Legacy reference rows without a matching lookup option (missing or relabelled).
     *
     * @param  array<int, string>  $tables
     * @return array{expected: int, drifted: array<int, array{set: string, legacyId: int, name: string}>}
     */
    public function audit(array $tables): array
    {
        $expected = 0;
        $drifted = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (self::perSpecies($table) ? LegacyCatalog::LEGACY_SPECIES : [null] as $speciesCode) {
                $setCode = self::setCode($table, $speciesCode);
                $labels = DB::table('lookup_options')->join('lookup_sets', 'lookup_sets.uuid', '=', 'lookup_options.lookup_set_uuid')
                    ->where('lookup_sets.code', $setCode)->whereNotNull('lookup_options.legacy_id')
                    ->pluck('lookup_options.label', 'lookup_options.legacy_id');
                $query = DB::table($table);
                if ($speciesCode) {
                    $typeId = DB::table('animal_species')->where('code', $speciesCode)->value('legacy_livestock_type_id');
                    $query->where(fn ($where) => $where->whereNull('livestockTypeId')->when($typeId, fn ($q) => $q->orWhere('livestockTypeId', $typeId)));
                }
                foreach ($query->get(['id', 'name']) as $row) {
                    $expected++;
                    if (($labels[$row->id] ?? null) !== (string) $row->name) {
                        $drifted[] = ['set' => $setCode, 'legacyId' => (int) $row->id, 'name' => (string) $row->name];
                    }
                }
            }
        }

        return ['expected' => $expected, 'drifted' => $drifted];
    }

    private static function perSpecies(string $table): bool
    {
        static $columns = [];

        return $columns[$table] ??= Schema::hasTable($table) && Schema::hasColumn($table, 'livestockTypeId');
    }
}
