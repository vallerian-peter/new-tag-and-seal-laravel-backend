<?php

namespace App\Services\Marketplace;

use App\Models\AnimalCommerceReservation;
use App\Models\Livestock;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingItem;
use App\Models\User;
use App\Services\Dynamic\FarmAccessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Creates a listing for [seller]: the seller must manage the farm, and every
 * animal must be active, unlisted and not held. Used by the app and the portal.
 */
class MarketplaceListings
{
    /** @param  UploadedFile[]  $photoFiles */
    public function create(User $seller, array $data, array $photoFiles = []): MarketplaceListing
    {
        $isGroup = $data['listing_type'] === 'group_lot';
        $animalUuids = $data['animal_uuids'] ?? [];
        abort_if(! $isGroup && empty($animalUuids), 422, 'Single listing requires an animal selection.');
        abort_if($isGroup && empty($animalUuids), 422, 'Group listings must include the livestock records that make up the listed stock.');
        abort_if(count(array_unique($animalUuids)) !== count($animalUuids), 422, 'A livestock record cannot be listed more than once.');
        app(MarketplaceOrderContext::class)->releaseExpiredHolds();

        $storedPhotos = [];
        try {
            return DB::transaction(function () use ($seller, $data, $isGroup, $animalUuids, $photoFiles, &$storedPhotos) {
                $animals = Livestock::whereIn('uuid', $animalUuids)->lockForUpdate()->get();
                abort_unless($animals->count() === count($animalUuids), 422, 'One or more livestock records could not be found.');
                abort_unless($animals->every(fn ($animal) => $animal->status === 'active'), 422, 'Only active livestock can be listed.');
                $farmUuids = $animals->pluck('farmUuid')->unique();
                abort_unless($farmUuids->count() === 1, 422, 'A listing can only contain livestock from one farm.');
                $farmUuid = (string) $farmUuids->first();
                abort_if(isset($data['farm_uuid']) && $data['farm_uuid'] !== $farmUuid, 422, 'Selected livestock do not belong to the selected farm.');
                abort_unless(app(FarmAccessService::class)->can($seller, $farmUuid, 'livestock.manage'), 403, 'You do not have permission to list livestock from this farm.');
                abort_if(AnimalCommerceReservation::whereIn('livestock_uuid', $animalUuids)->where('status', 'active')->exists(),
                    422, 'One or more selected animals are reserved by another marketplace action.');
                abort_if(MarketplaceListingItem::whereIn('livestock_uuid', $animalUuids)
                    ->whereHas('listing', fn ($q) => $q->whereIn('status', ['active', 'reserved', 'under_negotiation']))
                    ->exists(), 422, 'One or more selected livestock are already listed on sale in the marketplace.');

                $quantity = count($animalUuids);
                $unitPrice = $data['unit_price'];
                foreach ($photoFiles as $file) {
                    $path = $file->storeAs('marketplace-photos', Str::uuid().'.'.$file->extension(), 'local');
                    if ($path === false) {
                        throw new \RuntimeException('Unable to store marketplace photo.');
                    }
                    $storedPhotos[] = $path;
                }
                $listing = MarketplaceListing::create([
                    'seller_user_id' => $seller->id,
                    'farm_uuid' => $farmUuid,
                    'listing_title' => $data['listing_title'],
                    'listing_type' => $data['listing_type'],
                    'group_sale_type' => $data['group_sale_type'] ?? ($isGroup ? 'whole_lot' : null),
                    'total_quantity' => $quantity,
                    'available_quantity' => $quantity,
                    'reserved_quantity' => 0,
                    'sold_quantity' => 0,
                    'unit_price' => $unitPrice,
                    'total_asking_price' => $unitPrice * $quantity,
                    'bulk_threshold' => $data['bulk_threshold'] ?? null,
                    'bulk_price' => $data['bulk_price'] ?? null,
                    'min_order_quantity' => $data['min_order_quantity'] ?? 1,
                    'max_order_quantity' => $data['max_order_quantity'] ?? $quantity,
                    'status' => 'active',
                    'description' => $data['description'] ?? null,
                    'location_name' => $data['location_name'] ?? null,
                    'photos' => array_map('basename', $storedPhotos),
                    'published_at' => now(),
                ]);

                foreach ($animalUuids as $uuid) {
                    MarketplaceListingItem::create([
                        'listing_uuid' => $listing->uuid,
                        'livestock_uuid' => $uuid,
                        'allocated_asking_price' => $unitPrice,
                    ]);
                    AnimalCommerceReservation::create([
                        'livestock_uuid' => $uuid,
                        'reservation_type' => 'marketplace_listing',
                        'reference_uuid' => $listing->uuid,
                        'status' => 'active',
                        'held_by_user_id' => $seller->id,
                    ]);
                }
                Livestock::whereIn('uuid', $animalUuids)->update(['status' => 'onSale', 'updated_at' => now()]);

                return $listing->load('items.livestock');
            });
        } catch (\Throwable $error) {
            if ($storedPhotos) {
                Storage::disk('local')->delete($storedPhotos);
            }
            throw $error;
        }
    }
}
