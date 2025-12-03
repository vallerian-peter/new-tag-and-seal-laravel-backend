# Backend Multi-Livestock Implementation - Complete Summary

## ✅ Completed Changes

### 1. Database Migrations Created

1. **`2025_11_30_173325_create_birth_events_table.php`**
   - Creates `birth_events` table with `eventType` enum ('calving', 'farrowing')
   - Migrates existing data from `calvings` table
   - Drops old `calvings` table
   - All fields use camelCase convention
   - Foreign keys reference `birth_types` and `birth_problems` (after table rename)

2. **`2025_11_30_173326_add_livestock_type_to_calving_types_table.php`**
   - Adds `livestockTypeId` (nullable) to `calving_types` table
   - Later renamed to `birth_types` table

3. **`2025_11_30_173327_add_livestock_type_to_calving_problems_table.php`**
   - Adds `livestockTypeId` (nullable) to `calving_problems` table
   - Later renamed to `birth_problems` table

4. **`2025_11_30_173328_create_aborted_pregnancies_table.php`**
   - Creates new table for pig-specific aborted pregnancy events
   - All fields use camelCase convention

5. **`2025_11_30_173329_create_stages_table.php`**
   - Creates `stages` table with `livestockTypeId` foreign key (required, not nullable)
   - No color field (matching existing reference table pattern)

6. **`2025_11_30_173804_rename_calving_columns_to_birth_in_birth_events_table.php`**
   - Renames `calvingTypeId` → `birthTypeId`
   - Renames `calvingProblemsId` → `birthProblemsId`
   - Updates foreign key constraints to reference renamed tables

7. **`2025_11_30_173805_rename_calving_tables_to_birth_tables.php`**
   - Renames `calving_types` → `birth_types`
   - Renames `calving_problems` → `birth_problems`
   - Updates foreign key constraints in `birth_events` table

### 2. Models Created/Updated

1. **`app/Models/BirthEvent.php`** (NEW - replaces Calving)
   - Table: `birth_events`
   - Includes `eventType` field
   - Helper methods: `getEventNameAttribute()`, `getOffspringNameAttribute()`
   - Relationships: `birthType()`, `birthProblem()`, `livestock()`, `farm()`, `reproductiveProblem()`

2. **`app/Models/AbortedPregnancy.php`** (NEW)
   - Table: `aborted_pregnancies`
   - Relationships to Farm, Livestock, ReproductiveProblem

3. **`app/Models/Stage.php`** (NEW)
   - Table: `stages`
   - Relationship to LivestockType

4. **`app/Models/BirthType.php`** (NEW)
   - Table: `birth_types` (renamed from `calving_types`)
   - Relationship to LivestockType
   - Supports filtering by `livestockTypeId`

5. **`app/Models/BirthProblem.php`** (NEW)
   - Table: `birth_problems` (renamed from `calving_problems`)
   - Relationship to LivestockType
   - Supports filtering by `livestockTypeId`

6. **`app/Models/CalvingType.php`** (UPDATED - backward compatibility)
   - Table: `birth_types` (points to renamed table)
   - Added `livestockTypeId` to fillable
   - Added relationship to LivestockType
   - **Note:** Kept for backward compatibility, but new code should use `BirthType`

7. **`app/Models/CalvingProblem.php`** (UPDATED - backward compatibility)
   - Table: `birth_problems` (points to renamed table)
   - Added `livestockTypeId` to fillable
   - Added relationship to LivestockType
   - **Note:** Kept for backward compatibility, but new code should use `BirthProblem`

8. **`app/Models/Livestock.php`** (UPDATED)
   - Added `birthEvents()` relationship
   - Added `getDateOfLastBirthAttribute()` accessor (queries from birth_events)
   - Removed `dateOfLastBirth` field (now accessed via accessor)

### 3. Controllers Created/Updated

1. **`app/Http/Controllers/Logs/Birth/BirthEventController.php`** (NEW - replaces CalvingController)
   - `index()` - List all birth events
   - `store()` - Create new birth event (auto-determines eventType based on livestock species)
   - `fetchBirthEventsWithUuid()` - For sync
   - `processBirthEvents()` - Process sync from mobile
   - Uses `birthTypeId` and `birthProblemsId` (not calvingTypeId/calvingProblemsId)

2. **`app/Http/Controllers/Logs/AbortedPregnancy/AbortedPregnancyController.php`** (NEW)
   - `index()` - List all aborted pregnancies
   - `store()` - Create new aborted pregnancy
   - `fetchAbortedPregnanciesWithUuid()` - For sync
   - `processAbortedPregnancies()` - Process sync from mobile

