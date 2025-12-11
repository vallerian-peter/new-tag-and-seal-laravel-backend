<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarmUser extends Model
{
    protected $table = "farm_users";

    protected $fillable = [
        'uuid',
        'farmUuid',
        'firstName',
        'middleName',
        'lastName',
        'phone',
        'email',
        'roleTitle',
        'gender',
        'status',
        'updated_at',
    ];

    // Note: farmUuid column stores JSON array string for multiple farms
    // We handle encoding/decoding manually in getter/setter methods

    /**
     * Get single farm (legacy support - returns first farm if multiple)
     */
    public function farm()
    {
        $farmUuids = $this->getFarmUuidsArray();
        if (empty($farmUuids)) {
            return null;
        }
        return $this->belongsTo(Farm::class, 'uuid', 'uuid')
            ->where('uuid', $farmUuids[0]);
    }

    /**
     * Get multiple farms relationship
     */
    public function farms()
    {
        $farmUuids = $this->getFarmUuidsArray();
        if (empty($farmUuids)) {
            return Farm::whereRaw('1 = 0'); // Return empty query
        }
        return Farm::whereIn('uuid', $farmUuids);
    }

    /**
     * Get farm UUIDs as array
     * Supports both single UUID (string) and multiple UUIDs (JSON array)
     */
    public function getFarmUuidsArray(): array
    {
        $farmUuid = $this->attributes['farmUuid'] ?? null;
        
        if (empty($farmUuid)) {
            return [];
        }

        // If JSON string, decode it
        if (is_string($farmUuid)) {
            // Check if it's a JSON array
            $decoded = json_decode($farmUuid, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return array_filter($decoded); // Remove empty values
            }
            // If not JSON array, treat as single UUID string
            return [$farmUuid];
        }

        // If already an array (from accessor), return it
        if (is_array($farmUuid)) {
            return array_filter($farmUuid);
        }

        return [];
    }

    /**
     * Set farm UUIDs (accepts string or array)
     */
    public function setFarmUuids($farmUuids): void
    {
        if (empty($farmUuids)) {
            $this->attributes['farmUuid'] = null;
            return;
        }

        if (is_array($farmUuids)) {
            // Remove duplicates and empty values
            $farmUuids = array_filter(array_unique($farmUuids));
            if (count($farmUuids) === 1) {
                // Store as single string if only one UUID
                $this->attributes['farmUuid'] = reset($farmUuids);
            } else {
                // Store as JSON array string if multiple
                $this->attributes['farmUuid'] = json_encode(array_values($farmUuids));
            }
        } elseif (is_string($farmUuids)) {
            $this->attributes['farmUuid'] = $farmUuids;
        }
    }

    /**
     * Add a farm UUID to the list
     */
    public function addFarmUuid(string $farmUuid): void
    {
        $farmUuids = $this->getFarmUuidsArray();
        if (!in_array($farmUuid, $farmUuids)) {
            $farmUuids[] = $farmUuid;
            $this->setFarmUuids($farmUuids);
        }
    }

    /**
     * Remove a farm UUID from the list
     */
    public function removeFarmUuid(string $farmUuid): void
    {
        $farmUuids = $this->getFarmUuidsArray();
        $farmUuids = array_filter($farmUuids, fn($uuid) => $uuid !== $farmUuid);
        $this->setFarmUuids(array_values($farmUuids));
    }
}
