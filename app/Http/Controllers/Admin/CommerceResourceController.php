<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnimalCommerceReservation;
use App\Models\MarketplaceListing;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommerceResourceController extends Controller
{
    public const RESOURCES = [
        'listings',
        'reservations',
        'orders',
    ];

    public function index(Request $request, string $resource): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        return match ($resource) {
            'listings' => $this->indexListings($request),
            'reservations' => $this->indexReservations($request),
            'orders' => $this->indexOrders($request),
        };
    }

    public function options(string $resource): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        return response()->json([
            'status' => true,
            'message' => 'Commerce options retrieved successfully.',
            'data' => [
                'listing_statuses' => ['draft', 'active', 'under_negotiation', 'reserved', 'sold', 'withdrawn', 'expired'],
                'reservation_types' => ['marketplace_listing', 'offer_hold', 'order_commitment'],
                'reservation_statuses' => ['active', 'fulfilled', 'released', 'expired'],
                'order_statuses' => ['pending_settlement', 'ready_for_handover', 'handed_over', 'transferred', 'cancelled', 'disputed'],
                'settlement_methods' => ['cash_on_delivery', 'direct_mobile_money', 'bank_transfer', 'escrow'],
            ],
        ]);
    }

    public function show(string $resource, string $record): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        $data = match ($resource) {
            'listings' => MarketplaceListing::with(['seller', 'farm', 'items.livestock'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'reservations' => AnimalCommerceReservation::with(['livestock', 'heldBy'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'orders' => PurchaseOrder::with(['seller', 'buyer', 'originFarm', 'destinationFarm', 'items.livestock'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
        };

        return response()->json([
            'status' => true,
            'message' => 'Record retrieved successfully.',
            'data' => $data,
        ]);
    }

    /** Resolve a paid-order dispute after the refund or continuation decision is verified. */
    public function resolveOrderDispute(Request $request, string $order): JsonResponse
    {
        $data = $request->validate([
            'resolution' => ['required', 'in:continue,refund_complete'],
            'resolution_note' => ['required', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($order, $data, $request): JsonResponse {
            $purchaseOrder = PurchaseOrder::with('items')
                ->where('uuid', $order)
                ->orWhere('order_code', $order)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($purchaseOrder->status === 'disputed', 422, 'Only disputed orders can be resolved.');

            $history = trim(($purchaseOrder->dispute_reason ?? '')."\nAdmin resolution: ".$data['resolution_note']);
            if ($data['resolution'] === 'continue') {
                abort_unless($purchaseOrder->payment_evidence_submitted_at, 422, 'Payment evidence must exist before continuing the order.');
                $purchaseOrder->update([
                    'status' => 'ready_for_handover',
                    'settlement_verified_at' => $purchaseOrder->settlement_verified_at ?? now(),
                    'settlement_verified_by_user_id' => $purchaseOrder->settlement_verified_by_user_id ?? $request->user()->id,
                    'dispute_reason' => $history,
                ]);
            } else {
                foreach ($purchaseOrder->items as $item) {
                    $listing = $item->listing_uuid
                        ? MarketplaceListing::where('uuid', $item->listing_uuid)->lockForUpdate()->first()
                        : null;
                    if ($listing) {
                        $listing->increment('available_quantity', $item->quantity);
                        $listing->decrement('reserved_quantity', min($listing->reserved_quantity, $item->quantity));
                        if ($listing->status === 'sold' && $listing->available_quantity > 0) {
                            $listing->update(['status' => 'active']);
                        }
                    }
                }
                AnimalCommerceReservation::where('reference_uuid', $purchaseOrder->uuid)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'released',
                        'released_at' => now(),
                        'release_reason' => 'Admin confirmed refund and resolved dispute.',
                    ]);
                $purchaseOrder->update([
                    'status' => 'cancelled',
                    'dispute_reason' => $history,
                    'cancellation_reason' => 'Admin confirmed refund completion.',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Disputed order resolved.',
                'data' => $purchaseOrder->fresh(['items.livestock', 'seller', 'buyer']),
            ]);
        });
    }

    private function indexListings(Request $request): JsonResponse
    {
        $query = MarketplaceListing::query()->with(['seller', 'farm', 'items.livestock']);
        $paginated = $this->filteredPage($request, $query,
            ['listing_title', 'uuid'],
            ['seller' => ['username', 'email'], 'farm' => ['name']],
            ['draft', 'active', 'under_negotiation', 'reserved', 'sold', 'withdrawn', 'expired']);

        return response()->json([
            'status' => true,
            'message' => 'Marketplace listings retrieved successfully.',
            'data' => [
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
                'meta' => [
                    'total_listings' => MarketplaceListing::count(),
                    'active_listings' => MarketplaceListing::where('status', 'active')->count(),
                    'sold_listings' => MarketplaceListing::where('status', 'sold')->count(),
                ],
            ],
        ]);
    }

    private function indexReservations(Request $request): JsonResponse
    {
        $query = AnimalCommerceReservation::query()->with(['livestock', 'heldBy']);
        $paginated = $this->filteredPage($request, $query,
            ['uuid', 'livestock_uuid', 'reference_uuid'],
            ['livestock' => ['name', 'identificationNumber'], 'heldBy' => ['username', 'email']],
            ['active', 'fulfilled', 'released', 'expired']);

        return response()->json([
            'status' => true,
            'message' => 'Animal reservations retrieved successfully.',
            'data' => [
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
                'meta' => [
                    'total_reservations' => AnimalCommerceReservation::count(),
                    'active_reservations' => AnimalCommerceReservation::where('status', 'active')->count(),
                    'fulfilled_reservations' => AnimalCommerceReservation::where('status', 'fulfilled')->count(),
                ],
            ],
        ]);
    }

    private function indexOrders(Request $request): JsonResponse
    {
        $query = PurchaseOrder::query()->with(['seller', 'buyer', 'originFarm', 'items.livestock']);
        $paginated = $this->filteredPage($request, $query,
            ['order_code', 'uuid'],
            ['seller' => ['username', 'email'], 'buyer' => ['username', 'email']],
            ['pending_settlement', 'ready_for_handover', 'handed_over', 'transferred', 'cancelled', 'disputed']);

        return response()->json([
            'status' => true,
            'message' => 'Purchase orders retrieved successfully.',
            'data' => [
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
                'meta' => [
                    'total_orders' => PurchaseOrder::count(),
                    'pending_orders' => PurchaseOrder::where('status', 'pending_settlement')->count(),
                    'transferred_orders' => PurchaseOrder::where('status', 'transferred')->count(),
                ],
            ],
        ]);
    }
    /** Search and status apply before pagination, with deterministic ordering across pages. */
    private function filteredPage(Request $request, Builder $query, array $columns, array $relations, array $statuses): LengthAwarePaginator
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', Rule::in(array_merge(['all'], $statuses))],
            'sort' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'all';
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $query->where(function (Builder $matches) use ($search, $columns, $relations) {
                foreach ($columns as $column) {
                    $matches->orWhere($column, 'like', '%'.$search.'%');
                }
                foreach ($relations as $relation => $fields) {
                    $matches->orWhereHas($relation, function (Builder $related) use ($search, $fields) {
                        $related->where(function (Builder $names) use ($search, $fields) {
                            foreach ($fields as $field) {
                                $names->orWhere($field, 'like', '%'.$search.'%');
                            }
                        });
                    });
                }
            });
        }
        $direction = $filters['sort'] ?? 'desc';

        return $query->orderBy('created_at', $direction)->orderBy('id', $direction)->paginate($filters['per_page'] ?? 10);
    }

}

