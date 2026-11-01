<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnimalCommerceReservation;
use App\Models\MarketplaceListing;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    private function indexListings(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);

        $query = MarketplaceListing::query()->with(['seller', 'farm', 'items.livestock']);

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($b) use ($search) {
                $b->where('listing_title', 'like', "%{$search}%")
                  ->orWhere('uuid', 'like', "%{$search}%");
            });
        }

        $paginated = $query->latest()->paginate($perPage);

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
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $status = (string) $request->query('status', 'all');

        $query = AnimalCommerceReservation::query()->with(['livestock', 'heldBy']);

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $paginated = $query->latest()->paginate($perPage);

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
                    'active_reservations' => AnimalCommerceReservation::where('status', 'active')->count(),
                ],
            ],
        ]);
    }

    private function indexOrders(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $status = (string) $request->query('status', 'all');

        $query = PurchaseOrder::query()->with(['seller', 'buyer', 'originFarm', 'items.livestock']);

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $paginated = $query->latest()->paginate($perPage);

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
}

