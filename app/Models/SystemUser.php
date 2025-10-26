<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemUser extends Model
{
    protected $fillable = [
        'firstName',
        'middleName',
        'lastName',
        'phone',
        'address',
        'createdBy',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }
}
