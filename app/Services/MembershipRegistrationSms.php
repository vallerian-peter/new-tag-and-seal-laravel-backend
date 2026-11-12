<?php

namespace App\Services;

use App\Services\Hiring\HiringNotifications;

/**
 * Kiswahili SMS telling a user they were registered to a membership.
 * Messages go through the durable notification outbox; the event key makes
 * each registration send once even when the request is retried or synced again.
 */
class MembershipRegistrationSms
{
    private const FEATURES = [
        'farmer' => 'Mkulima',
        'milking' => 'Maziwa',
        'jobSeeker' => 'Mtafuta Kazi',
        'jobOwner' => 'Mwajiri',
        'shop' => 'Duka',
        'marketplaceBuyer' => 'Mnunuzi wa Soko',
        'marketplaceSeller' => 'Muuzaji wa Soko',
    ];

    public function __construct(private HiringNotifications $notifications) {}

    public function featureRegistered(int $userId, ?string $phone, string $feature, string $name, ?string $farmerNo = null): void
    {
        $label = self::FEATURES[$feature] ?? $feature;
        $message = "Tag & Seal: Hongera {$name}! Umesajiliwa kikamilifu kwenye uanachama wa {$label}."
            .($farmerNo ? " Namba yako ya mkulima ni {$farmerNo}." : '')
            .' Sasa unaweza kutumia huduma hii kwenye programu ya Tag & Seal.';

        $this->send("membership_registered:{$userId}:{$feature}", $userId, $phone, 'membership_registered', $message, [
            'feature' => $feature,
        ]);
    }

    public function amcosFarmerRegistered(int $userId, ?string $phone, string $name, string $amcosName, string $memberNumber, ?string $farmerNo, string $membershipUuid): void
    {
        $message = "Tag & Seal: Hongera {$name}! Umesajiliwa kama mkulima wa AMCOS {$amcosName}."
            ." Namba yako ya uanachama ni {$memberNumber}"
            .($farmerNo ? " na namba yako ya mkulima ni {$farmerNo}." : '.')
            .' Sasa unaweza kupeleka maziwa kwenye vituo vya ukusanyaji vya AMCOS hii.';

        $this->send("amcos_farmer_registered:{$membershipUuid}", $userId, $phone, 'amcos_farmer_registered', $message, [
            'membership_uuid' => $membershipUuid,
        ]);
    }

    private function send(string $event, int $userId, ?string $phone, string $kind, string $message, array $payload): void
    {
        // The same sentence fills both columns so the outbox sends Kiswahili only.
        $this->notifications->send(mb_substr($event, 0, 160), $userId, $phone, $kind, $message, $message, $payload);
    }
}
