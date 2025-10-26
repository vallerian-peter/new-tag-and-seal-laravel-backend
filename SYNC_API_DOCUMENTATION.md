# 🔄 Sync API Documentation

## Overview

The Sync API provides a clean, role-based data synchronization system. All sync endpoints follow a consistent structure and return data based on the authenticated user's role and permissions.

---

## 🎯 Main Endpoints

### 1. **Splash Sync** (Primary Endpoint)
**Use Case:** Called when app starts after user login  
**Endpoint:** `GET /api/v1/sync/splash`  
**Authentication:** Required (Bearer Token)

#### What It Does:
1. ✅ Returns ALL reference data (locations, breeds, species, etc.)
2. ✅ Returns user-specific data based on role
3. ✅ Optimized single-request approach

#### Response Structure:
```json
{
  "status": true,
  "message": "Splash sync completed successfully",
  "data": {
    "locations": {
      "countries": [...],
      "regions": [...],
      "districts": [...],
      "wards": [...],
      "villages": [...],
      "streets": [...],
      "divisions": [...]
    },
    "referenceData": {
      "identityCardTypes": [...],
      "schoolLevels": [...],
      "legalStatuses": [...]
    },
    "livestockReferenceData": {
      "species": [...],
      "livestockTypes": [...],
      "breeds": [...],
      "livestockObtainedMethods": [...]
    },
    "userSpecificData": {
      "type": "farmer",
      "farms": [...],          // Only for farmers
      "livestock": [...],      // Only for farmers
      "farmsCount": 5,
      "livestockCount": 120
    },
    "user": {
      "id": 1,
      "username": "farmer123",
      "email": "farmer@example.com",
      "role": "Farmer",
      "roleId": 45,
      "status": "active"
    }
  },
  "timestamp": "2024-10-24T10:30:00.000000Z"
}
```

---

### 2. **Initial Register Sync**
**Use Case:** Called during registration before user is authenticated  
**Endpoint:** `GET /api/v1/sync/initial-register`  
**Authentication:** Not Required

#### What It Does:
Returns only data needed for registration forms (locations, identity types, school levels)

#### Response Structure:
```json
{
  "status": true,
  "message": "Registration data retrieved successfully",
  "data": {
    "locations": {
      "countries": [...],
      "regions": [...],
      "districts": [...],
      "wards": [...],
      "villages": [...],
      "streets": [...],
      "divisions": [...]
    },
    "identityCardTypes": [...],
    "schoolLevels": [...]
  },
  "timestamp": "2024-10-24T10:30:00.000000Z"
}
```

---

### 3. **Sync All** (Generic Endpoint)
**Use Case:** General sync endpoint for all locations  
**Endpoint:** `GET /api/v1/sync/all`  
**Authentication:** Not Required

---

## 👥 Role-Based Data Access

### **Farmer Role** (`role: "Farmer"`)
**Gets:**
- ✅ All reference data (locations, breeds, species, etc.)
- ✅ **Their farms** (based on `roleId` = `farmerId`)
- ✅ **Their livestock** (all livestock in their farms)

**Logic:**
```
1. User → roleId (farmerId)
2. Find all farms where farmerId = user.roleId
3. Get farm IDs
4. Find all livestock where farmId IN (farm IDs)
```

