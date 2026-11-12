<?php

namespace App\Services\Dairy;

use App\Models\TransporterCompany;
use App\Services\Hiring\HiringNotifications;

/**
 * SMS to the company manager when the portal decides a transporter
 * registration. Each decision is sent once; the event key keeps it unique.
 */
class TransporterNotifications
{
    public function __construct(private readonly HiringNotifications $notifications) {}

    public function decided(TransporterCompany $company, string $decision): void
    {
        $at = optional($decision === 'approved' ? $company->approved_at : $company->rejected_at)->timestamp ?? time();
        $text = $decision === 'approved'
            ? "Tag & Seal: Kampuni yako {$company->name} imeidhinishwa kama msafirishaji. Fungua programu kuanza kusajili magari na madereva."
            : "Tag & Seal: Usajili wa kampuni {$company->name} umekataliwa. Sababu: {$company->approval_remarks}. Rekebisha na utume tena kwenye programu.";

        $this->notifications->send(
            "transporter_{$decision}:{$company->uuid}:{$at}",
            $company->manager_user_id,
            $company->phone,
            "transporter_{$decision}",
            $text,
            $text,
            ['company_uuid' => $company->uuid],
        );
    }
}