3. **`app/Http/Controllers/BirthType/BirthTypeController.php`** (NEW)
   - `fetchAll()` - Fetch all birth types (with optional `livestockTypeId` filter)
   - `getByLivestockType()` - Filter by livestock type (includes generic types where `livestockTypeId` is null)

4. **`app/Http/Controllers/BirthProblem/BirthProblemController.php`** (NEW)
   - `fetchAll()` - Fetch all birth problems (with optional `livestockTypeId` filter)
   - `getByLivestockType()` - Filter by livestock type (includes generic problems where `livestockTypeId` is null)

5. **`app/Http/Controllers/CalvingType/CalvingTypeController.php`** (UPDATED - backward compatibility)
   - `fetchAll()` - Now uses `BirthType` model internally
   - `getByLivestockType()` - Filters by livestock type (includes generic types)
   - **Note:** Kept for backward compatibility, but new code should use `BirthTypeController`

6. **`app/Http/Controllers/CalvingProblem/CalvingProblemController.php`** (UPDATED - backward compatibility)
   - `fetchAll()` - Now uses `BirthProblem` model internally
   - `getByLivestockType()` - Filters by livestock type (includes generic problems)
   - **Note:** Kept for backward compatibility, but new code should use `BirthProblemController`

7. **`app/Http/Controllers/Stage/StageController.php`** (NEW)
   - `index()` - List all stages (with optional livestockTypeId filter, includes generic stages)
   - `getByLivestockType()` - Filter stages by livestock type (includes generic stages where `livestockTypeId` is null)

8. **`app/Http/Controllers/Logs/LogController.php`** (UPDATED)
   - Updated to include `BirthEventController` and `AbortedPregnancyController`
   - `fetchLogsByFarmLivestockUuids()` now includes:
     - `birthEvents` (replaces calvings)
     - `abortedPregnancies` (new)

### 4. Routes Updated

**File:** `routes/api.php`

**New Routes:**
```php
// Birth Events (replaces calvings)
Route::apiResource('birth-events', \App\Http\Controllers\Logs\Birth\BirthEventController::class);

// Aborted Pregnancies (pig-specific)
Route::apiResource('aborted-pregnancies', \App\Http\Controllers\Logs\AbortedPregnancy\AbortedPregnancyController::class);

// Reference Data (new generic endpoints)
Route::get('birth-types', [\App\Http\Controllers\BirthType\BirthTypeController::class, 'fetchAll']);
Route::get('birth-types/by-livestock-type/{livestockTypeId}', [\App\Http\Controllers\BirthType\BirthTypeController::class, 'getByLivestockType']);
Route::get('birth-problems', [\App\Http\Controllers\BirthProblem\BirthProblemController::class, 'fetchAll']);
Route::get('birth-problems/by-livestock-type/{livestockTypeId}', [\App\Http\Controllers\BirthProblem\BirthProblemController::class, 'getByLivestockType']);
Route::get('stages/by-livestock-type/{livestockTypeId}', [\App\Http\Controllers\Stage\StageController::class, 'getByLivestockType']);

// Backward Compatibility Routes (deprecated)
Route::get('calving-types/by-livestock-type/{livestockTypeId}', [\App\Http\Controllers\CalvingType\CalvingTypeController::class, 'getByLivestockType']);
Route::get('calving-problems/by-livestock-type/{livestockTypeId}', [\App\Http\Controllers\CalvingProblem\CalvingProblemController::class, 'getByLivestockType']);
```

### 5. Seeders Created

1. **`database/seeders/CattleStagesSeeder.php`** (NEW)
   - Seeds cattle-specific stages: Calf, Weaner, Heifer, Cow, Steer, Bull

2. **`database/seeders/PigStagesSeeder.php`** (NEW)
   - Seeds pig-specific stages: Piglet, Weaner, Gilt, Sow, Barrow, Stag, Boar

3. **`database/seeders/BirthTypesSeeder.php`** (NEW)
   - Seeds generic birth types (Normal, Abnormal, Assisted)
   - Seeds cattle-specific types (Normal Calving, Abnormal Calving, Assisted Calving, Caesarean Section)
   - Seeds pig-specific types (Normal Farrowing, Abnormal Farrowing, Assisted Farrowing)

4. **`database/seeders/BirthProblemsSeeder.php`** (NEW)
   - Seeds generic birth problems (None, Dystocia, Retained Placenta)
   - Seeds cattle-specific problems (Surgical Procedure, Fetatomy, Calving Related Nerve Paralysis, Milk Fever, Mastitis)
   - Seeds pig-specific problems (Stillborn Piglets, Weak Piglets, Prolonged Farrowing)

5. **`database/seeders/CleanupReferenceDataSeeder.php`** (NEW)
   - Removes duplicate "Fetatomy" from generic birth problems
   - Removes duplicate "None" from generic birth problems
   - Removes redundant "Natural" from generic birth types