**Example:**
- Farmer ID: 45
- Farms: [Farm #1, Farm #2, Farm #3]
- Livestock: All animals in those 3 farms

---

### **Extension Officer / Vet / Farm Invited User**
**Gets:**
- ✅ All reference data
- ⚠️ Assigned farms only (TODO: implement farm assignment logic)
- ⚠️ Livestock in assigned farms

---

### **System User** (Admin)
**Gets:**
- ✅ All reference data
- ℹ️ Access to all data via separate admin endpoints

---

## 📁 Controller Structure

All controllers follow the same clean pattern:

### **Reference Data Controllers** (Simple - One Method Only)
These return lookup/dropdown data:

1. **BreedController** → `fetchAll()`
2. **SpecieController** → `fetchAll()`
3. **LivestockTypeController** → `fetchAll()`
4. **LivestockObtainedMethodController** → `fetchAll()`
5. **IdentityCardTypeController** → `fetchAll()`
6. **SchoolLevelController** → `fetchAll()`
7. **LegalStatusController** → `fetchAll()`

**Pattern:**
```php
public function fetchAll(): array
{
    return Model::orderBy('name', 'asc')
        ->get()
        ->map(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                // ... other fields
            ];
        })
        ->toArray();
}
```

---

### **Location Controller** (Centralized Location Management)
Returns all location data:

- `fetchCountries()`
- `fetchRegions()`
- `fetchDistricts()`
- `fetchWards()`
- `fetchVillages()`
- `fetchStreets()`
- `fetchDivisions()`

---

### **Farm Controller** (User-Specific Data)
Methods:
- `index()` - Get all farms (admin)
- `getAllFarmsByFarmerId($farmerId)` - Get farms for specific farmer
- `show($farm)` - Get single farm
- **`fetchByFarmerId($farmerId)`** - Used by sync (returns array)

---

### **Livestock Controller** (User-Specific Data)
Methods:
- `index()` - Get all livestock (admin)
- `getAllLivestockByFarmIds($farmIds)` - Get livestock for specific farms
- `show($livestock)` - Get single livestock
- **`fetchByFarmIds($farmIds)`** - Used by sync (returns array)

---

## 🔄 Data Flow Diagram

```
┌─────────────────────────────────────────────────────────┐
│              FLUTTER APP (Splash Screen)                │
└─────────────────────┬───────────────────────────────────┘
                      │
                      │ HTTP GET /api/v1/sync/splash
                      │ Headers: Authorization: Bearer {token}
                      │
                      ▼
┌─────────────────────────────────────────────────────────┐
│               Laravel Backend - SyncController           │
│                    splashSync() Method                   │
└─────────────────────┬───────────────────────────────────┘
                      │
                      ├─► Extract User from Token
                      │   └─► user.role = "Farmer"
                      │   └─► user.roleId = 45 (farmerId)
                      │
                      ├─► Fetch Reference Data (EVERYONE)
                      │   ├─► LocationController
                      │   ├─► IdentityCardTypeController
                      │   ├─► SchoolLevelController
                      │   ├─► LegalStatusController
                      │   ├─► BreedController
                      │   ├─► SpecieController
                      │   ├─► LivestockTypeController
                      │   └─► LivestockObtainedMethodController
                      │
                      └─► Fetch User-Specific Data (ROLE-BASED)
                          └─► For Farmer (roleId = 45):
                              ├─► FarmController.fetchByFarmerId(45)
                              │   └─► Returns: [Farm #1, Farm #2, Farm #3]
                              │
                              └─► LivestockController.fetchByFarmIds([1, 2, 3])
                                  └─► Returns: All livestock in those farms
                      │
                      ▼
┌─────────────────────────────────────────────────────────┐
│                    JSON Response                         │
│  {                                                       │
│    "status": true,                                       │
│    "data": {                                             │
│      "locations": {...},                                 │
│      "referenceData": {...},                             │
│      "livestockReferenceData": {...},                    │
│      "userSpecificData": {                               │
│        "farms": [...],  ← Farmer's farms                 │
│        "livestock": [...] ← Farmer's livestock           │
│      }                                                    │
│    }                                                      │
│  }                                                        │
└─────────────────────┬───────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────┐
│         Flutter App - sync.dart                          │
│         Sync.splashSync(database)                        │
└─────────────────────┬───────────────────────────────────┘
                      │
                      ├─► Parse Response
                      │
                      ├─► Store Locations to Database
                      │   └─► AllAdditionalDataRepository.syncAndStoreLocally()
                      │       └─► Uses LocationDao to insert countries, regions, etc.
                      │
                      ├─► Store Reference Data
                      │   └─► Uses ReferenceDataDao
                      │
                      ├─► Store Livestock Reference Data
                      │   └─► SpecieRepository, BreedRepository, etc.
                      │
                      └─► Store User-Specific Data
                          ├─► FarmRepository.syncAndStoreLocally()
                          │   └─► Uses FarmDao
                          │
                          └─► LivestockRepository.syncAndStoreLocally()
                              └─► Uses LivestockDao
                      │
                      ▼
┌─────────────────────────────────────────────────────────┐
│          Local Drift Database (SQLite)                   │
│  ✅ All data stored locally for offline access           │
└─────────────────────────────────────────────────────────┘
```

---

## 📊 Database Relationship Flow

### **For Farmer (Example: farmerId = 45)**

```
User Table
  └─► id: 1
  └─► role: "Farmer"
  └─► roleId: 45  ← This is the farmerId

Farmers Table
  └─► id: 45
  └─► firstName: "John"
  └─► surname: "Doe"

Farms Table
  └─► Farm #1 (id: 1, farmerId: 45, name: "Green Valley Farm")
  └─► Farm #2 (id: 2, farmerId: 45, name: "Sunshine Farm")
  └─► Farm #3 (id: 3, farmerId: 45, name: "Mountain View Farm")

Livestock Table
  └─► Animal #1 (id: 1, farmId: 1, name: "Bessie")
  └─► Animal #2 (id: 2, farmId: 1, name: "Daisy")
  └─► Animal #3 (id: 3, farmId: 2, name: "Buttercup")
  └─► ... (all animals in farmer's farms)
```

---

## 🚀 Implementation Summary

### **Backend Controllers Created/Updated:**

| Controller | Location | Method | Purpose |
|------------|----------|--------|---------|
| **SyncController** | `Sync/` | `splashSync()` | Main sync endpoint |
| **FarmController** | `Farm/` | `fetchByFarmerId()` | Get farms for farmer |
| **LivestockController** | `Livestock/` | `fetchByFarmIds()` | Get livestock for farms |
| **BreedController** | `Breed/` | `fetchAll()` | Get all breeds |
| **SpecieController** | `Specie/` | `fetchAll()` | Get all species |
| **LivestockTypeController** | `LivestockType/` | `fetchAll()` | Get all livestock types |
| **LivestockObtainedMethodController** | `LivestockObtainedMethod/` | `fetchAll()` | Get all methods |
| **LegalStatusController** | `LegalStatus/` | `fetchAll()` | Get all legal statuses |
| **IdentityCardTypeController** | `IdentityCardType/` | `fetchAll()` | Get all ID types |
| **SchoolLevelController** | `SchoolLevel/` | `fetchAll()` | Get all school levels |
| **LocationController** | `Location/` | `fetch*()` | Get all locations |

---

## 📱 Flutter Integration

### **How Flutter App Uses It:**

```dart
// In splash_screen.dart or app initialization
Future<void> initializeApp() async {
  final database = AppDatabase();
  
  // Call splash sync
  await Sync.splashSync(database);
  
  // Now all data is stored locally!
  // User can use app offline
}
```

### **Flow in Flutter:**
1. App starts → Splash screen
2. Call `Sync.splashSync(database)`
3. Receive JSON response
4. Parse and store in local Drift database:
   - Locations → `LocationDao`
   - Reference data → `ReferenceDataDao`
   - Farms → `FarmDao`
   - Livestock → `LivestockDao`
5. Navigate to dashboard

---

## ✅ Benefits

1. **Single Request** - One API call gets everything
2. **Role-Based** - Farmers get their farms, admins get admin data
3. **Offline-First** - All data stored locally in Drift
4. **Clean Structure** - Easy to understand and maintain
5. **Consistent Pattern** - All controllers follow same structure

---

## 🔐 Authentication Flow

### **Farmer Example:**
```
1. Login → POST /api/v1/login
   Request: { "email": "farmer@example.com", "password": "..." }
   Response: { "token": "...", "user": { "role": "Farmer", "roleId": 45 } }

2. Store token in Flutter app

3. Splash Sync → GET /api/v1/sync/splash
   Headers: { "Authorization": "Bearer {token}" }
   Response: { "data": { "userSpecificData": { "farms": [...], "livestock": [...] } } }

4. App now has all farmer's data locally!
```

---

## 📝 Next Steps

To use this in your routes file (`routes/api.php`):

```php
use App\Http\Controllers\Sync\SyncController;

// Public sync endpoints
Route::get('/sync/initial-register', [SyncController::class, 'initialRegisterSync']);

// Protected sync endpoints (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/sync/splash', [SyncController::class, 'splashSync']);
    Route::get('/sync/all', [SyncController::class, 'syncAll']);
});
```

---

**All sync endpoints are ready to use!** 🎉


