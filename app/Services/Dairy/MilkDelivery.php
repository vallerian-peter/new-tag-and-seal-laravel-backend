<?php

namespace App\Services\Dairy;

use App\Services\Hiring\HiringNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared rules for milk received at a collection centre, whether recorded
 * directly by staff or approved from a farmer's declared delivery.
 */
class MilkDelivery
{
    public const TEST_COLUMNS = ['temperature_celsius', 'density_lactometer', 'fat_percentage', 'alcohol_test_passed', 'antibiotic_free'];

    public const EXTRA_METRICS = [
        'snf_percentage', 'protein_percentage', 'acidity', 'corrected_lactometer',
        'total_solids', 'colony_forming_units', 'smell_normal', 'colour_normal',
    ];

    public const REJECTION_CATEGORIES = ['not_received', 'below_grade', 'other'];

    /** Validation rules for measurement and quality readings. */
    public static function readingRules(): array
    {
        return [
            'measurement_method' => 'nullable|in:volume,weight',
            'weight_kg' => 'nullable|numeric|gt:0|required_if:measurement_method,weight',
            'quality_grade' => 'nullable|string|max:20',
            'is_tested' => 'required|boolean',
            'temperature_celsius' => 'nullable|numeric|between:-5,60',
            'density_lactometer' => 'nullable|numeric|min:0',
            'fat_percentage' => 'nullable|numeric|between:0,100',
            'alcohol_test_passed' => 'nullable|boolean',
            'antibiotic_free' => 'nullable|boolean',
            'scale_device_id' => 'nullable|string|max:100',
            'quality_metrics' => 'nullable|array',
            'quality_metrics.snf_percentage' => 'nullable|numeric|between:0,100',
            'quality_metrics.protein_percentage' => 'nullable|numeric|between:0,100',
            'quality_metrics.acidity' => 'nullable|numeric|min:0',
            'quality_metrics.corrected_lactometer' => 'nullable|numeric|min:0',
            'quality_metrics.total_solids' => 'nullable|numeric|between:0,100',
            'quality_metrics.colony_forming_units' => 'nullable|numeric|min:0',
            'quality_metrics.smell_normal' => 'nullable|boolean',
            'quality_metrics.colour_normal' => 'nullable|boolean',
        ];
    }

    public static function metrics(array $data): array
    {
        $metrics = [];
        foreach (self::EXTRA_METRICS as $key) {
            $value = $data['quality_metrics'][$key] ?? null;
            if ($value !== null && $value !== '') {
                $metrics[$key] = is_bool($value) ? $value : (float) $value;
            }
        }

        return $metrics;
    }

    public static function hasTestResult(array $data): bool
    {
        return collect(self::TEST_COLUMNS)->contains(fn ($field) => ($data[$field] ?? null) !== null)
            || self::metrics($data) !== [];
    }

    /** Intake columns for measurement and quality, ready to insert or update. */
    public static function readingColumns(array $data, array $extraMetrics = []): array
    {
        $tested = (bool) ($data['is_tested'] ?? false);
        $metrics = $tested ? self::metrics($data) : [];
        $metrics = array_merge($metrics, $extraMetrics);
        $columns = [
            'measurement_method' => $data['measurement_method'] ?? 'volume',
            'weight_kg' => ($data['measurement_method'] ?? 'volume') === 'weight' ? ($data['weight_kg'] ?? null) : null,
            'quality_grade' => $data['quality_grade'] ?? null,
            'is_tested' => $tested,
            'test_status' => $tested ? 'tested' : 'not_tested',
            'scale_device_id' => $data['scale_device_id'] ?? null,
            'quality_metrics_json' => $metrics === [] ? null : json_encode($metrics),
        ];
        foreach (self::TEST_COLUMNS as $field) {
            $columns[$field] = $tested ? ($data[$field] ?? null) : null;
        }

        return $columns;
    }

    /** Validates gross = accepted + rejected and the reason; returns an error message or null. */
    public static function quantityError(float $gross, float $accepted, float $rejected, ?string $reason): ?string
    {
        if ($gross <= 0 || abs(($accepted + $rejected) - $gross) > 0.001) {
            return 'Validation error: Delivered quantity must equal Accepted quantity + Rejected quantity.';
        }
        if ($rejected > 0 && trim((string) $reason) === '') {
            return 'Rejection reason is required when rejected quantity is greater than zero.';
        }

        return null;
    }

