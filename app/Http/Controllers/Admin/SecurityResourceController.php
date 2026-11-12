<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformUpgradeRun;
use App\Services\Dynamic\ConfigurationSyncService;
use App\Services\Ledger\LegacyBridge;
use App\Services\Ledger\LegacyCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SecurityResourceController extends Controller
{
    public function upgradeRuns(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 10), 5), 100);
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $sort = (string) $request->query('sort', 'desc');

        $query = PlatformUpgradeRun::query();

        if ($status !== '' && $status !== 'all') {
            $query->where('state', $status);
        }

        if ($search !== '') {
            $like = "%{$search}%";
            $query->where(function ($q) use ($like) {
                $q->where('release', 'like', $like)
                    ->orWhere('uuid', 'like', $like)
                    ->orWhere('environment', 'like', $like)
                    ->orWhere('target_schema_version', 'like', $like);
            });
        }

        match ($sort) {
            'asc', 'oldest' => $query->orderBy('started_at')->orderBy('id'),
            default => $query->orderByDesc('started_at')->orderByDesc('id'),
        };

        $paginator = $query->paginate($perPage)->withQueryString();

        $stats = [
            ['label' => 'Total Upgrade Runs', 'value' => PlatformUpgradeRun::query()->count(), 'note' => 'Platform version executions'],
            ['label' => 'Completed', 'value' => PlatformUpgradeRun::query()->where('state', 'completed')->count(), 'note' => 'Successfully deployed upgrades'],
            ['label' => 'Failed / Attention', 'value' => PlatformUpgradeRun::query()->whereIn('state', ['failed', 'rolled_back'])->count(), 'note' => 'Upgrade executions needing review'],
        ];

        return response()->json([
            'status' => true,
            'message' => 'Upgrade runs fetched successfully.',
            'data' => $paginator->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'stats' => $stats,
            ],
        ]);
    }

    public function ledgerMigration(): JsonResponse
    {
        $families = [
            'births' => [
                'family' => 'births',
                'label' => 'Births & Offspring',
                'description' => 'Calving, farrowing, kidding, and litter recordings.',
                'tables' => ['birth_events', 'aborted_pregnancies'],
            ],
            'health' => [
                'family' => 'health',
                'label' => 'Health & Veterinary',
                'description' => 'Vaccinations, dewormings, medications, treatments, and clinical signs.',
                'tables' => ['vaccinations', 'dewormings', 'treatments', 'prepuce_conditions'],
            ],
            'production' => [
                'family' => 'production',
                'label' => 'Milk & Yield Production',
                'description' => 'Milking sessions, yield measurements, and egg collections.',
                'tables' => ['milkings'],
            ],
            'breeding' => [
                'family' => 'breeding',
                'label' => 'Breeding & Reproduction',
                'description' => 'Insemination records, pregnancy checks, heat cycles, and dry-off.',
                'tables' => ['inseminations', 'pregnancies', 'dryoffs'],
            ],
            'growth' => [
                'family' => 'growth',
                'label' => 'Growth & Inventory',
                'description' => 'Weight tracking, nutrition feedings, teeth clipping, tail docking, and stage changes.',
                'tables' => ['weight_changes', 'feedings', 'iron_injections', 'teeth_clippings', 'tail_dockings', 'stage_changes', 'livestock_markings'],
            ],
            'movement' => [
                'family' => 'movement',
                'label' => 'Movement & Transfers',
                'description' => 'Farm-to-farm livestock relocations, transport, and acquisitions.',
                'tables' => ['transfers', 'disposals'],
            ],
        ];

        $data = [];
        foreach ($families as $code => $info) {
            $flag = DB::table('platform_feature_flags')
                ->where('key', "family.{$code}.authority")
                ->value('value_json');
            $authority = $flag ? (json_decode($flag, true)['authority'] ?? 'legacy') : 'legacy';

            $legacyCount = 0;
            foreach ($info['tables'] as $table) {
                if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                    $legacyCount += DB::table($table)->count();
                }
            }

            $ledgerCount = DB::table('event_records')
                ->where('behavior_code', 'like', "%{$code}%")
                ->orWhereIn('event_definition_uuid', function ($q) use ($code) {
                    $q->select('uuid')->from('event_definitions')->where('family', $code);
                })
                ->count();

            $data[] = [
                'family' => $code,
                'label' => $info['label'],
                'description' => $info['description'],
                'authority' => $authority,
                'tables' => $info['tables'],
                'legacyCount' => $legacyCount,
                'ledgerCount' => $ledgerCount,
                'reconciled' => $legacyCount === 0 || $ledgerCount >= $legacyCount,
            ];
        }

        return response()->json([
            'status' => true,
            'message' => 'Ledger migration status fetched successfully.',
            'data' => $data,
        ]);
    }

    public function changeAuthority(Request $request, ConfigurationSyncService $syncService): JsonResponse
    {
        $validated = $request->validate([
            'family' => ['required', 'string', Rule::in(LegacyCatalog::FAMILIES)],
            'authority' => ['required', 'string', Rule::in(['legacy', 'ledger_shadow', 'ledger', 'retired'])],
        ]);

        $family = $validated['family'];
        $authority = $validated['authority'];

        DB::table('platform_feature_flags')->updateOrInsert(
            ['key' => "family.{$family}.authority"],
            [
                'value_json' => json_encode(['authority' => $authority], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]
        );

        $syncService->publishRevision($request->user()?->uuid);

        return response()->json([
            'status' => true,
            'message' => "Authority for {$family} transitioned to {$authority}.",
            'data' => [
                'family' => $family,
                'authority' => $authority,
            ],
        ]);
    }
}

