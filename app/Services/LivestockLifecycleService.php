<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Livestock;
use App\Models\SyncTombstone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class LivestockLifecycleService
{
    public function archive(
        Livestock $livestock,
        ?User $actor,
        string $reason,
        array $context = [],
    ): Livestock {
        if ($livestock->trashed()) {
            throw new LogicException('The livestock is already archived.');
        }

        return DB::transaction(function () use ($livestock, $actor, $reason, $context): Livestock {
            $before = $livestock->attributesToArray();

            $livestock->forceFill([
                'deleted_by_uuid' => $actor?->uuid,
                'delete_reason' => $reason,
                'restored_at' => null,
                'restored_by_uuid' => null,
                'revision' => ((int) $livestock->revision) + 1,
            ])->save();

            $livestock->delete();

            $archived = Livestock::withTrashed()->findOrFail($livestock->id);
            $this->publishDeletionTombstone($archived);
            $this->writeAuditLog('livestock.deleted', $archived, $actor, $reason, $before, $context);

            return $archived;
        });
    }

    public function restore(
        Livestock $livestock,
        User $actor,
        array $context = [],
    ): Livestock {
        if (! $livestock->trashed()) {
            throw new LogicException('The livestock is not archived.');
        }

        return DB::transaction(function () use ($livestock, $actor, $context): Livestock {
            $before = $livestock->attributesToArray();

            $livestock->forceFill([
                'restored_at' => now(),
                'restored_by_uuid' => $actor->uuid,
                'revision' => ((int) $livestock->revision) + 1,
            ]);
            $livestock->restore();

            $restored = $livestock->fresh();
            SyncTombstone::query()
                ->where('entity_type', 'livestock')
                ->where('entity_uuid', $restored->uuid)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);
            $this->writeAuditLog('livestock.restored', $restored, $actor, null, $before, $context);

            return $restored;
        });
    }

    private function publishDeletionTombstone(Livestock $livestock): void
    {
        $tombstone = SyncTombstone::firstOrNew([
            'entity_type' => 'livestock',
            'entity_uuid' => $livestock->uuid,
        ]);

        if (! $tombstone->uuid) {
            $tombstone->uuid = (string) Str::uuid();
        }

        $tombstone->fill([
            'farm_uuid' => $livestock->farmUuid,
            'entity_revision' => (int) $livestock->revision,
            'operation' => 'deleted',
            'occurred_at' => $livestock->deleted_at ?? now(),
            'superseded_at' => null,
        ])->save();
    }

    private function writeAuditLog(
        string $action,
        Livestock $livestock,
        ?User $actor,
        ?string $reason,
        array $before,
        array $context,
    ): void {
        $requestUuid = $context['request_uuid'] ?? null;
        if (! is_string($requestUuid) || ! Str::isUuid($requestUuid)) {
            $requestUuid = (string) Str::uuid();
        }

        AuditLog::create([
            'uuid' => (string) Str::uuid(),
            'actor_uuid' => $actor?->uuid,
            'action' => $action,
            'entity_type' => 'livestock',
            'entity_uuid' => $livestock->uuid,
            'before_json' => $before,
            'after_json' => $livestock->attributesToArray(),
            'reason' => $reason,
            'request_uuid' => $requestUuid,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'created_at' => now(),
        ]);
    }
}
