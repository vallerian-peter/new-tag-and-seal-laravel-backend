<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Farm;
use App\Models\Livestock;
use App\Models\AdministrationRoute;
use App\Models\Medicines;
use App\Models\Vet;
use App\Models\ExtensionOfficer;

class Deworming extends Model
{
    protected $table = 'dewormings';

    protected $fillable = [
        'uuid',
        'eventDate',
        'farmUuid',
        'livestockUuid',
        'administrationRouteId',
        'medicineId',
        'vetId',
        'extensionOfficerId',
        'quantity',
        'dose',
        'nextAdministrationDate',
    ];

    protected $casts = [
        'nextAdministrationDate' => 'date',
    ];

    public function administrationRoute()
    {
        return $this->belongsTo(AdministrationRoute::class, 'administrationRouteId', 'id');
    }

    public function medicine()
    {
        return $this->belongsTo(Medicine::class, 'medicineId', 'id');
    }

    public function vet()
    {
        return $this->belongsTo(Vet::class, 'vetId', 'medicalLicenseNo');
    }

    public function extensionOfficer()
    {
        return $this->belongsTo(ExtensionOfficer::class, 'extensionOfficerId', 'medicalLicenseNo');
    }

    public function livestock()
    {
        return $this->belongsTo(Livestock::class, 'livestockUuid', 'uuid');
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class, 'farmUuid', 'uuid');
    }
}
