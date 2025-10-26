<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Farmer extends Model
{
    protected $fillable = [
        'farmerNo',
        'firstName',
        'middleName',
        'surname',
        'phone1',
        'phone2',
        'email',
        'physicalAddress',
        'farmerOrganizationMembership',
        'dateOfBirth',
        'gender',
        'identityCardTypeId',
        'identityNumber',
        'streetId',
        'schoolLevelId',
        'villageId',
        'wardId',
        'districtId',
        'regionId',
        'countryId',
        'farmerType',
        'createdBy',
        'status',
    ];

    public function identityCardType()
    {
        return $this->belongsTo(IdentityCardType::class, 'identityCardTypeId');
    }

    public function street()
    {
        return $this->belongsTo(Street::class, 'streetId');
    }

    public function schoolLevel()
    {
        return $this->belongsTo(SchoolLevel::class, 'schoolLevelId');
    }

    public function village()
    {
        return $this->belongsTo(Village::class, 'villageId');
    }

    public function ward()
    {
        return $this->belongsTo(Ward::class, 'wardId');
    }

    public function district()
    {
        return $this->belongsTo(District::class, 'districtId');
    }

    public function region()
    {
        return $this->belongsTo(Region::class, 'regionId');
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'countryId');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }
}
