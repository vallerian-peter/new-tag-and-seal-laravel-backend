<?php

namespace App\Http\Controllers\Dairy;

use App\Http\Controllers\Controller;
use App\Services\Dairy\CooperativeAccess;
use App\Services\Dairy\FarmerAccount;
use App\Services\Dairy\SyncCursor;
use App\Services\Hiring\HiringNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FarmerLedgerController extends Controller
{
    /**
     * GET /api/v2/dairy/ledger
     * Returns Farmer Cooperative Account Ledger entries (Credits from deliveries, Debits from expenses/debts & payouts).
     */
    public function ledger(Request $request): JsonResponse
    {
        $user = $request->user();
        $farmerUserId = $request->query('farmer_user_id');
        $amcosUuid = $request->query('amcos_uuid');
        $role = $request->query('role', 'farmer');

        $isSelfView = $role === 'farmer' && (! $farmerUserId || (int) $farmerUserId === (int) $user->id);
        if (! $isSelfView) {
            if (! $amcosUuid) {
                CooperativeAccess::deny('Select a cooperative first.');
            }
            CooperativeAccess::requireStaff($user->id, $amcosUuid);
        }

        $query = DB::table('farmer_cooperative_account_ledgers as l')
            ->leftJoin('cooperative_amcos as a', 'a.uuid', '=', 'l.amcos_uuid')
            ->leftJoin('cooperative_mcus as m', 'm.uuid', '=', 'l.mcu_uuid')
            ->leftJoin('cooperative_collection_centres as c', 'c.uuid', '=', 'l.centre_uuid')
            ->leftJoin('cooperative_farmer_memberships as fm', function ($join) {
                $join->on('fm.farmer_user_id', '=', 'l.farmer_user_id')
                    ->on('fm.amcos_uuid', '=', 'l.amcos_uuid');
            })
            ->select(
                'l.*',
                'a.name as amcos_name',
                'm.name as mcu_name',
                'c.name as centre_name',
                'fm.farmer_name',
                'fm.farmer_phone',
                'fm.member_number'
            )
            ->orderByDesc('l.entry_date')
            ->orderByDesc('l.id');

        $filter = function ($q) use ($isSelfView, $user, $farmerUserId, $amcosUuid) {
            if ($isSelfView) {
                $q->where('l.farmer_user_id', $user->id);
            } elseif ($farmerUserId) {
                $q->where('l.farmer_user_id', (int) $farmerUserId);
            }
            if ($amcosUuid) {
                $q->where('l.amcos_uuid', $amcosUuid);
            }
        };
        $filter($query);

        if ($request->boolean('sync')) {
            [$entries, $meta] = SyncCursor::page($query, 'l', $request->query('cursor'));

            return response()->json(['status' => true, 'data' => ['entries' => $entries], 'meta' => $meta]);
        }

        $totals = DB::table('farmer_cooperative_account_ledgers as l')
            ->tap($filter)
            ->selectRaw('COALESCE(SUM(l.credit_amount), 0) as credits')
            ->selectRaw('COALESCE(SUM(l.debit_amount), 0) as debits')
            ->selectRaw("COALESCE(SUM(CASE WHEN l.entry_type = 'debit_expense' THEN l.debit_amount ELSE 0 END), 0) as expenses")
            ->selectRaw("COALESCE(SUM(CASE WHEN l.entry_type = 'debit_payout' THEN l.debit_amount ELSE 0 END), 0) as payouts")
            ->first();
        $totalCredits = round((float) $totals->credits, 2);
        $totalDebits = round((float) $totals->debits, 2);

        return response()->json([
            'status' => true,
            'data' => [
                'summary' => [
                    'total_credits' => $totalCredits,
                    'total_debits' => $totalDebits,
                    'total_expenses_debt' => round((float) $totals->expenses, 2),
                    'total_payouts' => round((float) $totals->payouts, 2),
                    'net_balance' => round($totalCredits - $totalDebits, 2),
                ],
                'account' => $this->account($isSelfView ? (int) $user->id : ($farmerUserId ? (int) $farmerUserId : null), $amcosUuid),
                'entries' => $query->limit(200)->get(),
            ],
        ]);
    }
    /**
     * POST /api/v2/dairy/ledger/entry
     * Record a Farmer Payout (debit_payout) or Farmer Expense/Debt (debit_expense) or Credit/Debit adjustment.
     * A payout above the balance is allowed: the rest becomes debt, which the
     * next delivery credit reduces first.
     */
    public function recordLedgerEntry(Request $request, HiringNotifications $notifications): JsonResponse
    {
        $data = $request->validate([
            'amcos_uuid' => 'required|string|exists:cooperative_amcos,uuid',
            'centre_uuid' => 'nullable|string|exists:cooperative_collection_centres,uuid',
            'farmer_user_id' => 'required|integer|exists:users,id',
            'entry_type' => 'required|in:debit_payout,debit_expense,credit_adjustment,debit_adjustment',
            'amount' => 'required|numeric|gt:0',
            'payment_method' => 'nullable|in:cash,mobile_money,bank_transfer,ledger_offset',
            'reference_number' => 'nullable|string|max:100',
            'description' => 'required|string|max:255',
            'uuid' => 'nullable|string|max:64',
        ]);

        $amcos = DB::table('cooperative_amcos')->where('uuid', $data['amcos_uuid'])->firstOrFail();
        $isAdjustment = in_array($data['entry_type'], ['credit_adjustment', 'debit_adjustment'], true);
        CooperativeAccess::requireStaff($request->user()->id, $amcos->uuid, $isAdjustment, $data['centre_uuid'] ?? null);

        if (! empty($data['uuid'])) {
            $dup = DB::table('farmer_cooperative_account_ledgers')->where('uuid', $data['uuid'])->first();
            if ($dup) {
                abort_unless(
                    $dup->amcos_uuid === $amcos->uuid
                    && (int) $dup->farmer_user_id === (int) $data['farmer_user_id']
                    && $dup->entry_type === $data['entry_type']
                    && round((float) $dup->credit_amount + (float) $dup->debit_amount, 2) === round((float) $data['amount'], 2),
                    409,
                    'Entry UUID is already in use for different details.'
                );

                return response()->json(['status' => true, 'message' => 'Entry already synced.', 'data' => $dup]);
            }
        }
        $membership = DB::table('cooperative_farmer_memberships')
            ->where('amcos_uuid', $amcos->uuid)
            ->where('farmer_user_id', $data['farmer_user_id'])
            ->first();

        if (! $membership || $membership->status !== 'active') {
            return response()->json(['status' => false, 'message' => 'Farmer is not an active member of this cooperative.'], 422);
        }

        $amount = round((float) $data['amount'], 2);
        $isCredit = $data['entry_type'] === 'credit_adjustment';
        $creditAmount = $isCredit ? $amount : 0.00;
        $debitAmount = $isCredit ? 0.00 : $amount;

        return DB::transaction(function () use ($request, $data, $amcos, $membership, $amount, $creditAmount, $debitAmount, $notifications) {
            $entryUuid = $data['uuid'] ?? (string) Str::uuid();
            $refNumber = $data['reference_number'] ?? ('LDG-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4)));

            [, $account] = FarmerAccount::post([
                'uuid' => $entryUuid,
                'unit' => $amcos->default_unit ?? 'l',
                'mcu_uuid' => $amcos->mcu_uuid,
                'amcos_uuid' => $amcos->uuid,
                'centre_uuid' => $data['centre_uuid'] ?? null,
                'farmer_user_id' => $data['farmer_user_id'],
                'farmer_id' => $membership->farmer_id ?? null,
                'entry_type' => $data['entry_type'],
                'credit_amount' => $creditAmount,
                'debit_amount' => $debitAmount,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'reference_number' => $refNumber,
                'description' => trim($data['description']),
                'recorded_by_user_id' => $request->user()->id,
            ]);
            $newBalance = round((float) $account->balance, 2);

            if (in_array($data['entry_type'], ['debit_payout', 'debit_expense'], true)) {
                $remainingToSettle = $amount;
                $unpaidIntakes = DB::table('cooperative_intakes')
                    ->where('farmer_user_id', $data['farmer_user_id'])
                    ->where('amcos_uuid', $amcos->uuid)
                    ->whereIn('payment_status', ['unpaid', 'partially_paid'])
                    ->orderBy('intake_timestamp')
                    ->get();

                foreach ($unpaidIntakes as $intake) {
                    if ($remainingToSettle <= 0) {
                        break;
                    }
                    $outstanding = round((float) $intake->payable_amount - (float) $intake->paid_amount, 2);
                    if ($outstanding <= 0) {
                        continue;
                    }
                    $applied = min($remainingToSettle, $outstanding);
                    $newPaid = round((float) $intake->paid_amount + $applied, 2);
                    $status = ($newPaid >= (float) $intake->payable_amount - 0.01) ? 'paid' : 'partially_paid';

                    DB::table('cooperative_intakes')
                        ->where('id', $intake->id)
                        ->update([
                            'paid_amount' => $newPaid,
                            'payment_status' => $status,
                            'updated_at' => now(),
                        ]);
                    $remainingToSettle = round($remainingToSettle - $applied, 2);
                }
            }

            $sms = self::entrySms($data['entry_type'], $amcos->name, $refNumber, $amount, $newBalance);
            $kind = $data['entry_type'] === 'debit_payout' ? 'cooperative_payout' : 'cooperative_ledger_entry';
            $notifications->send("{$kind}:{$entryUuid}", (int) $data['farmer_user_id'], $membership->farmer_phone, $kind, $sms, $sms, [
                'entry_uuid' => $entryUuid, 'entry_type' => $data['entry_type'],
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Farmer ledger entry recorded successfully.',
                'data' => DB::table('farmer_cooperative_account_ledgers')->where('uuid', $entryUuid)->first(),
            ], 201);
        });
    }

    /** Swahili SMS for a ledger entry, naming the new balance or the debt. */
    public static function entrySms(string $type, string $amcosName, string $ref, float $amount, float $balance): string
    {
        $money = fn (float $v) => 'TZS ' . number_format(abs($v), 0);
        $what = match ($type) {
            'debit_payout' => "Umelipwa {$money($amount)} na {$amcosName}.",
            'debit_expense' => "Makato ya {$money($amount)} yamerekodiwa na {$amcosName}.",
            'credit_adjustment' => "Akaunti yako imeongezwa {$money($amount)} na {$amcosName}.",
            default => "Akaunti yako imepunguzwa {$money($amount)} na {$amcosName}.",
        };
        $after = $balance < 0 ? "Deni: {$money($balance)}." : "Salio jipya: {$money($balance)}.";

        return "Tag & Seal ({$ref}): {$what} {$after}";
    }

    private function account(?int $farmerUserId, ?string $amcosUuid): ?object
    {
        if (! $farmerUserId) {
            return null;
        }
        $query = DB::table('farmer_cooperative_accounts')->where('farmer_user_id', $farmerUserId);
        if ($amcosUuid) {
            $query->where('amcos_uuid', $amcosUuid);
        }

        return $query->orderByDesc('last_entry_at')->first();
    }
}