    /** Active season price for the AMCOS (AMCOS season first, then MCU season). */
    public static function season(string $amcosUuid, ?string $mcuUuid, string $unit): ?object
    {
        return DB::table('cooperative_seasons')
            ->where('is_active', true)
            ->where('unit', $unit)
            ->where(function ($q) use ($amcosUuid, $mcuUuid) {
                $q->where('amcos_uuid', $amcosUuid)
                    ->when($mcuUuid !== null, fn ($q) => $q->orWhere(function ($q2) use ($mcuUuid) {
                        $q2->where('mcu_uuid', $mcuUuid)->whereNull('amcos_uuid');
                    }));
            })
            ->whereDate('start_date', '<=', now()->toDateString())
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString());
            })
            ->orderByRaw('CASE WHEN amcos_uuid IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByDesc('start_date')
            ->first();
    }

    /**
     * Today's price for each unit at an AMCOS, chosen as season() does: the
     * AMCOS's own active season first, then its MCU's, newest first.
     *
     * @return list<array{season_uuid: string, season_name: string, unit: string, price_per_unit: float, start_date: ?string, end_date: ?string, amcos_uuid: ?string}>
     */
    public static function currentPrices(string $amcosUuid, ?string $mcuUuid): array
    {
        $today = now()->toDateString();

        return DB::table('cooperative_seasons')
            ->where('is_active', true)
            ->where(function ($q) use ($amcosUuid, $mcuUuid) {
                $q->where('amcos_uuid', $amcosUuid)
                    ->when($mcuUuid !== null, fn ($q) => $q->orWhere(function ($q2) use ($mcuUuid) {
                        $q2->where('mcu_uuid', $mcuUuid)->whereNull('amcos_uuid');
                    }));
            })
            ->whereDate('start_date', '<=', $today)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->orderByRaw('CASE WHEN amcos_uuid IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByDesc('start_date')
            ->get()
            ->unique('unit')
            ->map(fn ($s) => [
                'season_uuid' => $s->uuid,
                'season_name' => $s->name,
                'unit' => $s->unit,
                'price_per_unit' => round((float) $s->price_per_unit, 2),
                'start_date' => $s->start_date ? substr((string) $s->start_date, 0, 10) : null,
                'end_date' => $s->end_date ? substr((string) $s->end_date, 0, 10) : null,
                'amcos_uuid' => $s->amcos_uuid,
            ])
            ->values()
            ->all();
    }

    /** Active membership allowed to deliver at the centre, or null. */
    public static function membership(object $centre, int $farmerUserId): ?object
    {
        $membership = DB::table('cooperative_farmer_memberships')
            ->where('amcos_uuid', $centre->amcos_uuid)
            ->where('farmer_user_id', $farmerUserId)
            ->first();
        $assigned = json_decode((string) ($membership->enrolled_centre_uuids ?? '[]'), true) ?: [];

        return $membership
            && $membership->status === 'active'
            && ($assigned === [] || in_array($centre->uuid, $assigned, true))
            ? $membership : null;
    }

    public static function unitSymbol(string $unit): string
    {
        return strtoupper((string) (DB::table('measurement_units')->where('code', $unit)->value('symbol') ?? $unit));
    }

    /**
     * Puts the accepted quantity into centre stock and credits the farmer's
     * account. Must run inside the transaction that approved the intake.
     *
     * @return float the farmer's new account balance
     */
    /**
     * The phone's ledger UUID for a delivery credit, so the credit it showed
     * offline is the same record. Rejects one already used by another entry.
     */
    public static function freeLedgerUuid(?string $uuid): ?string
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        abort_if(
            DB::table('farmer_cooperative_account_ledgers')->where('uuid', $uuid)->exists(),
            409,
            'Ledger UUID is already in use for another entry.'
        );

        return $uuid;
    }

    public static function settle(object $intake, object $centre, object $membership, int $actorId, ?string $ledgerUuid = null): float
    {
        $accepted = (float) $intake->accepted_quantity;
        $payable = (float) $intake->payable_amount;
        $unitSymbol = self::unitSymbol($intake->unit);

        if ($accepted > 0) {
            $stock = CentreStock::apply(CentreStock::lock($centre->uuid, $intake->commodity_code, $intake->unit), $accepted, $accepted);
            DB::table('cooperative_centre_stock_ledgers')->insert([
                'uuid' => (string) Str::uuid(),
                'unit' => $intake->unit,
                'commodity_code' => $intake->commodity_code,
                'mcu_uuid' => $intake->mcu_uuid,
                'amcos_uuid' => $intake->amcos_uuid,
                'centre_uuid' => $centre->uuid,
                'movement_type' => 'farmer_intake',
                'quantity' => $accepted,
                'running_balance' => (float) $stock->current_balance,
                'destination_or_reason' => "Receipt {$intake->receipt_number} from {$membership->farmer_name}",
                'reference_uuid' => $intake->uuid,
                'recorded_by_user_id' => $actorId,
                'movement_date' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($payable <= 0) {
            return FarmerAccount::balance((int) $intake->farmer_user_id, $intake->amcos_uuid);
        }
        [, $account] = FarmerAccount::post([
            'uuid' => $ledgerUuid ?? (string) Str::uuid(),
            'unit' => $intake->unit,
            'quantity' => $accepted,
            'mcu_uuid' => $intake->mcu_uuid,
            'amcos_uuid' => $intake->amcos_uuid,
            'centre_uuid' => $centre->uuid,
            'farmer_user_id' => $intake->farmer_user_id,
            'farmer_id' => $membership->farmer_id,
            'intake_uuid' => $intake->uuid,
            'season_uuid' => $intake->season_uuid,
            'entry_type' => 'credit_delivery',
            'credit_amount' => $payable,
            'reference_number' => $intake->receipt_number,
            'description' => "Mapato ({$accepted} {$unitSymbol} @ TZS {$intake->price_per_unit}/{$unitSymbol}) - {$centre->name}",
            'recorded_by_user_id' => $actorId,
        ]);

        return round((float) $account->balance, 2);
    }

    public static function notifyApproved(HiringNotifications $notifications, object $intake, object $centre, object $membership, float $balance, string $kind = 'cooperative_delivery_approved'): string
    {
        $unit = self::unitSymbol($intake->unit);
        $gross = (float) $intake->gross_quantity;
        $accepted = (float) $intake->accepted_quantity;
        $rejected = (float) $intake->rejected_quantity;
        $payable = number_format((float) $intake->payable_amount, 0);
        $total = number_format($balance, 0);
        $rejectSw = $rejected > 0 ? " Yaliyokataliwa: {$rejected}{$unit} ({$intake->rejection_reason})." : '';
        $rejectEn = $rejected > 0 ? " Rejected: {$rejected} {$unit} ({$intake->rejection_reason})." : '';
        $sw = "Tag & Seal ({$intake->receipt_number}): Umefikisha {$gross}{$unit} katika {$centre->name}. Yaliyokubaliwa: {$accepted}{$unit} (Credit: TZS {$payable}).{$rejectSw} Salio la Akaunti: TZS {$total}.";
        $en = "Tag & Seal ({$intake->receipt_number}): {$gross} {$unit} received at {$centre->name}. Accepted: {$accepted} {$unit} (credit TZS {$payable}).{$rejectEn} Account balance: TZS {$total}.";
        $notifications->send("{$kind}:{$intake->uuid}", (int) $intake->farmer_user_id, $membership->farmer_phone, $kind, $en, $sw, [
            'receipt_number' => $intake->receipt_number, 'intake_uuid' => $intake->uuid,
        ]);

        return $sw;
    }

    public static function notifyRejected(HiringNotifications $notifications, object $intake, object $centre, object $membership): void
    {
        $unit = self::unitSymbol($intake->unit);
        $declared = (float) ($intake->declared_quantity ?? $intake->gross_quantity);
        [$sw, $en] = match ($intake->rejection_category) {
            'not_received' => ['Maziwa hayakupokelewa', 'Milk was not received'],
            'below_grade' => ['Maziwa yameshindwa vipimo vya ubora', 'Milk failed the quality tests'],
            default => ['Sababu nyingine', 'Other reason'],
        };
        $note = trim((string) $intake->rejection_reason);
        $notifications->send(
            "cooperative_delivery_rejected:{$intake->uuid}",
            (int) $intake->farmer_user_id,
            $membership->farmer_phone,
            'cooperative_delivery_rejected',
            "Tag & Seal: Your delivery of {$declared} {$unit} to {$centre->name} was rejected. {$en}" . ($note !== '' ? ": {$note}." : '.'),
            "Tag & Seal: Uwasilishaji wako wa {$declared}{$unit} katika {$centre->name} umekataliwa. {$sw}" . ($note !== '' ? ": {$note}." : '.'),
            ['intake_uuid' => $intake->uuid]
        );
    }
}
