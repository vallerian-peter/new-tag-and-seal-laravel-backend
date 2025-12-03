<?php

namespace App\Models;

use App\Models\Breed;
use App\Models\Farm;
use App\Models\LivestockObtainedMethod;
use App\Models\LivestockType;
use App\Models\Specie;
use Illuminate\Database\Eloquent\Model;

class Livestock extends Model
{
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
        'gender',
        'breedId',
        'speciesId',
        'status',
        'livestockObtainedMethodId',
        'dateFirstEnteredToFarm',
        'weightAsOnRegistration',
        'created_at',  // Allow manual timestamp management
        'updated_at',  // Allow manual timestamp management
    ];

    protected $casts = [
        'dateOfBirth' => 'date',
        'dateFirstEnteredToFarm' => 'date',
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
