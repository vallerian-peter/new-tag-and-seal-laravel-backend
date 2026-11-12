<?php

namespace App\Services\Hiring;

use Illuminate\Validation\ValidationException;

class WorkerPermissions
{
    public const MODULES = ['milking', 'feeding', 'weight-change', 'deworming', 'medication', 'vaccination', 'disposal', 'birth-event', 'aborted-pregnancy', 'dryoff', 'insemination', 'pregnancy', 'transfer', 'teeth-clipping', 'tail-docking', 'iron-injection', 'livestock-marking', 'stage-change', 'prepuce-condition'];

    public static function catalog(): array
    {
        return ['farm.view', 'livestock.manage', 'finance.manage', 'events.void', ...array_merge(...array_map(fn ($module) => ["work.$module.view", "work.$module.create"], self::MODULES))];
    }

    public static function validate(array $permissions): array
    {
        if (array_diff($permissions, self::catalog())) {
            throw ValidationException::withMessages(['permissions' => 'INVALID_WORKER_PERMISSIONS']);
        }
        if ($permissions && ! in_array('farm.view', $permissions, true)) {
            throw ValidationException::withMessages(['permissions' => 'FARM_VIEW_REQUIRED']);
        }
        foreach (self::MODULES as $module) {
            if (in_array("work.$module.create", $permissions, true) && ! in_array("work.$module.view", $permissions, true)) {
                throw ValidationException::withMessages(['permissions' => 'MODULE_VIEW_REQUIRED']);
            }
        }

        return array_values(array_unique($permissions));
    }
}
