<?php

namespace App\Services\Dynamic;

use App\Models\AnimalSubject;
use App\Models\IdentifierType;
use App\Models\SubjectIdentifier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IdentifierAssignmentService
{
    public function assign(AnimalSubject $subject, string $typeCode, string $value, ?User $actor = null): SubjectIdentifier
    {
        $type = IdentifierType::query()->where('code', $typeCode)->where('status', 'active')->firstOrFail();
        if (! in_array($subject->unit_type->value, $type->supported_subject_modes, true)) {
            throw ValidationException::withMessages(['identifierType' => 'Identifier type does not support this subject mode.']);
        }
        $normalized = $this->normalize($value, $type->normalization_rule);
        if ($normalized === '') {
            throw ValidationException::withMessages(['identifierValue' => 'Identifier value is required.']);
        }

        return DB::transaction(function () use ($subject, $type, $value, $normalized, $actor): SubjectIdentifier {
            if ($type->uniqueness_scope !== 'none' && SubjectIdentifier::query()->where('identifier_type_uuid', $type->uuid)->where('normalized_value', $normalized)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['identifierValue' => 'Identifier is already assigned.']);
            }

            $identifier = SubjectIdentifier::create([
                'subject_uuid' => $subject->uuid,
                'identifier_type_uuid' => $type->uuid,
                'normalized_value' => $normalized,
                'display_value' => trim($value),
                'status' => 'active',
                'assigned_at' => now(),
                'revision' => 1,
            ]);
            DB::table('subject_identifier_events')->insert([
                'uuid' => (string) Str::uuid(),
                'subject_uuid' => $subject->uuid,
                'identifier_uuid' => $identifier->uuid,
                'action' => 'assigned',
                'payload_json' => json_encode(['typeCode' => $type->code, 'value' => $normalized], JSON_THROW_ON_ERROR),
                'actor_uuid' => $actor?->uuid,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $identifier;
        });
    }

    public function assignPairedBarcodeRfid(AnimalSubject $subject, ?string $barcode, ?string $rfid, ?User $actor = null): ?SubjectIdentifier
    {
        if ($barcode === null && $rfid === null) {
            return null;
        }
        $barcode = trim((string) $barcode);
        $rfid = trim((string) $rfid);
        if ($barcode === '' || $rfid === '' || strcasecmp($barcode, $rfid) !== 0) {
            throw ValidationException::withMessages([
                'pairedTag' => 'Barcode and RFID must contain the same paired tag value.',
            ]);
        }

        return $this->assign($subject, 'paired_rfid_barcode', $barcode, $actor);
    }

    public function replacePaired(AnimalSubject $subject, ?string $value, ?User $actor = null): void
    {
        $type = IdentifierType::where('code', 'paired_rfid_barcode')->where('status', 'active')->first();
        if (! $type) {
            if (blank($value)) {
                return;
            }
            throw ValidationException::withMessages(['pairedTag' => 'IDENTIFIER_TYPE_UNAVAILABLE']);
        }
        $normalized = $this->normalize((string) $value, $type->normalization_rule);
        $current = SubjectIdentifier::where('subject_uuid', $subject->uuid)->where('identifier_type_uuid', $type->uuid)->where('status', 'active')->first();
        if ($current && $current->normalized_value === $normalized) {
            return;
        }
        if ($current) {
            $this->retire($subject, $current->uuid, $actor);
        }
        if ($normalized !== '') {
            $this->assignPairedBarcodeRfid($subject, $value, $value, $actor);
        }
    }

    public function retire(AnimalSubject $subject, string $identifierUuid, ?User $actor = null): SubjectIdentifier
    {
        $identifier = SubjectIdentifier::query()
            ->where('uuid', $identifierUuid)
            ->where('subject_uuid', $subject->uuid)
            ->where('status', 'active')
            ->firstOrFail();

        $identifier->forceFill([
            'status' => 'retired',
            'retired_at' => now(),
            'revision' => ((int) $identifier->revision) + 1,
        ])->save();
        DB::table('subject_identifier_events')->insert([
            'uuid' => (string) Str::uuid(),
            'subject_uuid' => $subject->uuid,
            'identifier_uuid' => $identifier->uuid,
            'action' => 'retired',
            'payload_json' => json_encode(['value' => $identifier->normalized_value], JSON_THROW_ON_ERROR),
            'actor_uuid' => $actor?->uuid,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $identifier;
    }

    private function normalize(string $value, string $rule): string
    {
        $value = trim($value);

        return match ($rule) {
            'trim_lowercase' => mb_strtolower($value),
            'digits_only' => preg_replace('/\D+/', '', $value) ?? '',
            'trim' => $value,
            default => mb_strtoupper($value),
        };
    }
}
