<?php

namespace App\Services\Ledger;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Points bills, expenses and incomes at the ledger event behind the log they were
 * raised for. Finance rows keep their legacy reference; `eventRecordUuid` is added.
 */
class FinanceLinker
{
    /** finance table => column holding the log uuid */
    public const REFERENCES = ['bills' => 'subjectUuid', 'finance_incomes' => 'sourceUuid'];

    /** The ledger event for a log uuid: a bridged legacy row, or an event submitted directly. */
    public function eventFor(?string $logUuid): ?string
    {
        if (! $logUuid) {
            return null;
        }

        return DB::table('legacy_record_mappings')->where('target_type', 'event_record')->where('source_uuid', $logUuid)->value('target_uuid')
            ?? DB::table('event_records')->where('uuid', $logUuid)->value('uuid');
    }

    /** Links every finance row that references the log uuid. */
    public function linkLog(string $logUuid, string $eventUuid): int
    {
        if (! $this->ready()) {
            return 0;
        }
        $count = 0;
        foreach (self::REFERENCES as $table => $column) {
            $count += DB::table($table)->where($column, $logUuid)->where(fn ($query) => $query->whereNull('eventRecordUuid')->orWhere('eventRecordUuid', '!=', $eventUuid))
                ->update(['eventRecordUuid' => $eventUuid]);
        }
        $bills = DB::table('bills')->where('subjectUuid', $logUuid)->pluck('uuid');
        if ($bills->isNotEmpty()) {
            $count += DB::table('finance_expenses')->where('sourceType', 'bill')->whereIn('sourceUuid', $bills)->update(['eventRecordUuid' => $eventUuid]);
        }

        return $count;
    }

    /** Links one finance row after it is saved. */
    public function linkRow(string $table, mixed $id): void
    {
        if (! $this->ready()) {
            return;
        }
        $row = DB::table($table)->where('id', $id)->first();
        if (! $row) {
            return;
        }
        $event = $table === 'finance_expenses'
            ? (($row->sourceType ?? null) === 'bill' ? DB::table('bills')->where('uuid', $row->sourceUuid)->value('eventRecordUuid') : null)
            : $this->eventFor($row->{self::REFERENCES[$table]} ?? null);
        if ($event && $event !== $row->eventRecordUuid) {
            DB::table($table)->where('id', $id)->update(['eventRecordUuid' => $event]);
        }
        if ($table === 'bills' && $event) {
            DB::table('finance_expenses')->where('sourceType', 'bill')->where('sourceUuid', $row->uuid)->update(['eventRecordUuid' => $event]);
        }
    }

    public function linkAll(): int
    {
        if (! $this->ready()) {
            return 0;
        }
        $count = 0;
        foreach (array_keys(self::REFERENCES) as $table) {
            DB::table($table)->orderBy('id')->chunk(500, function ($rows) use ($table, &$count): void {
                foreach ($rows as $row) {
                    $before = $row->eventRecordUuid;
                    $this->linkRow($table, $row->id);
                    $count += (int) ($before !== DB::table($table)->where('id', $row->id)->value('eventRecordUuid'));
                }
            });
        }

        return $count;
    }

    /**
     * Finance rows that reference a legacy row of the given tables, with the event
     * they should point at and the one they do.
     *
     * @param  array<int, string>  $tables
     * @return array{expected: int, broken: array<int, array<string, mixed>>}
     */
    public function audit(array $tables): array
    {
        if (! $this->ready()) {
            return ['expected' => 0, 'broken' => []];
        }
        $expected = 0;
        $broken = [];
        foreach (self::REFERENCES as $table => $column) {
            $rows = DB::table("$table as f")
                ->join('legacy_record_mappings as m', fn ($join) => $join->on('m.source_uuid', '=', "f.$column")->where('m.target_type', '=', 'event_record'))
                ->whereIn('m.source_table', $tables)
                ->get(['f.uuid', 'f.eventRecordUuid as linked', 'm.target_uuid as event']);
            $expected += $rows->count();
            foreach ($rows as $row) {
                if ($row->linked !== $row->event) {
                    $broken[] = ['table' => $table, 'uuid' => $row->uuid, 'linked' => $row->linked, 'event' => $row->event];
                }
            }
        }
        $expenses = DB::table('finance_expenses as x')->join('bills as b', 'b.uuid', '=', 'x.sourceUuid')
            ->join('legacy_record_mappings as m', fn ($join) => $join->on('m.source_uuid', '=', 'b.subjectUuid')->where('m.target_type', '=', 'event_record'))
            ->where('x.sourceType', 'bill')->whereIn('m.source_table', $tables)
            ->get(['x.uuid', 'x.eventRecordUuid as linked', 'm.target_uuid as event']);
        $expected += $expenses->count();
        foreach ($expenses as $row) {
            if ($row->linked !== $row->event) {
                $broken[] = ['table' => 'finance_expenses', 'uuid' => $row->uuid, 'linked' => $row->linked, 'event' => $row->event];
            }
        }

        return ['expected' => $expected, 'broken' => $broken];
    }

    private function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasColumn('bills', 'eventRecordUuid');
    }
}
