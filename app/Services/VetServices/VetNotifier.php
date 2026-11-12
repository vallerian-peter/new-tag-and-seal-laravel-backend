<?php

namespace App\Services\VetServices;

use App\Jobs\SendVetServiceSms;
use App\Models\Vet;
use App\Models\VetServiceRequest;
use Illuminate\Support\Collection;

/**
 * Out-of-band notifications (SMS today; push can be added here later without
 * touching the request flow). Everything is dispatched after the response.
 */
class VetNotifier
{
    /** @param Collection<int, Vet> $vets */
    public function newRequestOffered(VetServiceRequest $request, Collection $vets): void
    {
        $phones = $vets->pluck('phoneNumber')->filter()->values()->all();
        if (empty($phones)) {
            return;
        }

        $animal = $request->livestockSnapshot['name'] ?? $request->livestockSnapshot['identificationNumber'] ?? 'an animal';
        $urgency = strtoupper($request->urgency);
        $message = "Tag & Seal: New {$urgency} vet request for {$animal} ({$request->problemCategory}) "
            ."near {$this->placeLabel($request)}. Open the app to accept.";

        SendVetServiceSms::dispatchAfterResponse($phones, $message);
    }

    public function requestAccepted(VetServiceRequest $request, Vet $vet): void
    {
        $phone = $this->farmerPhone($request);
        if (! $phone) {
            return;
        }
        $message = "Tag & Seal: Dr. {$vet->fullName} accepted your vet request and will contact you on {$vet->phoneNumber}.";
        SendVetServiceSms::dispatchAfterResponse([$phone], $message);
    }

    public function statusChanged(VetServiceRequest $request, string $status): void
    {
        $phone = $this->farmerPhone($request);
        if (! $phone) {
            return;
        }
        $text = match ($status) {
            'travelling' => 'is on the way to your farm',
            'arrived' => 'has arrived at your farm',
            'in_progress' => 'has started treatment',
            'completed' => 'has completed the service. Please rate the visit in the app',
            default => null,
        };
        if ($text === null) {
            return;
        }
        $name = $request->vet?->fullName ?? 'The veterinarian';
        SendVetServiceSms::dispatchAfterResponse([$phone], "Tag & Seal: Dr. {$name} {$text}.");
    }

    public function requestCancelledByFarmer(VetServiceRequest $request): void
    {
        $phone = $request->vet?->phoneNumber;
        if (! $phone) {
            return;
        }
        SendVetServiceSms::dispatchAfterResponse([$phone], 'Tag & Seal: The farmer cancelled the vet request you accepted.');
    }

    public function noVetFound(VetServiceRequest $request): void
    {
        $phone = $this->farmerPhone($request);
        if (! $phone) {
            return;
        }
        SendVetServiceSms::dispatchAfterResponse(
            [$phone],
            'Tag & Seal: No veterinarian is available nearby right now. We will keep searching; you can widen the search in the app.'
        );
    }

    private function farmerPhone(VetServiceRequest $request): ?string
    {
        if ($request->contactPhone) {
            return $request->contactPhone;
        }
        $request->loadMissing('farmer');

        return $request->farmer?->farmerCard()['phone'] ?? null;
    }

    private function placeLabel(VetServiceRequest $request): string
    {
        return $request->locationAddress ?: sprintf('%.4f, %.4f', $request->latitude, $request->longitude);
    }
}
