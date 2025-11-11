<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Medicines extends Model
{
    protected $table = 'medicines';

    protected $fillable = [
        'name',
        'medicineTypeId',
    ];

    public function medicineType()
    {
        return $this->belongsTo(MedicineTypes::class, 'medicineTypeId', 'id');
    }
}
