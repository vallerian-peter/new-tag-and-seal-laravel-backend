<?php

namespace App\Services\Dairy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One account per farmer per AMCOS holding the running totals, with every
 * movement kept as a row in farmer_cooperative_account_ledgers for audit.
 * Call inside a database transaction.
 */
class FarmerAccount
{
    public static function lock(int $farmerUserId, string $amcosUuid, ?string $mcuUuid = null): object
    {
        DB::table('farmer_cooperative_accounts')->insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'farmer_user_id' => $farmerUserId,
            'amcos_uuid' => $amcosUuid,
            'mcu_uuid' => $mcuUuid,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('farmer_cooperative_accounts')
            ->where('farmer_user_id', $farmerUserId)
            ->where('amcos_uuid', $amcosUuid)
            ->lockForUpdate()
            ->first();
    }

    public static function balance(int $farmerUserId, string $amcosUuid): float
    {
        return round((float) (DB::table('farmer_cooperative_accounts')
            ->where('farmer_user_id', $farmerUserId)
            ->where('amcos_uuid', $amcosUuid)
            ->value('balance') ?? 0), 2);
    }

    /**
     * Inserts the ledger row and moves the account totals in the same step.
     * `$entry` holds the ledger columns; credit_amount/debit_amount default to 0.
     *
     * @return array{0: object, 1: object} [ledger entry, updated account]
     */
    public static function post(array $entry): array
    {
        $credit = round((float) ($entry['credit_amount'] ?? 0), 2);
        $debit = round((float) ($entry['debit_amount'] ?? 0), 2);
        $account = self::lock((int) $entry['farmer_user_id'], $entry['amcos_uuid'], $entry['mcu_uuid'] ?? null);
        $balance = round((float) $account->balance + $credit - $debit, 2);
        $uuid = $entry['uuid'] ?? (string) Str::uuid();

        DB::table('farmer_cooperative_account_ledgers')->insert(array_merge($entry, [
            'uuid' => $uuid,
            'account_uuid' => $account->uuid,
            'credit_amount' => $credit,
            'debit_amount' => $debit,
            'running_balance' => $balance,
            'entry_date' => $entry['entry_date'] ?? now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        DB::table('farmer_cooperative_accounts')->where('id', $account->id)->update([
            'total_credit' => round((float) $account->total_credit + $credit, 2),
            'total_debit' => round((float) $account->total_debit + $debit, 2),
            'balance' => $balance,
            'outstanding_debt' => max(0, -$balance),
            'last_entry_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            DB::table('farmer_cooperative_account_ledgers')->where('uuid', $uuid)->first(),
            DB::table('farmer_cooperative_accounts')->where('id', $account->id)->first(),
        ];
    }
}