6. **`database/seeders/DatabaseSeeder.php`** (UPDATED)
   - Added calls to: `CattleStagesSeeder`, `PigStagesSeeder`, `BirthTypesSeeder`, `BirthProblemsSeeder`

## 📝 Key Features

### Generic Reference Tables
- **`birth_types`** and **`birth_problems`** are now generic tables that support all livestock types
- Filtered by `livestockTypeId`:
  - `livestockTypeId = 1` (Cattle) → shows cattle-specific types
  - `livestockTypeId = 2` (Swine) → shows pig-specific types
  - `livestockTypeId = null` → shows generic types for all livestock

### Event Type Auto-Detection
- When creating a birth event, the system automatically determines `eventType`:
  - If livestock species is "pig" → `eventType = 'farrowing'`
  - Otherwise → `eventType = 'calving'`

### Date of Last Birth
- **NOT stored in livestocks table** (as requested)
- Accessed via `$livestock->dateOfLastBirth` accessor
- Queries the most recent birth event from `birth_events` table

### Reference Data Filtering
- Birth types and problems can be filtered by `livestockTypeId`
- Includes generic types (where `livestockTypeId` is null)
- Stages are filtered by `livestockTypeId` (includes generic stages where null)

## 🔄 Migration Notes

### Migration Order (CRITICAL):
1. `2025_11_30_173325_create_birth_events_table` (creates table with calvingTypeId/calvingProblemsId)
2. `2025_11_30_173326_add_livestock_type_to_calving_types_table` (adds livestockTypeId to calving_types)
3. `2025_11_30_173327_add_livestock_type_to_calving_problems_table` (adds livestockTypeId to calving_problems)
4. `2025_11_30_173328_create_aborted_pregnancies_table`
5. `2025_11_30_173329_create_stages_table`
6. `2025_11_30_173805_rename_calving_tables_to_birth_tables` (renames tables, updates foreign keys)
7. `2025_11_30_173804_rename_calving_columns_to_birth_in_birth_events_table` (renames columns)

### Before Running Migrations:
1. **Backup your database**
2. Ensure all existing `calvings` data has associated livestock with species
3. The migration will:
   - Create `birth_events` table
   - Migrate all `calvings` data with correct `eventType`
   - Rename `calving_types` → `birth_types`
   - Rename `calving_problems` → `birth_problems`
   - Rename columns in `birth_events` to use generic names
   - Drop `calvings` table

## ⚠️ Important Notes

1. **Table Renames:**
   - `calvings` → `birth_events` ✅
   - `calving_types` → `birth_types` ✅
   - `calving_problems` → `birth_problems` ✅

2. **Column Renames in birth_events:**
   - `calvingTypeId` → `birthTypeId` ✅
   - `calvingProblemsId` → `birthProblemsId` ✅

3. **Backward Compatibility:**
   - `CalvingType` and `CalvingProblem` models still exist but point to renamed tables
   - Old controller routes still work but are deprecated
   - New code should use `BirthType`, `BirthProblem`, and new controllers

4. **Old Files (Can be removed after migration):**
   - `app/Models/Calving.php` (if exists)
   - `app/Http/Controllers/Logs/Calving/CalvingController.php` (if exists)

## 📊 Database Schema Changes Summary

| Table | Action | New Fields | Notes |
|-------|--------|------------|-------|
| `calvings` | **DROPPED** | - | Replaced by `birth_events` |
| `birth_events` | **CREATED** | `eventType` (enum), `birthTypeId`, `birthProblemsId` | Generic for all livestock |
| `calving_types` | **RENAMED** → `birth_types` | `livestockTypeId` (nullable) | Now generic table |
| `calving_problems` | **RENAMED** → `birth_problems` | `livestockTypeId` (nullable) | Now generic table |
| `aborted_pregnancies` | **CREATED** | All new | Pig-specific event |
| `stages` | **CREATED** | `livestockTypeId` (required) | Species-specific stages |
| `livestocks` | **NO CHANGE** | - | `dateOfLastBirth` accessed via relationship |

## 📈 Current Data Status

### Stages:
- **Cattle (ID: 1)**: 6 stages (Calf, Weaner, Heifer, Cow, Steer, Bull)
- **Swine (ID: 2)**: 7 stages (Piglet, Weaner, Gilt, Sow, Barrow, Stag, Boar)
- **Other Types**: 0 stages (can be seeded as needed)

### Birth Types:
- **Generic (NULL)**: 3 types (Assisted, Normal, Abnormal)
- **Cattle (ID: 1)**: 4 types (Normal Calving, Abnormal Calving, Assisted Calving, Caesarean Section)
- **Swine (ID: 2)**: 3 types (Normal Farrowing, Abnormal Farrowing, Assisted Farrowing)

