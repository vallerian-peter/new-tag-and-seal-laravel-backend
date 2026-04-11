<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrepuceCondition extends Model
{
    protected $table = 'prepuce_conditions';

    protected $fillable = [
        'uuid',
        'eventDate',
        'farmUuid',
        'livestockUuid',
        'conditionTypeId',
        'severityId',
        'clinicalSignIds',
        'causeRiskId',
        'treatmentGivenIds',
        'medicineId',
        'administrationRouteId',
        'vetId',
        'extensionOfficerId',
        'quantity',
        'dose',
        'breedingStatusId',
        'healingStatusId',
        'followUpDate',
        'notes',
    ];

    protected $casts = [
        'clinicalSignIds' => 'array',
        'treatmentGivenIds' => 'array',
        'medicineId' => 'integer',
        'administrationRouteId' => 'integer',
        'conditionTypeId' => 'integer',
        'severityId' => 'integer',
        'causeRiskId' => 'integer',
        'breedingStatusId' => 'integer',
        'healingStatusId' => 'integer',
    ];

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicines::class, 'medicineId', 'id');
    }

    public function administrationRoute(): BelongsTo
    {
        return $this->belongsTo(AdministrationRoute::class, 'administrationRouteId', 'id');
    }

    public function vet(): BelongsTo
    {
        return $this->belongsTo(Vet::class, 'vetId', 'medicalLicenseNo');
    }

    public function extensionOfficer(): BelongsTo
    {
        return $this->belongsTo(ExtensionOfficer::class, 'extensionOfficerId', 'medicalLicenseNo');
    }

    public function conditionType(): BelongsTo
    {
        return $this->belongsTo(PrepuceConditionType::class, 'conditionTypeId', 'id');
    }

    public function severity(): BelongsTo
    {
        return $this->belongsTo(PrepuceSeverity::class, 'severityId', 'id');
    }

    public function causeRisk(): BelongsTo
    {
        return $this->belongsTo(PrepuceCauseRisk::class, 'causeRiskId', 'id');
    }

    public function breedingStatus(): BelongsTo
    {
        return $this->belongsTo(PrepuceBreedingStatus::class, 'breedingStatusId', 'id');
    }

    public function healingStatus(): BelongsTo
    {
        return $this->belongsTo(PrepuceHealingStatus::class, 'healingStatusId', 'id');
    }
}
