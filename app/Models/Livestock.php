<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Livestock extends Model
{
    protected $table = 'livestocks';

    protected $fillable = [
        'farmUuid',  // Farm UUID reference (not auto-increment ID)
        'uuid',
        'identificationNumber',
        'dummyTagId',
        'barcodeTagId',
        'rfidTagId',
        'livestockTypeId',
        'name',
        'dateOfBirth',
        'motherUuid',  // Mother livestock UUID reference
        'fatherUuid',  // Father livestock UUID reference
        'birthEventUuid',  // Litter / birth event grouping (analytics)
        'stageId',  // Production stage (species-specific, FK to stages)
        'isIdentified',  // false until tagged / official ID assigned
        'gender',
        'breedId',
        'speciesId',
        'status',
        'livestockObtainedMethodId',
        'dateFirstEnteredToFarm',
        'weightAsOnRegistration',
        'primaryColor',
        'secondaryColor',
        'created_at',  // Allow manual timestamp management
        'updated_at',  // Allow manual timestamp management
    ];

    protected $casts = [
        'dateOfBirth' => 'date',
        'dateFirstEnteredToFarm' => 'date',
        'isIdentified' => 'boolean',
    ];

    /**
     * Get the farm that owns the livestock
     *
     * Note: farmUuid stores farm.uuid (not farm.id)
     * So we need to specify 'uuid' as the ownerKey
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class, 'farmUuid', 'uuid');
    }

    public function livestockType()
    {
        return $this->belongsTo(LivestockType::class, 'livestockTypeId');
    }

    /**
     * Get the mother livestock
     *
     * Note: motherUuid stores livestock.uuid (not livestock.id)
     */
    public function mother()
    {
        return $this->belongsTo(Livestock::class, 'motherUuid', 'uuid');
    }

    /**
     * Get the father livestock
     *
     * Note: fatherUuid stores livestock.uuid (not livestock.id)
     */
    public function father()
    {
        return $this->belongsTo(Livestock::class, 'fatherUuid', 'uuid');
    }

    public function breed()
    {
        return $this->belongsTo(Breed::class, 'breedId');
    }

    public function species()
    {
        return $this->belongsTo(Specie::class, 'speciesId');
    }

    public function livestockObtainedMethod()
    {
        return $this->belongsTo(LivestockObtainedMethod::class, 'livestockObtainedMethodId');
    }

    public function birthEventAsOffspring()
    {
        return $this->belongsTo(BirthEvent::class, 'birthEventUuid', 'uuid');
    }

    public function stage()
    {
        return $this->belongsTo(Stage::class, 'stageId');
    }

    public function birthEvents()
    {
        return $this->hasMany(BirthEvent::class, 'livestockUuid', 'uuid');
    }

    /**
     * Get the date of last birth from birth events
     */
    public function getDateOfLastBirthAttribute()
    {
        $lastBirth = $this->birthEvents()
            ->orderBy('startDate', 'desc')
            ->first();

        return $lastBirth ? $lastBirth->startDate : null;
    }
}