### Birth Problems:
- **Generic (NULL)**: 4 problems (No Problem, Respiratory problem, Dystocia, Retained Placenta)
- **Cattle (ID: 1)**: 5 problems (Surgical Procedure, Fetatomy, Calving Related Nerve Paralysis, Milk Fever, Mastitis)
- **Swine (ID: 2)**: 3 problems (Stillborn Piglets, Weak Piglets, Prolonged Farrowing)

### Breeds:
- **Cattle (ID: 1)**: 11 breeds
- **Other Types**: 0 breeds (can be seeded as needed)

## ✅ Migration Status

All migrations have been successfully executed:
- ✅ `2025_11_30_173325_create_birth_events_table` - DONE
- ✅ `2025_11_30_173326_add_livestock_type_to_calving_types_table` - DONE
- ✅ `2025_11_30_173327_add_livestock_type_to_calving_problems_table` - DONE
- ✅ `2025_11_30_173328_create_aborted_pregnancies_table` - DONE
- ✅ `2025_11_30_173329_create_stages_table` - DONE
- ✅ `2025_11_30_173804_rename_calving_columns_to_birth_in_birth_events_table` - DONE
- ✅ `2025_11_30_173805_rename_calving_tables_to_birth_tables` - DONE

## ✅ Completed Tasks

1. ✅ Created `birth_events` table with `eventType` enum
2. ✅ Renamed `calving_types` → `birth_types` (generic table)
3. ✅ Renamed `calving_problems` → `birth_problems` (generic table)
4. ✅ Renamed columns in `birth_events`: `calvingTypeId` → `birthTypeId`, `calvingProblemsId` → `birthProblemsId`
5. ✅ Created `aborted_pregnancies` table for pigs
6. ✅ Created `stages` table with `livestockTypeId`
7. ✅ Created new models: `BirthEvent`, `BirthType`, `BirthProblem`, `AbortedPregnancy`, `Stage`
8. ✅ Updated `CalvingType` and `CalvingProblem` models for backward compatibility
9. ✅ Created new controllers: `BirthEventController`, `BirthTypeController`, `BirthProblemController`, `AbortedPregnancyController`, `StageController`
10. ✅ Updated `CalvingTypeController` and `CalvingProblemController` for backward compatibility
11. ✅ Updated `LogController` to include birth events and aborted pregnancies
12. ✅ Routes updated with new endpoints
13. ✅ Seeders created: `CattleStagesSeeder`, `PigStagesSeeder`, `BirthTypesSeeder`, `BirthProblemsSeeder`, `CleanupReferenceDataSeeder`
14. ✅ All migrations run successfully
15. ✅ Reference data cleaned up (duplicates removed)
16. ✅ No linter errors

## 🚀 API Endpoints Summary

### Birth Events
- `GET /api/logs/birth-events` - List all birth events
- `POST /api/logs/birth-events` - Create birth event
- `GET /api/logs/birth-events/{id}` - Get birth event
- `PUT /api/logs/birth-events/{id}` - Update birth event
- `DELETE /api/logs/birth-events/{id}` - Delete birth event

### Aborted Pregnancies
- `GET /api/logs/aborted-pregnancies` - List all aborted pregnancies
- `POST /api/logs/aborted-pregnancies` - Create aborted pregnancy
- `GET /api/logs/aborted-pregnancies/{id}` - Get aborted pregnancy
- `PUT /api/logs/aborted-pregnancies/{id}` - Update aborted pregnancy
- `DELETE /api/logs/aborted-pregnancies/{id}` - Delete aborted pregnancy

### Reference Data
- `GET /api/reference/birth-types` - Get all birth types (optional `?livestockTypeId=X`)
- `GET /api/reference/birth-types/by-livestock-type/{livestockTypeId}` - Get birth types by livestock type
- `GET /api/reference/birth-problems` - Get all birth problems (optional `?livestockTypeId=X`)
- `GET /api/reference/birth-problems/by-livestock-type/{livestockTypeId}` - Get birth problems by livestock type
- `GET /api/reference/stages/by-livestock-type/{livestockTypeId}` - Get stages by livestock type

### Backward Compatibility (Deprecated)
- `GET /api/reference/calving-types/by-livestock-type/{livestockTypeId}` - Still works, uses `BirthType` internally
- `GET /api/reference/calving-problems/by-livestock-type/{livestockTypeId}` - Still works, uses `BirthProblem` internally

---

**Implementation Date:** 2025-11-30  
**Status:** ✅ **BACKEND 100% COMPLETE** - All migrations run successfully, all data seeded, all controllers working!
