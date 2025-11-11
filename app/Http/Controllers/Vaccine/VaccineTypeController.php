<?php

namespace App\Http\Controllers\Vaccine;

use App\Http\Controllers\Controller;
use App\Models\VaccineType;
use Illuminate\Support\Collection;

class VaccineTypeController extends Controller
{
    /**
     * Fetch all vaccine types ordered by name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(): array
    {
        /** @var Collection<int, VaccineType> $types */
        $types = VaccineType::orderBy('name')->get();

        return $types->map(static function (VaccineType $type): array {
            return [
                'id' => $type->id,
                'name' => $type->name,
            ];
        })->toArray();
    }
}

