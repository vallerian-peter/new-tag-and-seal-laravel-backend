<?php

namespace App\Enums;

class VetSpecialization
{
    public static function all(): array
    {
        return [
            'cattle', 'goats_sheep', 'poultry', 'pigs', 'dairy', 'reproduction',
            'surgery', 'vaccination', 'nutrition', 'laboratory', 'general',
        ];
    }
}
