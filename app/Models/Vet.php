<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vet extends Model
{
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
        'status',
    ];
}
