<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExtensionOfficer extends Model
{
    protected $table = 'extension_officers';

    protected $fillable = [
        'referenceNo',
        'medicalLicenseNo',
        'fullName',
        'phoneNumber',
        'email',
        'address',
        'countryId',
        'regionId',
        'districtId',
        'gender',
        'dateOfBirth',
        'identityCardTypeId',
        'identityNo',
        'schoolLevelId',
        'status',
    ];

    public function country()
    {
        return $this->belongsTo(Country::class, 'countryId', 'id');
    }

    public function region()
    {
        return $this->belongsTo(Region::class, 'regionId', 'id');
    }

    public function district()
    {
        return $this->belongsTo(District::class, 'districtId', 'id');
    }

    public function identityCardType()
    {
        return $this->belongsTo(IdentityCardType::class, 'identityCardTypeId', 'id');
    }

    public function schoolLevel()
    {
        return $this->belongsTo(SchoolLevel::class, 'schoolLevelId', 'id');
    }
}
