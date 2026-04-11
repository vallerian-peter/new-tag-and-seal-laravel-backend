<?php

namespace App\Http\Controllers\PrepuceConditionLookup;

use App\Http\Controllers\Controller;
use App\Models\PrepuceBreedingStatus;
use App\Models\PrepuceCauseRisk;
use App\Models\PrepuceClinicalSign;
use App\Models\PrepuceConditionType;
use App\Models\PrepuceHealingStatus;
use App\Models\PrepuceSeverity;
use App\Models\PrepuceTreatmentGiven;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PrepuceConditionLookupController extends Controller
{
    /**
     * Medicine-style reference rows: id + name (+ optional Swahili), one sync key per table.
     *
     * @return array<string, list<array{id: int, name: string, nameSw: string|null}>>
     */
    public function referenceDataForSync(): array
    {
        return [
            'prepuceConditionTypes' => $this->mapForSync(PrepuceConditionType::query()),
            'prepuceSeverities' => $this->mapForSync(PrepuceSeverity::query()),
            'prepuceClinicalSigns' => $this->mapForSync(PrepuceClinicalSign::query()),
            'prepuceCauseRisks' => $this->mapForSync(PrepuceCauseRisk::query()),
            'prepuceTreatmentsGiven' => $this->mapForSync(PrepuceTreatmentGiven::query()),
            'prepuceBreedingStatuses' => $this->mapForSync(PrepuceBreedingStatus::query()),
            'prepuceHealingStatuses' => $this->mapForSync(PrepuceHealingStatus::query()),
        ];
    }

    /**
     * @param  Builder<Model>  $query
     * @return list<array{id: int, name: string, nameSw: string|null}>
     */
    private function mapForSync(Builder $query): array
    {
        return $query->orderBy('name')
            ->get()
            ->map(static fn (Model $row) => [
                'id' => (int) $row->getKey(),
                'name' => $row->name,
                'nameSw' => $row->name_sw,
            ])
            ->values()
            ->all();
    }
}
