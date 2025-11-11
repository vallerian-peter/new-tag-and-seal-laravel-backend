<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vaccination extends Model
{
    // $table->string('uuid')->unique();
    // $table->string('vaccinationNo')->unique();

    // $table->string('farmUuid');
    // $table->index('farmUuid');

    // $table->string('livestockUuid');
    // $table->index('livestockUuid');

    // $table->foreignId('vaccineId')->constrained('vaccines')->cascadeOnDelete();
    // $table->foreignId('diseaseId')->constrained('diseases')->cascadeOnDelete();
    // $table->string('vetId')->nullable();
    // $table->string('extensionOfficerId')->nullable();
    // $table->enum('status', ['pending', 'completed', 'failed'])->default('completed');

    protected $fillable = [
        'uuid',
        'vaccinationNo',
        'farmUuid',
        'livestockUuid',
        'vaccineId',
        'diseaseId',
        'vetId',
        'extensionOfficerId',
        'status',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class, 'farmUuid', 'uuid');
    }
    public function livestock()
    {
        return $this->belongsTo(Livestock::class, 'livestockUuid', 'uuid');
    }
    public function vaccine()
    {
        return $this->belongsTo(Vaccine::class, 'vaccineId', 'id');
    }
    public function disease()
    {
        return $this->belongsTo(Disease::class, 'diseaseId', 'id');
    }
    public function vet()
    {
        return $this->belongsTo(Vet::class, 'vetId', 'medicalLicenseNo');
    }
    public function extensionOfficer()
    {
        return $this->belongsTo(ExtensionOfficer::class, 'extensionOfficerId', 'medicalLicenseNo');
    }
}
