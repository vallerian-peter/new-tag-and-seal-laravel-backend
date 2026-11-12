<?php

namespace App\Services\Hiring;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class FarmOwnership
{
    public function farmerIds(User $user): array
    {
        if (! $user->isActive()) {
            return [];
        }
        $memberships = DB::table('feature_memberships')
            ->where('user_id', $user->id)
            ->whereIn('feature', ['farmer', 'marketplaceSeller'])
            ->get(['farmer_id', 'details']);

        $ids = [];
        $selectedFarmIds = [];
        foreach ($memberships as $m) {
            if ($m->farmer_id !== null) {
                $ids[] = (int) $m->farmer_id;
            }
            if (! empty($m->details)) {
                $details = json_decode((string) $m->details, true);
                if (is_array($details) && ! empty($details['farmId'])) {
                    $selectedFarmIds[] = (int) $details['farmId'];
                }
            }
        }

        if (! empty($selectedFarmIds)) {
            $farmFarmerIds = DB::table('farms')->whereIn('id', array_unique($selectedFarmIds))->pluck('farmerId')->all();
            $ids = array_merge($ids, $farmFarmerIds);
        }

        if ($user->role === 'farmer' && $user->roleId) {
            $ids[] = (int) $user->roleId;
        }

        $createdIds = DB::table('farmers')->where('createdBy', $user->id)->pluck('id')->all();
        $ids = array_merge($ids, $createdIds);

        if (empty($ids) && ! empty($user->phone)) {
            $cleanPhone = preg_replace('/[^0-9]/', '', (string) $user->phone);
            $suffix = substr($cleanPhone, -9);
            if ($suffix !== '') {
                $phoneIds = DB::table('farmers')
                    ->where('phone1', 'like', "%{$suffix}%")
                    ->orWhere('phone2', 'like', "%{$suffix}%")
                    ->pluck('id')
                    ->all();
                $ids = array_merge($ids, $phoneIds);
            }
        }

        if (empty($ids)) {
            return [];
        }

        return DB::table('farmers')->whereIn('id', array_unique($ids))->where('status', 'active')->pluck('id')->all();
    }

    public function owns(User $user, string $farm): bool
    {
        return DB::table('farms')->where('uuid', $farm)->whereIn('farmerId', $this->farmerIds($user))->exists();
    }

    public function farms(User $user): array
    {
        return DB::table('farms')->whereIn('farmerId', $this->farmerIds($user))->get(['uuid', 'name'])->map(fn ($farm) => (array) $farm)->all();
    }
}
