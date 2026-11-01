<?php

namespace App\Services\Dynamic;

use App\Models\AnimalBlueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Validated compatibility values, kept outside administrator-defined payloads. */
class RegistrationContext
{
    public function validate(?array $context, AnimalBlueprint $blueprint): ?array
    {
        if ($context === null) {
            return null;
        }
        $validated = Validator::make($context, [
            'livestockTypeId' => ['required', 'integer', 'exists:livestock_types,id'],
            'speciesId' => ['required', 'integer', 'exists:species,id'],
            'breedId' => ['nullable', 'integer', 'exists:breeds,id'],
            'identificationNumber' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'in:male,female,intersex,unknown,not_applicable'],
            'dateOfBirth' => ['nullable', 'date'],
            'dateFirstEnteredToFarm' => ['nullable', 'date'],
            'weightAsOnRegistration' => ['nullable', 'numeric', 'min:0'],
            'livestockObtainedMethodId' => ['nullable', 'integer'],
            'motherUuid' => ['nullable', 'string', 'max:100'], 'fatherUuid' => ['nullable', 'string', 'max:100'],
            'birthEventUuid' => ['nullable', 'string', 'max:100'], 'stageId' => ['nullable', 'integer'],
            'primaryColor' => ['nullable', 'string', 'max:100'], 'secondaryColor' => ['nullable', 'string', 'max:100'],
            'isIdentified' => ['nullable', 'boolean'],
        ])->validate();
        $binding = $blueprint->settings_json['catalogBinding'] ?? null;
        if (is_array($binding) && isset($binding['speciesId'], $binding['livestockTypeId']) && ((int) $binding['speciesId'] !== (int) $validated['speciesId']
            || (int) $binding['livestockTypeId'] !== (int) $validated['livestockTypeId'])) {
            throw ValidationException::withMessages(['registrationContext' => 'CATALOG_BINDING_MISMATCH']);
        }
        $speciesType = DB::table('species')->where('id', $validated['speciesId'])->value('livestockTypeId');
        if ($speciesType !== null && (int) $speciesType !== (int) $validated['livestockTypeId']) {
            throw ValidationException::withMessages(['registrationContext.speciesId' => 'SPECIES_TYPE_MISMATCH']);
        }
        if (! empty($validated['breedId']) && (int) DB::table('breeds')->where('id', $validated['breedId'])->value('livestockTypeId') !== (int) $validated['livestockTypeId']) {
            throw ValidationException::withMessages(['registrationContext.breedId' => 'BREED_TYPE_MISMATCH']);
        }

        return $validated;
    }
}
