<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\MedicineType;

class Medicines extends Model
{
    protected $table = 'medicines';

    protected $fillable = [
        'name',
        'medicineTypeId',
    ];

    public function medicineType()
    {
        return $this->belongsTo(MedicineType::class, 'medicineTypeId', 'id');
    }
}
