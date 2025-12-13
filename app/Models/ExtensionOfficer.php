<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtensionOfficer extends Model
{
    protected $table = 'extension_officers';

    protected $fillable = [
        'firstName',
        'middleName',
        'lastName',
        'email',
        'phone',
        'password',
        'gender',
        'licenseNumber',
        'address',
        'countryId',
        'regionId',
        'districtId',
        'wardId',
        'organization',
        'isVerified',
        'specialization',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'isVerified' => 'boolean',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'countryId');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'regionId');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'districtId');
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class, 'wardId');
    }

    public function farmInvites(): HasMany
    {
        return $this->hasMany(ExtensionOfficerFarmInvite::class, 'extensionOfficerId');
    }
}
