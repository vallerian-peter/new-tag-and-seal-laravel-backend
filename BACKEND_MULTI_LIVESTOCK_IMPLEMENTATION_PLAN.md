# Backend Multi-Livestock Support Implementation Plan

**Version:** 1.0  
**Date:** 2025-01-XX  
**Purpose:** Support multiple livestock types (Cattle, Pigs, Goats, Sheep, etc.) with species-specific terminology and events

---

## 📋 Overview

This plan outlines all backend changes needed to support multiple livestock types, specifically:
- Replace `calvings` table with `birth_events` table (supporting both calving and farrowing)
- Add support for pig-specific events (farrowing, aborted pregnancy)
- Make stages and reference data species-aware
- Add `dateOfLastBirth` field to livestocks table

---

## 🎯 Goals

1. ✅ Replace `calvings` table with generic `birth_events` table
2. ✅ Support both "calving" (cattle) and "farrowing" (pigs) in same table
3. ✅ Add `aborted_pregnancies` table for pigs
4. ✅ Make stages filterable by livestock type
5. ✅ Make reference data (calving types, problems) filterable by livestock type
6. ✅ ~~Add `dateOfLastBirth` to livestocks~~ (REMOVED - will query from birth_events)
7. ✅ **Ensure all field names use camelCase convention** (matching existing structure)

---

## 📦 Phase 1: Database Structure Changes

### 1.1 Create `birth_events` Table Migration
**File:** `database/migrations/2025_XX_XX_XXXXXX_create_birth_events_table.php`

**Changes:**
- Create new `birth_events` table with `eventType` enum field
- Migrate existing data from `calvings` table
- Determine `eventType` based on livestock species
- Drop old `calvings` table

**Fields (all camelCase):**
```php
- id (bigint, primary key)
- uuid (string, unique)
- farmUuid (string, indexed) // camelCase
- livestockUuid (string, indexed) // camelCase
- eventType (enum: 'calving', 'farrowing') // NEW - camelCase
- startDate (string) // camelCase
- endDate (string, nullable) // camelCase
- calvingTypeId (foreign key) // camelCase
- calvingProblemsId (foreign key, nullable) // camelCase
- reproductiveProblemId (foreign key, nullable) // camelCase
- remarks (string, nullable) // camelCase
- status (enum: 'pending', 'not_active', 'active')
- timestamps (created_at, updated_at - Laravel standard)
```

**Migration Strategy:**
- Query existing calvings
- Join with livestocks and species tables
- Set eventType = 'farrowing' if species = 'pig', else 'calving'
- Insert into birth_events
- Drop calvings table

---

### 1.2 ~~Add `dateOfLastBirth` to Livestocks~~ (REMOVED)
**Note:** We will NOT add `dateOfLastBirth` to livestocks table. Instead, we'll query it from `birth_events` table when needed.

---

### 1.3 Add `livestockTypeId` to Reference Tables
**File:** `database/migrations/2025_XX_XX_XXXXXX_add_livestock_type_to_calving_types_table.php`

**Changes:**
- Add `livestockTypeId` (nullable) to `calving_types` table
- Allows different calving types for different livestock types

**File:** `database/migrations/2025_XX_XX_XXXXXX_add_livestock_type_to_calving_problems_table.php`

**Changes:**
- Add `livestockTypeId` (nullable) to `calving_problems` table
- Allows different problems for different livestock types

---

### 1.4 Create `aborted_pregnancies` Table
**File:** `database/migrations/2025_XX_XX_XXXXXX_create_aborted_pregnancies_table.php`

**Changes:**
- Create new table for pig-specific aborted pregnancy events

**Fields (all camelCase):**
```php
- id (bigint, primary key)
- uuid (string, unique)
- farmUuid (string, indexed) // camelCase
- livestockUuid (string, indexed) // camelCase
- abortionDate (date) // camelCase
- reproductiveProblemId (foreign key, nullable) // camelCase
- remarks (text, nullable) // camelCase
- status (enum: 'active', 'inactive')
- timestamps (created_at, updated_at - Laravel standard)
```

---

