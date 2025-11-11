<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Medication extends Model
{
    // $table->string('uuid')->unique();

    // $table->string('farmUuid');
    // $table->index('farmUuid');

    // $table->string('livestockUuid');
    // $table->index('livestockUuid');

    // $table->foreignId('diseaseId')->constrained('diseases')->cascadeOnDelete();
    // $table->foreignId('medicineId')->constrained('medicines')->cascadeOnDelete();

    // $table->string('quantity')->comment('Quantity of the medicine with unit')->nullable();
    // $table->string('withdrawalPeriod')->comment('Number of Days for a meat or milk with unit')->nullable();
    // $table->string('medicationDate')->comment('Date a Livestock medicated')->nullable();
    // $table->string('remarks')->nullable();

    protected $fillable = [
        'uuid',
        'farmUuid',
        'livestockUuid',
        'diseaseId',
        'medicineId',
        'quantity',
        'withdrawalPeriod',
        'medicationDate',
        'remarks',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class, 'farmUuid', 'uuid');
    }
    public function livestock()
    {
        return $this->belongsTo(Livestock::class, 'livestockUuid', 'uuid');
    }
    public function disease()
    {
        return $this->belongsTo(Disease::class, 'diseaseId', 'id');
    }
    public function medicine()
    {
        return $this->belongsTo(Medicine::class, 'medicineId', 'id');
    }
}
