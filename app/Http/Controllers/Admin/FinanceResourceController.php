<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Disposal;
use App\Models\ExtensionOfficer;
use App\Models\Farm;
use App\Models\Farmer;
use App\Models\FinanceExpense;
use App\Models\FinanceIncome;
use App\Models\Transfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FinanceResourceController extends Controller
{
    public const RESOURCES = [
        'bills',
        'income',
        'expenditure',
        'income-expenditure',
    ];

    public function index(Request $request, string $resource): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        return match ($resource) {
            'bills' => $this->indexBills($request),
            'income' => $this->indexIncome($request),
            'expenditure' => $this->indexExpenditure($request),
            'income-expenditure' => $this->indexIncomeExpenditureReport($request),
        };
    }

    public function options(string $resource): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        return response()->json([
            'status' => true,
            'message' => 'Finance options retrieved successfully.',
            'data' => $this->optionsPayload(),
        ]);
    }

    public function show(string $resource, string $record): JsonResponse
    {
        abort_unless(in_array($resource, ['bills', 'income', 'expenditure'], true), 404);

        $data = match ($resource) {
            'bills' => $this->presentBill(Bill::with(['farm.farmer', 'farmer', 'extensionOfficer'])->findOrFail($record)),
            'income' => $this->presentIncome(FinanceIncome::with(['farm.farmer', 'farmer'])->findOrFail($record)),
            'expenditure' => $this->presentExpense(FinanceExpense::with(['farm.farmer', 'farmer'])->findOrFail($record)),
        };

        return response()->json([
            'status' => true,
            'message' => 'Finance record retrieved successfully.',
            'data' => $data,
        ]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        abort_unless(in_array($resource, ['bills', 'income', 'expenditure'], true), 404);

        $data = match ($resource) {
            'bills' => $this->storeBill($request),
            'income' => $this->storeIncome($request),
            'expenditure' => $this->storeExpense($request),
        };

        return response()->json([
            'status' => true,
            'message' => 'Finance record created successfully.',
            'data' => $data,
        ], 201);
    }

    public function update(Request $request, string $resource, string $record): JsonResponse
    {
        abort_unless(in_array($resource, ['bills', 'income', 'expenditure'], true), 404);

        $data = match ($resource) {
            'bills' => $this->updateBill($request, Bill::findOrFail($record)),
            'income' => $this->updateIncome($request, FinanceIncome::findOrFail($record)),
            'expenditure' => $this->updateExpense($request, FinanceExpense::findOrFail($record)),
        };

        return response()->json([
            'status' => true,
            'message' => 'Finance record updated successfully.',
            'data' => $data,
        ]);
    }

    public function destroy(string $resource, string $record): JsonResponse
    {
        abort_unless(in_array($resource, ['bills', 'income', 'expenditure'], true), 404);

        match ($resource) {
            'bills' => Bill::findOrFail($record)->delete(),
            'income' => FinanceIncome::findOrFail($record)->delete(),
            'expenditure' => FinanceExpense::findOrFail($record)->delete(),
        };

        return response()->json([
            'status' => true,
            'message' => 'Finance record deleted successfully.',
        ]);
    }

    private function indexBills(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');
        $sort = (string) $request->query('sort', 'latest');
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);

        $totalBills = Bill::count();
        $paidCount = Bill::where('status', 'paid')->count();
        $pendingCount = Bill::where('status', 'pending')->count();
        $totalAmount = (float) Bill::sum('amount');

        $query = Bill::query()->with(['farm.farmer', 'farmer', 'extensionOfficer']);

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('billNo', 'like', "%{$search}%")
                    ->orWhere('subjectType', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('farm', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('referenceNo', 'like', "%{$search}%"))
                    ->orWhereHas('farmer', fn ($q) => $q->where('firstName', 'like', "%{$search}%")->orWhere('surname', 'like', "%{$search}%"))
                    ->orWhereHas('extensionOfficer', fn ($q) => $q->where('firstName', 'like', "%{$search}%")->orWhere('lastName', 'like', "%{$search}%"));
            });
        }

        match ($sort) {
            'oldest' => $query->orderBy('id'),
            'amount_desc' => $query->orderByDesc('amount'),
            'amount_asc' => $query->orderBy('amount'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        $paginator = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Bills retrieved successfully.',
            'data' => [
                'items' => collect($paginator->items())->map(fn (Bill $bill) => $this->presentBill($bill))->values(),
                'meta' => [
                    'currentPage' => $paginator->currentPage(),
                    'lastPage' => $paginator->lastPage(),
                    'perPage' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'deletedCount' => 0,
                ],
                'summary' => [
                    'total' => $totalBills,
                    'active' => $paidCount,
                    'secondary' => $pendingCount,
                    'tertiary' => (int) round($totalAmount),
                ],
                'options' => $this->optionsPayload(),
            ],
        ]);
    }

    private function indexIncome(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');
        $sort = (string) $request->query('sort', 'latest');
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);

        $totalCount = FinanceIncome::count();
        $receivedCount = FinanceIncome::where('status', 'received')->count();
        $pendingCount = FinanceIncome::where('status', 'pending')->count();
        $totalAmount = (float) FinanceIncome::sum('totalAmount');

        $query = FinanceIncome::query()->with(['farm.farmer', 'farmer']);

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('sourceType', 'like', "%{$search}%")
                    ->orWhere('referenceNo', 'like', "%{$search}%")
                    ->orWhere('subjectType', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('farm', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('referenceNo', 'like', "%{$search}%"))
                    ->orWhereHas('farmer', fn ($q) => $q->where('firstName', 'like', "%{$search}%")->orWhere('surname', 'like', "%{$search}%"));
            });
        }

        match ($sort) {
            'oldest' => $query->orderBy('id'),
            'amount_desc' => $query->orderByDesc('totalAmount'),
            'amount_asc' => $query->orderBy('totalAmount'),
            default => $query->orderByDesc('incomeDate')->orderByDesc('id'),
        };

        $paginator = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Finance income retrieved successfully.',
            'data' => [
                'items' => collect($paginator->items())->map(fn (FinanceIncome $item) => $this->presentIncome($item))->values(),
                'meta' => [
                    'currentPage' => $paginator->currentPage(),
                    'lastPage' => $paginator->lastPage(),
                    'perPage' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'deletedCount' => 0,
                ],
                'summary' => [
                    'total' => $totalCount,
                    'active' => $receivedCount,
                    'secondary' => $pendingCount,
                    'tertiary' => (int) round($totalAmount),
                ],
                'options' => $this->optionsPayload(),
            ],
        ]);
    }

    private function indexExpenditure(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');
        $sort = (string) $request->query('sort', 'latest');
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);

        $totalCount = FinanceExpense::count();
        $paidCount = FinanceExpense::whereIn('status', ['paid', 'completed'])->count();
        $pendingCount = FinanceExpense::where('status', 'pending')->count();
        $totalCost = (float) FinanceExpense::sum('totalCost');

        $query = FinanceExpense::query()->with(['farm.farmer', 'farmer']);

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('sourceType', 'like', "%{$search}%")
                    ->orWhere('billNo', 'like', "%{$search}%")
                    ->orWhere('subjectType', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('farm', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('referenceNo', 'like', "%{$search}%"))
                    ->orWhereHas('farmer', fn ($q) => $q->where('firstName', 'like', "%{$search}%")->orWhere('surname', 'like', "%{$search}%"));
            });
        }

        match ($sort) {
            'oldest' => $query->orderBy('id'),
            'amount_desc' => $query->orderByDesc('totalCost'),
            'amount_asc' => $query->orderBy('totalCost'),
            default => $query->orderByDesc('expenseDate')->orderByDesc('id'),
        };

        $paginator = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Finance expenditure retrieved successfully.',
            'data' => [
                'items' => collect($paginator->items())->map(fn (FinanceExpense $item) => $this->presentExpense($item))->values(),
                'meta' => [
                    'currentPage' => $paginator->currentPage(),
                    'lastPage' => $paginator->lastPage(),
                    'perPage' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'deletedCount' => 0,
                ],
                'summary' => [
                    'total' => $totalCount,
                    'active' => $paidCount,
                    'secondary' => $pendingCount,
                    'tertiary' => (int) round($totalCost),
                ],
                'options' => $this->optionsPayload(),
            ],
        ]);
    }

    private function indexIncomeExpenditureReport(Request $request): JsonResponse
    {
        $fromDate = $request->query('fromDate') ?: Carbon::now()->startOfMonth()->toDateString();
        $toDate = $request->query('toDate') ?: Carbon::now()->endOfDay()->toDateString();
        $farmUuid = $request->query('farmUuid') ?: null;
        $farmerId = $request->query('farmerId') ? (int) $request->query('farmerId') : null;
        $search = trim((string) $request->query('search', ''));

        $from = Carbon::parse($fromDate)->startOfDay();
        $to = Carbon::parse($toDate)->endOfDay();

        $incomeQuery = FinanceIncome::query()
            ->with(['farm.farmer', 'farmer'])
            ->where(function ($query) use ($from, $to): void {
                $query
                    ->whereBetween('incomeDate', [$from, $to])
                    ->orWhere(function ($q) use ($from, $to): void {
                        $q->whereNull('incomeDate')->whereBetween('created_at', [$from, $to]);
                    });
            });

        $disposalSalesQuery = Disposal::query()
            ->with(['farm.farmer', 'livestock', 'disposalType'])
            ->whereNotNull('salePrice')
            ->where(function ($query) use ($from, $to): void {
                $query
                    ->whereBetween('eventDate', [$from, $to])
                    ->orWhere(function ($q) use ($from, $to): void {
                        $q->whereNull('eventDate')->whereBetween('created_at', [$from, $to]);
                    });
            });

        $transferSalesQuery = Transfer::query()
            ->with(['fromFarm.farmer', 'toFarm', 'livestock'])
            ->whereNotNull('price')
            ->where(function ($query) use ($from, $to): void {
                $query
                    ->whereBetween('transferDate', [$from->toDateString(), $to->toDateString()])
                    ->orWhereBetween('eventDate', [$from, $to])
                    ->orWhere(function ($q) use ($from, $to): void {
                        $q->whereNull('transferDate')->whereNull('eventDate')->whereBetween('created_at', [$from, $to]);
                    });
            });

        $expenseQuery = FinanceExpense::query()
            ->with(['farm.farmer', 'farmer'])
            ->where(function ($query) use ($from, $to): void {
                $query
                    ->whereBetween('expenseDate', [$from, $to])
                    ->orWhere(function ($q) use ($from, $to): void {
                        $q->whereNull('expenseDate')->whereBetween('created_at', [$from, $to]);
                    });
            });

        $billQuery = Bill::query()
            ->with(['farm.farmer', 'farmer', 'extensionOfficer'])
            ->whereBetween('created_at', [$from, $to]);

        if (! empty($farmUuid)) {
            $incomeQuery->where('farmUuid', $farmUuid);
            $disposalSalesQuery->where('farmUuid', $farmUuid);
            $transferSalesQuery->where('farmUuid', $farmUuid);
            $expenseQuery->where('farmUuid', $farmUuid);
            $billQuery->where('farmUuid', $farmUuid);
        }

        if (! empty($farmerId)) {
            $incomeQuery->where(function ($q) use ($farmerId): void {
                $q->where('farmerId', $farmerId)
                    ->orWhereHas('farm', fn ($f) => $f->where('farmerId', $farmerId));
            });
            $disposalSalesQuery->whereHas('farm', fn ($q) => $q->where('farmerId', $farmerId));
            $transferSalesQuery->whereHas('fromFarm', fn ($q) => $q->where('farmerId', $farmerId));
            $expenseQuery->where(function ($q) use ($farmerId): void {
                $q->where('farmerId', $farmerId)
                    ->orWhereHas('farm', fn ($f) => $f->where('farmerId', $farmerId));
            });
            $billQuery->where(function ($q) use ($farmerId): void {
                $q->where('farmerId', $farmerId)
                    ->orWhereHas('farm', fn ($f) => $f->where('farmerId', $farmerId));
            });
        }

        $incomeRecords = $incomeQuery->orderByDesc('incomeDate')->orderByDesc('created_at')->limit(250)->get();
        $recordedDisposalUuids = $incomeRecords
            ->where('sourceType', 'disposal')
            ->pluck('sourceUuid')
            ->filter()
            ->values();

        if ($recordedDisposalUuids->isNotEmpty()) {
            $disposalSalesQuery->whereNotIn('uuid', $recordedDisposalUuids);
        }

        $disposalSales = $disposalSalesQuery->orderByDesc('eventDate')->orderByDesc('created_at')->limit(250)->get();
        $transferSales = $transferSalesQuery->orderByDesc('transferDate')->orderByDesc('eventDate')->orderByDesc('created_at')->limit(250)->get();
        $expenseRecords = $expenseQuery->orderByDesc('expenseDate')->orderByDesc('created_at')->limit(250)->get();
        $bills = $billQuery->orderByDesc('created_at')->limit(250)->get();

        $billExpenseSourceUuids = $expenseRecords
            ->where('sourceType', 'bill')
            ->pluck('sourceUuid')
            ->filter()
            ->values();

        $orphanBills = $bills->filter(function (Bill $bill) use ($billExpenseSourceUuids): bool {
            return empty($bill->uuid) || ! $billExpenseSourceUuids->contains($bill->uuid);
        });

        $incomeRows = collect();

        foreach ($incomeRecords as $income) {
            $farmer = $income->farmer ?? $income->farm?->farmer;
            $farmerName = $farmer ? trim(collect([$farmer->firstName, $farmer->middleName, $farmer->surname])->filter()->implode(' ')) : '-';
            $incomeRows->push([
                'date' => optional($income->incomeDate ?? $income->created_at)->format('Y-m-d H:i') ?? '-',
                'type' => strtoupper((string) ($income->sourceType ?? 'manual')),
                'reference' => $income->referenceNo ?? '-',
                'farm' => $income->farm?->name ?? '-',
                'farmer' => $farmerName ?: '-',
                'subject' => strtoupper((string) ($income->subjectType ?? '-')),
                'quantity' => (int) ($income->quantity ?? 1),
                'amount' => (float) ($income->totalAmount ?? 0),
                'status' => strtolower((string) ($income->status ?? 'pending')),
                'notes' => $income->notes ?? '-',
            ]);
        }

        foreach ($disposalSales as $disposal) {
            $farmer = $disposal->farm?->farmer;
            $farmerName = $farmer ? trim(collect([$farmer->firstName, $farmer->middleName, $farmer->surname])->filter()->implode(' ')) : '-';
            $incomeRows->push([
                'date' => optional($disposal->eventDate ? Carbon::parse($disposal->eventDate) : $disposal->created_at)->format('Y-m-d H:i') ?? '-',
                'type' => 'DISPOSAL SALE',
                'reference' => $disposal->uuid ? substr($disposal->uuid, 0, 8) : '-',
                'farm' => $disposal->farm?->name ?? '-',
                'farmer' => $farmerName ?: '-',
                'subject' => $disposal->livestock?->identificationNumber ?? ($disposal->disposalType?->name ?? 'Disposal'),
                'quantity' => 1,
                'amount' => (float) ($disposal->salePrice ?? 0),
                'status' => strtolower((string) ($disposal->status ?? 'completed')),
                'notes' => trim(($disposal->buyerName ? 'Buyer: '.$disposal->buyerName.'. ' : '').($disposal->reasons ?? '')),
            ]);
        }

        foreach ($transferSales as $transfer) {
            $farmer = $transfer->fromFarm?->farmer;
            $farmerName = $farmer ? trim(collect([$farmer->firstName, $farmer->middleName, $farmer->surname])->filter()->implode(' ')) : '-';
            $dateSource = $transfer->transferDate ?? $transfer->eventDate ?? $transfer->created_at;
            $incomeRows->push([
                'date' => optional($dateSource ? Carbon::parse($dateSource) : null)->format('Y-m-d H:i') ?? '-',
                'type' => 'TRANSFER SALE',
                'reference' => $transfer->uuid ? substr($transfer->uuid, 0, 8) : '-',
                'farm' => $transfer->fromFarm?->name ?? '-',
                'farmer' => $farmerName ?: '-',
                'subject' => $transfer->livestock?->identificationNumber ?? 'Livestock transfer',
                'quantity' => 1,
                'amount' => (float) ($transfer->price ?? 0),
                'status' => strtolower((string) ($transfer->status ?? 'completed')),
                'notes' => trim(($transfer->toFarm?->name ? 'To farm: '.$transfer->toFarm->name.'. ' : '').($transfer->reason ?? '')),
            ]);
        }

        $expenseRows = collect();

        foreach ($expenseRecords as $expense) {
            $farmer = $expense->farmer ?? $expense->farm?->farmer;
            $farmerName = $farmer ? trim(collect([$farmer->firstName, $farmer->middleName, $farmer->surname])->filter()->implode(' ')) : '-';
            $expenseRows->push([
                'date' => optional($expense->expenseDate ?? $expense->created_at)->format('Y-m-d H:i') ?? '-',
                'type' => strtoupper((string) ($expense->sourceType ?? 'manual')),
                'reference' => $expense->billNo ?? '-',
                'farm' => $expense->farm?->name ?? '-',
                'farmer' => $farmerName ?: '-',
                'subject' => strtoupper((string) ($expense->subjectType ?? '-')),
                'quantity' => (int) ($expense->quantity ?? 1),
                'amount' => (float) ($expense->totalCost ?? 0),
                'status' => strtolower((string) ($expense->status ?? 'pending')),
                'notes' => $expense->notes ?? '-',
            ]);
        }

        foreach ($orphanBills as $bill) {
            $farmer = $bill->farmer ?? $bill->farm?->farmer;
            $farmerName = $farmer ? trim(collect([$farmer->firstName, $farmer->middleName, $farmer->surname])->filter()->implode(' ')) : '-';
            $expenseRows->push([
                'date' => optional($bill->created_at)->format('Y-m-d H:i') ?? '-',
                'type' => 'BILL (AUTO)',
                'reference' => $bill->billNo ?? '-',
                'farm' => $bill->farm?->name ?? '-',
                'farmer' => $farmerName ?: '-',
                'subject' => strtoupper((string) ($bill->subjectType ?? '-')),
                'quantity' => (int) ($bill->quantity ?? 1),
                'amount' => (float) ($bill->amount ?? 0),
                'status' => strtolower((string) ($bill->status ?? 'pending')),
                'notes' => $bill->notes ?? '-',
            ]);
        }

        $billRows = $bills->map(function (Bill $bill): array {
            $farmer = $bill->farmer ?? $bill->farm?->farmer;
            $farmerName = $farmer ? trim(collect([$farmer->firstName, $farmer->middleName, $farmer->surname])->filter()->implode(' ')) : '-';
            $officer = $bill->extensionOfficer;
            $officerName = $officer ? trim(collect([$officer->firstName, $officer->middleName, $officer->lastName])->filter()->implode(' ')) : '-';

            return [
                'billNo' => $bill->billNo ?? '-',
                'date' => optional($bill->created_at)->format('Y-m-d H:i') ?? '-',
                'farm' => $bill->farm?->name ?? '-',
                'farmer' => $farmerName ?: '-',
                'officer' => $officerName ?: '-',
                'subject' => strtoupper((string) ($bill->subjectType ?? '-')),
                'quantity' => (int) ($bill->quantity ?? 1),
                'amount' => (float) ($bill->amount ?? 0),
                'status' => strtolower((string) ($bill->status ?? 'pending')),
                'notes' => $bill->notes ?? '-',
            ];
        })->values();

        if ($search !== '') {
            $incomeRows = $this->filterReportRows($incomeRows, $search);
            $expenseRows = $this->filterReportRows($expenseRows, $search);
            $billRows = $this->filterReportRows($billRows, $search);
        }

        $incomeRows = $incomeRows->sortByDesc('date')->values();
        $expenseRows = $expenseRows->sortByDesc('date')->values();

        $totalIncome = (float) $incomeRows->sum('amount');
        $totalExpenditure = (float) $expenseRows->sum('amount');
        $net = $totalIncome - $totalExpenditure;

        return response()->json([
            'status' => true,
            'message' => 'Income and expenditure report retrieved successfully.',
            'data' => [
                'summary' => [
                    'totalIncome' => $totalIncome,
                    'totalExpenditure' => $totalExpenditure,
                    'net' => $net,
                    'totalIncomeRows' => $incomeRows->count(),
                    'totalExpenseRows' => $expenseRows->count(),
                    'totalBillRows' => $billRows->count(),
                ],
                'incomeRows' => $incomeRows->take(100)->values()->all(),
                'expenseRows' => $expenseRows->take(100)->values()->all(),
                'billRows' => $billRows->take(100)->values()->all(),
                'filters' => [
                    'fromDate' => $from->toDateString(),
                    'toDate' => $to->toDateString(),
                    'farmUuid' => $farmUuid,
                    'farmerId' => $farmerId,
                    'search' => $search,
                ],
                'options' => $this->optionsPayload(),
            ],
        ]);
    }

    private function filterReportRows(Collection $rows, string $search): Collection
    {
        $needle = mb_strtolower($search);

        return $rows->filter(function (array $row) use ($needle): bool {
            $haystack = mb_strtolower(implode(' ', array_map(fn ($v) => (string) $v, $row)));

            return str_contains($haystack, $needle);
        })->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBill(Bill $bill): array
    {
        $farmer = $bill->farmer ?? $bill->farm?->farmer;
        $farmerName = $farmer
            ? trim(collect([$farmer->firstName, $farmer->middleName, $farmer->surname])->filter()->implode(' '))
            : '—';

        $officer = $bill->extensionOfficer;
        $officerName = $officer
            ? trim(collect([$officer->firstName, $officer->middleName, $officer->lastName])->filter()->implode(' '))
            : '—';

        return [
            'id' => $bill->id,
            'billNo' => $bill->billNo,
            'farmUuid' => $bill->farmUuid,
            'farmName' => $bill->farm?->name ?? '—',
            'farmerId' => $bill->farmerId ?? $bill->farm?->farmerId,
            'farmerName' => $farmerName ?: '—',
            'extensionOfficerId' => $bill->extensionOfficerId,
            'extensionOfficerName' => $officerName ?: '—',
            'subjectType' => $bill->subjectType,
            'quantity' => (int) ($bill->quantity ?? 1),
            'amount' => (float) ($bill->amount ?? 0),
            'status' => strtolower((string) ($bill->status ?? 'pending')),
            'notes' => $bill->notes,
            'createdAt' => $bill->created_at?->toISOString(),
            'updatedAt' => $bill->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentIncome(FinanceIncome $income): array
    {
        $farmer = $income->farmer ?? $income->farm?->farmer;
        $farmerName = $farmer
            ? trim(collect([$farmer->firstName, $farmer->middleName, $farmer->surname])->filter()->implode(' '))
            : '—';

        return [
            'id' => $income->id,
            'sourceType' => $income->sourceType ?? 'manual',
            'farmUuid' => $income->farmUuid,
            'farmName' => $income->farm?->name ?? '—',
            'farmerId' => $income->farmerId ?? $income->farm?->farmerId,
            'farmerName' => $farmerName ?: '—',
            'referenceNo' => $income->referenceNo,
            'subjectType' => $income->subjectType,
            'quantity' => (int) ($income->quantity ?? 1),
            'unitAmount' => (float) ($income->unitAmount ?? 0),
            'totalAmount' => (float) ($income->totalAmount ?? 0),
            'status' => strtolower((string) ($income->status ?? 'pending')),
            'incomeDate' => $income->incomeDate?->toISOString(),
            'notes' => $income->notes,
            'createdAt' => $income->created_at?->toISOString(),
            'updatedAt' => $income->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentExpense(FinanceExpense $expense): array
    {
        $farmer = $expense->farmer ?? $expense->farm?->farmer;
        $farmerName = $farmer
            ? trim(collect([$farmer->firstName, $farmer->middleName, $farmer->surname])->filter()->implode(' '))
            : '—';

        return [
            'id' => $expense->id,
            'sourceType' => $expense->sourceType ?? 'manual',
            'farmUuid' => $expense->farmUuid,
            'farmName' => $expense->farm?->name ?? '—',
            'farmerId' => $expense->farmerId ?? $expense->farm?->farmerId,
            'farmerName' => $farmerName ?: '—',
            'billNo' => $expense->billNo,
            'subjectType' => $expense->subjectType,
            'quantity' => (int) ($expense->quantity ?? 1),
            'unitCost' => (float) ($expense->unitCost ?? 0),
            'totalCost' => (float) ($expense->totalCost ?? 0),
            'status' => strtolower((string) ($expense->status ?? 'pending')),
            'expenseDate' => $expense->expenseDate?->toISOString(),
            'notes' => $expense->notes,
            'createdAt' => $expense->created_at?->toISOString(),
            'updatedAt' => $expense->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storeBill(Request $request): array
    {
        $data = $request->validate([
            'billNo' => ['nullable', 'string', 'max:100'],
            'farmUuid' => ['required', 'string', 'exists:farms,uuid'],
            'farmerId' => ['nullable', 'integer', 'exists:farmers,id'],
            'extensionOfficerId' => ['nullable', 'integer', 'exists:extension_officers,id'],
            'subjectType' => ['required', 'string', 'max:120'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'in:pending,paid'],
            'notes' => ['nullable', 'string'],
        ]);

        $farm = Farm::where('uuid', $data['farmUuid'])->first();
        $farmerId = $data['farmerId'] ?? $farm?->farmerId;

        $created = Bill::create([
            'uuid' => (string) Str::uuid(),
            'billNo' => ! empty($data['billNo']) ? $data['billNo'] : '',
            'farmUuid' => $data['farmUuid'],
            'farmerId' => $farmerId,
            'extensionOfficerId' => $data['extensionOfficerId'] ?? null,
            'subjectType' => $data['subjectType'],
            'subjectUuid' => (string) Str::uuid(),
            'quantity' => (int) ($data['quantity'] ?? 1),
            'amount' => $data['amount'],
            'status' => $data['status'] ?? 'pending',
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->presentBill($created->load(['farm.farmer', 'farmer', 'extensionOfficer']));
    }

    /**
     * @return array<string, mixed>
     */
    private function updateBill(Request $request, Bill $bill): array
    {
        if ($request->boolean('markPaid')) {
            $bill->update(['status' => 'paid']);

            return $this->presentBill($bill->fresh()->load(['farm.farmer', 'farmer', 'extensionOfficer']));
        }

        $data = $request->validate([
            'billNo' => ['nullable', 'string', 'max:100'],
            'farmUuid' => ['required', 'string', 'exists:farms,uuid'],
            'farmerId' => ['nullable', 'integer', 'exists:farmers,id'],
            'extensionOfficerId' => ['nullable', 'integer', 'exists:extension_officers,id'],
            'subjectType' => ['required', 'string', 'max:120'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'in:pending,paid'],
            'notes' => ['nullable', 'string'],
        ]);

        $farm = Farm::where('uuid', $data['farmUuid'])->first();
        $farmerId = $data['farmerId'] ?? $farm?->farmerId ?? $bill->farmerId;

        $bill->update([
            'billNo' => ! empty($data['billNo']) ? $data['billNo'] : $bill->billNo,
            'farmUuid' => $data['farmUuid'],
            'farmerId' => $farmerId,
            'extensionOfficerId' => $data['extensionOfficerId'] ?? null,
            'subjectType' => $data['subjectType'],
            'quantity' => (int) ($data['quantity'] ?? 1),
            'amount' => $data['amount'],
            'status' => $data['status'] ?? $bill->status,
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->presentBill($bill->fresh()->load(['farm.farmer', 'farmer', 'extensionOfficer']));
    }

    /**
     * @return array<string, mixed>
     */
    private function storeIncome(Request $request): array
    {
        $data = $request->validate([
            'sourceType' => ['required', 'string', 'max:100'],
            'farmUuid' => ['required', 'string', 'exists:farms,uuid'],
            'farmerId' => ['nullable', 'integer', 'exists:farmers,id'],
            'referenceNo' => ['nullable', 'string', 'max:120'],
            'subjectType' => ['nullable', 'string', 'max:120'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'unitAmount' => ['required', 'numeric', 'min:0'],
            'totalAmount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'in:pending,received'],
            'incomeDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $farm = Farm::where('uuid', $data['farmUuid'])->first();
        $farmerId = $data['farmerId'] ?? $farm?->farmerId;
        $qty = max((int) ($data['quantity'] ?? 1), 1);
        $unitAmount = (float) $data['unitAmount'];
        $totalAmount = isset($data['totalAmount']) && (float) $data['totalAmount'] > 0
            ? (float) $data['totalAmount']
            : round($qty * $unitAmount, 2);

        $created = FinanceIncome::create([
            'uuid' => (string) Str::uuid(),
            'sourceType' => $data['sourceType'],
            'sourceUuid' => (string) Str::uuid(),
            'farmUuid' => $data['farmUuid'],
            'farmerId' => $farmerId,
            'referenceNo' => $data['referenceNo'] ?? null,
            'subjectType' => $data['subjectType'] ?? null,
            'quantity' => $qty,
            'unitAmount' => $unitAmount,
            'totalAmount' => $totalAmount,
            'status' => $data['status'] ?? 'pending',
            'incomeDate' => $data['incomeDate'] ?? now(),
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->presentIncome($created->load(['farm.farmer', 'farmer']));
    }

    /**
     * @return array<string, mixed>
     */
    private function updateIncome(Request $request, FinanceIncome $income): array
    {
        $data = $request->validate([
            'sourceType' => ['required', 'string', 'max:100'],
            'farmUuid' => ['required', 'string', 'exists:farms,uuid'],
            'farmerId' => ['nullable', 'integer', 'exists:farmers,id'],
            'referenceNo' => ['nullable', 'string', 'max:120'],
            'subjectType' => ['nullable', 'string', 'max:120'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'unitAmount' => ['required', 'numeric', 'min:0'],
            'totalAmount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'in:pending,received'],
            'incomeDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $farm = Farm::where('uuid', $data['farmUuid'])->first();
        $farmerId = $data['farmerId'] ?? $farm?->farmerId ?? $income->farmerId;
        $qty = max((int) ($data['quantity'] ?? 1), 1);
        $unitAmount = (float) $data['unitAmount'];
        $totalAmount = isset($data['totalAmount']) && (float) $data['totalAmount'] > 0
            ? (float) $data['totalAmount']
            : round($qty * $unitAmount, 2);

        $income->update([
            'sourceType' => $data['sourceType'],
            'farmUuid' => $data['farmUuid'],
            'farmerId' => $farmerId,
            'referenceNo' => $data['referenceNo'] ?? null,
            'subjectType' => $data['subjectType'] ?? null,
            'quantity' => $qty,
            'unitAmount' => $unitAmount,
            'totalAmount' => $totalAmount,
            'status' => $data['status'] ?? $income->status,
            'incomeDate' => $data['incomeDate'] ?? $income->incomeDate,
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->presentIncome($income->fresh()->load(['farm.farmer', 'farmer']));
    }

    /**
     * @return array<string, mixed>
     */
    private function storeExpense(Request $request): array
    {
        $data = $request->validate([
            'sourceType' => ['required', 'string', 'max:100'],
            'farmUuid' => ['required', 'string', 'exists:farms,uuid'],
            'farmerId' => ['nullable', 'integer', 'exists:farmers,id'],
            'billNo' => ['nullable', 'string', 'max:120'],
            'subjectType' => ['nullable', 'string', 'max:120'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'unitCost' => ['required', 'numeric', 'min:0'],
            'totalCost' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'in:pending,paid,completed'],
            'expenseDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $farm = Farm::where('uuid', $data['farmUuid'])->first();
        $farmerId = $data['farmerId'] ?? $farm?->farmerId;
        $qty = max((int) ($data['quantity'] ?? 1), 1);
        $unitCost = (float) $data['unitCost'];
        $totalCost = isset($data['totalCost']) && (float) $data['totalCost'] > 0
            ? (float) $data['totalCost']
            : round($qty * $unitCost, 2);

        $created = FinanceExpense::create([
            'uuid' => (string) Str::uuid(),
            'sourceType' => $data['sourceType'],
            'sourceUuid' => (string) Str::uuid(),
            'farmUuid' => $data['farmUuid'],
            'farmerId' => $farmerId,
            'billNo' => $data['billNo'] ?? null,
            'subjectType' => $data['subjectType'] ?? null,
            'quantity' => $qty,
            'unitCost' => $unitCost,
            'totalCost' => $totalCost,
            'status' => $data['status'] ?? 'pending',
            'expenseDate' => $data['expenseDate'] ?? now(),
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->presentExpense($created->load(['farm.farmer', 'farmer']));
    }

    /**
     * @return array<string, mixed>
     */
    private function updateExpense(Request $request, FinanceExpense $expense): array
    {
        $data = $request->validate([
            'sourceType' => ['required', 'string', 'max:100'],
            'farmUuid' => ['required', 'string', 'exists:farms,uuid'],
            'farmerId' => ['nullable', 'integer', 'exists:farmers,id'],
            'billNo' => ['nullable', 'string', 'max:120'],
            'subjectType' => ['nullable', 'string', 'max:120'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'unitCost' => ['required', 'numeric', 'min:0'],
            'totalCost' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'in:pending,paid,completed'],
            'expenseDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $farm = Farm::where('uuid', $data['farmUuid'])->first();
        $farmerId = $data['farmerId'] ?? $farm?->farmerId ?? $expense->farmerId;
        $qty = max((int) ($data['quantity'] ?? 1), 1);
        $unitCost = (float) $data['unitCost'];
        $totalCost = isset($data['totalCost']) && (float) $data['totalCost'] > 0
            ? (float) $data['totalCost']
            : round($qty * $unitCost, 2);

        $expense->update([
            'sourceType' => $data['sourceType'],
            'farmUuid' => $data['farmUuid'],
            'farmerId' => $farmerId,
            'billNo' => $data['billNo'] ?? null,
            'subjectType' => $data['subjectType'] ?? null,
            'quantity' => $qty,
            'unitCost' => $unitCost,
            'totalCost' => $totalCost,
            'status' => $data['status'] ?? $expense->status,
            'expenseDate' => $data['expenseDate'] ?? $expense->expenseDate,
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->presentExpense($expense->fresh()->load(['farm.farmer', 'farmer']));
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsPayload(): array
    {
        $farms = Farm::query()
            ->with('farmer:id,firstName,middleName,surname')
            ->orderBy('name')
            ->limit(250)
            ->get(['id', 'uuid', 'name', 'referenceNo', 'farmerId'])
            ->map(function (Farm $farm): array {
                $farmerName = $farm->farmer
                    ? trim(collect([$farm->farmer->firstName, $farm->farmer->middleName, $farm->farmer->surname])->filter()->implode(' '))
                    : null;

                return [
                    'value' => $farm->uuid,
                    'label' => $farm->name,
                    'description' => collect([$farm->referenceNo, $farmerName ? "Farmer: {$farmerName}" : null])->filter()->implode(' · '),
                ];
            })
            ->values();

        $farmers = Farmer::query()
            ->orderBy('firstName')
            ->orderBy('surname')
            ->limit(250)
            ->get(['id', 'firstName', 'middleName', 'surname', 'farmerNo', 'phone1'])
            ->map(fn (Farmer $farmer) => [
                'value' => (string) $farmer->id,
                'label' => trim(collect([$farmer->firstName, $farmer->middleName, $farmer->surname])->filter()->implode(' ')),
                'description' => collect([$farmer->farmerNo, $farmer->phone1])->filter()->implode(' · '),
            ])
            ->values();

        $extensionOfficers = ExtensionOfficer::query()
            ->orderBy('firstName')
            ->orderBy('lastName')
            ->limit(250)
            ->get(['id', 'firstName', 'middleName', 'lastName', 'email', 'phone'])
            ->map(fn (ExtensionOfficer $officer) => [
                'value' => (string) $officer->id,
                'label' => trim(collect([$officer->firstName, $officer->middleName, $officer->lastName])->filter()->implode(' ')),
                'description' => collect([$officer->email, $officer->phone])->filter()->implode(' · '),
            ])
            ->values();

        return [
            'farms' => $farms,
            'farmers' => $farmers,
            'extensionOfficers' => $extensionOfficers,
        ];
    }
}