### 1.5 Create/Update `stages` Table
**File:** `database/migrations/2025_XX_XX_XXXXXX_create_stages_table.php` (if not exists)

**Changes:**
- Ensure `stages` table has `livestockTypeId` foreign key
- This allows different stages for different livestock types

**Fields (all camelCase):**
```php
- id (bigint, primary key)
- name (string)
- livestockTypeId (foreign key) // Must exist - camelCase
- timestamps (created_at, updated_at - Laravel standard)
```

---

## 🔧 Phase 2: Model Updates

### 2.1 Rename/Update Calving Model to BirthEvent
**File:** `app/Models/BirthEvent.php` (rename from `Calving.php`)

**Changes:**
- Rename class from `Calving` to `BirthEvent`
- Update table name to `birth_events`
- Add `eventType` to fillable array
- Add helper methods:
  - `getEventNameAttribute()` - Returns "Calving" or "Farrowing"
  - `getOffspringNameAttribute()` - Returns "Calf" or "Piglet"

**Key Methods:**
```php
public function getEventNameAttribute(): string
{
    return $this->eventType === 'farrowing' ? 'Farrowing' : 'Calving';
}

public function getOffspringNameAttribute(): string
{
    return $this->eventType === 'farrowing' ? 'Piglet' : 'Calf';
}
```

---

### 2.2 Update Livestock Model
**File:** `app/Models/Livestock.php`

**Changes:**
- Add relationship to birth events:
  ```php
  public function birthEvents()
  {
      return $this->hasMany(BirthEvent::class, 'livestockUuid', 'uuid');
  }
  
  // Helper method to get last birth date
  public function getDateOfLastBirthAttribute()
  {
      $lastBirth = $this->birthEvents()
          ->orderBy('startDate', 'desc')
          ->first();
      return $lastBirth ? $lastBirth->startDate : null;
  }
  ```

---

### 2.3 Create AbortedPregnancy Model
**File:** `app/Models/AbortedPregnancy.php` (new file)

**Changes:**
- Create new model for aborted pregnancy events
- Define relationships to Farm, Livestock, ReproductiveProblem

**Relationships:**
```php
- farm() - BelongsTo Farm
- livestock() - BelongsTo Livestock
- reproductiveProblem() - BelongsTo ReproductiveProblem
```

---

### 2.4 Update CalvingType Model
**File:** `app/Models/CalvingType.php`

**Changes:**
- Add `livestockTypeId` to fillable array
- Add relationship to LivestockType:
  ```php
  public function livestockType()
  {
      return $this->belongsTo(LivestockType::class, 'livestockTypeId');
  }
  ```

---

### 2.5 Update CalvingProblem Model
**File:** `app/Models/CalvingProblem.php`

**Changes:**
- Add `livestockTypeId` to fillable array
- Add relationship to LivestockType:
  ```php
  public function livestockType()
  {
      return $this->belongsTo(LivestockType::class, 'livestockTypeId');
  }
  ```

---

## 🎮 Phase 3: Controller Updates

### 3.1 Update CalvingController to BirthEventController
**File:** `app/Http/Controllers/Logs/Birth/BirthEventController.php` (rename from `CalvingController.php`)

**Changes:**
- Rename class from `CalvingController` to `BirthEventController`
- Update all references from `Calving` to `BirthEvent`
- Update `index()` method to return birth events
- Update `store()` method to:
  - Determine `eventType` based on livestock species
  - Auto-set `eventType` = 'farrowing' if species is pig, else 'calving'
- Update `update()` method similarly
- Update `destroy()` method

**Key Logic:**
```php
// In store() method:
$livestock = Livestock::where('uuid', $request->livestockUuid)->first();
$species = Specie::find($livestock->speciesId);
$eventType = strtolower($species->name) === 'pig' ? 'farrowing' : 'calving';

$birthEvent = BirthEvent::create([
    // ... other fields
    'eventType' => $eventType,
]);

// Note: dateOfLastBirth is now accessed via relationship, no need to update
```

---

### 3.2 Create AbortedPregnancyController
**File:** `app/Http/Controllers/Logs/AbortedPregnancy/AbortedPregnancyController.php` (new file)

**Changes:**
- Create new controller for aborted pregnancy events
- Implement CRUD operations:
  - `index()` - List aborted pregnancies
  - `store()` - Create new aborted pregnancy
  - `update()` - Update existing
  - `destroy()` - Delete
- Add sync methods similar to other log controllers

---

### 3.3 Update CalvingTypeController
**File:** `app/Http/Controllers/CalvingType/CalvingTypeController.php`

**Changes:**
- Add method to filter by livestock type:
  ```php
  public function getByLivestockType($livestockTypeId)
  {
      return CalvingType::where('livestockTypeId', $livestockTypeId)
          ->orWhereNull('livestockTypeId') // Include generic types
          ->get();
  }
  ```
- Update `index()` to optionally filter by livestock type

---

### 3.4 Update CalvingProblemController
**File:** `app/Http/Controllers/CalvingProblem/CalvingProblemController.php`

**Changes:**
- Add method to filter by livestock type:
  ```php
  public function getByLivestockType($livestockTypeId)
  {
      return CalvingProblem::where('livestockTypeId', $livestockTypeId)
          ->orWhereNull('livestockTypeId') // Include generic problems
          ->get();
  }
  ```
- Update `index()` to optionally filter by livestock type

---

### 3.5 Create/Update StageController
**File:** `app/Http/Controllers/Stage/StageController.php` (create if not exists)

**Changes:**
- Create controller to manage stages
- Add method to get stages by livestock type:
  ```php
  public function getByLivestockType($livestockTypeId)
  {
      return Stage::where('livestockTypeId', $livestockTypeId)->get();
  }
  ```

---

### 3.6 Update SyncController
**File:** `app/Http/Controllers/Sync/SyncController.php`

**Changes:**
- Update sync methods to handle `birth_events` instead of `calvings`
- Add sync support for `aborted_pregnancies`
- Update references from `Calving` to `BirthEvent`

---

## 📊 Phase 4: Seeders & Data Migration

### 4.1 Create Pig Stages Seeder
**File:** `database/seeders/PigStagesSeeder.php` (new file)

**Changes:**
- Seed pig-specific stages (no color field):
  - Piglet (female & male)
  - Weaner (female & male)
  - Gilt (female)
  - Sow (female)
  - Barrow (male - castrated)
  - Stag (male - castrated)
  - Boar (male - intact)

---

### 4.2 Update CalvingTypes Seeder
**File:** `database/seeders/CalvingTypesSeeder.php` (update if exists)

**Changes:**
- Add `livestockTypeId` to existing calving types
- Optionally create pig-specific types if needed

---

### 4.3 Update CalvingProblems Seeder
**File:** `database/seeders/CalvingProblemsSeeder.php` (update if exists)

**Changes:**
- Add `livestockTypeId` to existing calving problems
- Optionally create pig-specific problems if needed

---

## 🔄 Phase 5: Route Updates

### 5.1 Update Routes
**File:** `routes/api.php` or `routes/web.php`

**Changes:**
- Update routes from `/calvings` to `/birth-events`
- Update controller references from `CalvingController` to `BirthEventController`
- Add routes for aborted pregnancies:
  ```php
  Route::apiResource('aborted-pregnancies', AbortedPregnancyController::class);
  ```
- Add routes for filtered reference data:
  ```php
  Route::get('calving-types/by-livestock-type/{livestockTypeId}', [CalvingTypeController::class, 'getByLivestockType']);
  Route::get('calving-problems/by-livestock-type/{livestockTypeId}', [CalvingProblemController::class, 'getByLivestockType']);
  Route::get('stages/by-livestock-type/{livestockTypeId}', [StageController::class, 'getByLivestockType']);
  ```

---

## 📝 Phase 6: Documentation Updates

### 6.1 Update API Documentation
**File:** `API_DOCUMENTATION.md` (if exists)

**Changes:**
- Update endpoints from `/calvings` to `/birth-events`
- Document `eventType` field
- Document new aborted pregnancy endpoints
- Document filtered reference data endpoints

---

## ✅ Implementation Checklist

### Database Migrations
- [ ] Create `birth_events` table migration
- [ ] Migrate data from `calvings` to `birth_events`
- [ ] Drop `calvings` table
- [ ] ~~Add `dateOfLastBirth` to `livestocks`~~ (REMOVED)
- [ ] Add `livestockTypeId` to `calving_types`
- [ ] Add `livestockTypeId` to `calving_problems`
- [ ] Create `aborted_pregnancies` table
- [ ] Verify `stages` table has `livestockTypeId`

### Models
- [ ] Rename `Calving` model to `BirthEvent`
- [ ] Update `BirthEvent` model with `eventType` and helpers
- [ ] Update `Livestock` model with `dateOfLastBirth`
- [ ] Create `AbortedPregnancy` model
- [ ] Update `CalvingType` model
- [ ] Update `CalvingProblem` model

### Controllers
- [ ] Rename `CalvingController` to `BirthEventController`
- [ ] Update `BirthEventController` with species-aware logic
- [ ] Create `AbortedPregnancyController`
- [ ] Update `CalvingTypeController` with filtering
- [ ] Update `CalvingProblemController` with filtering
- [ ] Create/Update `StageController` with filtering
- [ ] Update `SyncController` for new tables

### Seeders
- [ ] Create `PigStagesSeeder`
- [ ] Update `CalvingTypesSeeder` (if exists)
- [ ] Update `CalvingProblemsSeeder` (if exists)

### Routes
- [ ] Update routes from `/calvings` to `/birth-events`
- [ ] Add aborted pregnancy routes
- [ ] Add filtered reference data routes

### Testing
- [ ] Test birth event creation for cattle (calving)
- [ ] Test birth event creation for pigs (farrowing)
- [ ] Test aborted pregnancy creation
- [ ] Test dateOfLastBirth accessor (via relationship)
- [ ] Test filtered reference data endpoints
- [ ] Test data migration from calvings to birth_events

---

## 🚨 Important Notes

1. **Backward Compatibility:** 
   - The migration will preserve all existing data
   - Old `calvings` data will be migrated with correct `eventType`

2. **Data Integrity:**
   - Ensure species data exists before migration
   - Default `eventType` to 'calving' if species cannot be determined

3. **Performance:**
   - Index `eventType` field for faster queries
   - Consider adding composite index on (livestockUuid, eventType)

4. **API Versioning:**
   - Consider maintaining old `/calvings` endpoint temporarily
   - Or return 301 redirect to `/birth-events`

5. **Testing Strategy:**
   - Test migration on staging first
   - Backup database before running migration
   - Verify data integrity after migration

---

## 📅 Estimated Timeline

- **Phase 1 (Database):** 2-3 hours
- **Phase 2 (Models):** 1-2 hours
- **Phase 3 (Controllers):** 3-4 hours
- **Phase 4 (Seeders):** 1 hour
- **Phase 5 (Routes):** 30 minutes
- **Phase 6 (Testing):** 2-3 hours

**Total Estimated Time:** 9-13 hours

---

## 🔗 Related Files Reference

### Current Files to Modify:
- `app/Models/Calving.php` → Rename to `BirthEvent.php`
- `app/Http/Controllers/Logs/Calving/CalvingController.php` → Rename to `BirthEventController.php`
- `app/Models/Livestock.php` → Add `dateOfLastBirth`
- `app/Models/CalvingType.php` → Add `livestockTypeId`
- `app/Models/CalvingProblem.php` → Add `livestockTypeId`

### New Files to Create:
- `app/Models/AbortedPregnancy.php`
- `app/Http/Controllers/Logs/AbortedPregnancy/AbortedPregnancyController.php`
- `app/Http/Controllers/Stage/StageController.php` (if not exists)
- `database/seeders/PigStagesSeeder.php`

### Migration Files to Create:
- `database/migrations/2025_XX_XX_XXXXXX_create_birth_events_table.php`
- `database/migrations/2025_XX_XX_XXXXXX_add_livestock_type_to_calving_types_table.php`
- `database/migrations/2025_XX_XX_XXXXXX_add_livestock_type_to_calving_problems_table.php`
- `database/migrations/2025_XX_XX_XXXXXX_create_aborted_pregnancies_table.php`

---

**End of Implementation Plan**

