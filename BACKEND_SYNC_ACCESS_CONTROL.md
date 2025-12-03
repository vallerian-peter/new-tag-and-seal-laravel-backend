# Backend Sync Access Control Implementation

## ✅ **ANSWER: YES, Farm Invited Users CAN Sync!**

**Farm Invited Users are fully able to sync:**
- ✅ **Livestock** - For their assigned farms only (fully implemented)
- ✅ **Logs** - For livestock in their assigned farms only (fully implemented)
- ✅ **Vaccines** - Fully implemented with access validation and processing

**With strict access control:**
- All data is validated against assigned farms (`FarmUser.farmUuids`)
- Unauthorized data is filtered out before processing
- Complete audit trail in logs

---

## ✅ Overview

The backend sync system now properly handles both **farmers** and **farm invited users** with appropriate access control validation.

---

## 🔐 Access Control Rules

### **Farmers:**
- ✅ Can sync all their farms
- ✅ Can sync all their livestock
- ✅ Can sync all logs for their livestock
- ✅ Can sync all vaccines for their farms
- ✅ Can sync farm users they've created

### **Farm Invited Users / Extension Officers / Vets:**
- ❌ **Cannot create/sync farms** (only farmers can create farms)
- ✅ **CAN sync livestock** - only for livestock in assigned farms (with access validation)
- ✅ **CAN sync logs** - only for livestock in assigned farms (with access validation)
- ✅ **Vaccines sync** - Fully implemented with access validation and processing
- ❌ **Cannot create/sync farm users** (only farmers can create farm users)

---

## 🔧 Implementation Details

### **1. POST Sync (`postSync` method)**

**Location:** `/app/Http/Controllers/Sync/SyncController.php`

**Flow:**
1. Validates authenticated user matches requested userId
2. Processes data based on user role:
   - **Farmers:** Full access to all their data
   - **Farm Invited Users:** Access validated for assigned farms only

**Access Validation:**
- ✅ Farms: Only farmers can sync (farm invited users don't create farms)
- ✅ Livestock: Validated against assigned farms for farm invited users
- ✅ Logs: Validated that livestock belongs to assigned farms
- ✅ Vaccines: Validated against assigned farms
- ✅ Farm Users: Only farmers can sync (farm invited users don't create farm users)

---

### **2. GET Sync (`splashSync` method)**

**Location:** `/app/Http/Controllers/Sync/SyncController.php`

**Role Detection:**
```php
switch ($user->role) {
    case 'Farmer':
    case 'farmer':
        $data['userSpecificData'] = $this->getFarmerData($user->roleId);
        break;

    case 'Extension Officer':
    case 'extension officer':
    case 'Vet':
    case 'vet':
    case 'Farm Invited User':
        $data['userSpecificData'] = $this->getFieldWorkerData($user->roleId);
        break;
}
```

**Data Returned:**
- **Farmers:** All their farms, livestock, logs, vaccines, farm users
- **Farm Invited Users:** Only assigned farms (via `FarmUser.farmUuids` array), livestock in those farms, logs for that livestock, vaccines for those farms

---

## 🛡️ Access Validation Methods

### **Helper Methods Added:**

#### **1. `getAssignedFarmUuidsForUser(User $user): array`**
- Gets assigned farm UUIDs for farm invited users
- Returns empty array for farmers (they have access to all their farms)
- Retrieves from `FarmUser.farmUuids` (supports multiple farms)

#### **2. `validateLivestockAccess(array $livestock, array $assignedFarmUuids, int $userId): array`**
- Filters livestock to only those in assigned farms
- Logs rejected items for audit trail
- Returns filtered array

#### **3. `validateLivestockBelongsToFarms(string $livestockUuid, array $assignedFarmUuids): bool`**
- Checks if specific livestock belongs to assigned farms
- Used for log validation
- Returns true/false

#### **4. `getFarmerIdForLivestock(array $livestock, User $user): ?int`**
- Gets farmer ID from livestock's farm
- For farmers: Uses their roleId directly
- For farm invited users: Extracts from livestock's farmUuid → Farm.farmerId

#### **5. `validateVaccineAccess(array $vaccines, array $assignedFarmUuids, int $userId): array`**
- Filters vaccines to only those in assigned farms
- Logs rejected items
- Returns filtered array

---

## 📋 Processing Methods Updated

### **1. `processFarmSync()`**
- ✅ **Farmers:** Can sync all farms
- ❌ **Farm Invited Users:** Cannot sync farms (return empty array)
- Logic: Only farmers can create/modify farms

### **2. `processLivestockSync()`**
- ✅ **Farmers:** Can sync all their livestock
- ✅ **Farm Invited Users:** Can sync livestock in assigned farms only
- Validation: Filters livestock by assigned farm UUIDs
- Gets farmer ID from livestock's farm for processing

### **3. `processLogSync()`**
- ✅ **Farmers:** Can sync all logs for their livestock
- ✅ **Farm Invited Users:** Can sync logs for livestock in assigned farms only
- Validation: Checks each log's livestock belongs to assigned farms
- Rejects logs for unauthorized livestock

### **4. `processVaccineSync()`**
- ✅ **Farmers:** Can sync all vaccines for their farms
- ✅ **Farm Invited Users:** Can sync vaccines for assigned farms only
- Validation: Filters vaccines by assigned farm UUIDs
- Processing: Fully implemented with `VaccineController::processVaccines()` method

### **5. `processFarmUserSync()`**
- ✅ **Farmers:** Can sync farm users they've created
- ❌ **Farm Invited Users:** Cannot sync farm users (return empty array)
- Logic: Only farmers can create/modify farm users

---

## 🔍 Validation Flow Example

### **Farm Invited User Sync:**

```
1. User sends sync data:
   - Livestock: [livestock1 (farm-A), livestock2 (farm-B), livestock3 (farm-C)]
   - Logs: [log1 (livestock1), log2 (livestock2), log3 (livestock3)]
   - Assigned Farms: [farm-A, farm-B]

2. Backend Validation:
   ✅ livestock1 → farm-A → ALLOWED
   ✅ livestock2 → farm-B → ALLOWED
   ❌ livestock3 → farm-C → REJECTED (not in assigned farms)

   ✅ log1 → livestock1 (farm-A) → ALLOWED
   ✅ log2 → livestock2 (farm-B) → ALLOWED
   ❌ log3 → livestock3 (farm-C) → REJECTED

3. Processed Data:
   - Livestock: [livestock1, livestock2] (2 of 3)
   - Logs: [log1, log2] (2 of 3)
```

---

## 📊 Logging

All access validation is logged for audit trail:
- ✅ Allowed items: Normal logging
- ⚠️ Rejected items: Warning-level logging with reasons
- 📋 Summary counts: Info-level logging

**Example Log:**
```
[INFO] Livestock access validated - 2 of 3 items allowed
[WARNING] Livestock rejected - farm not assigned (userId: 123, livestockUuid: abc, farmUuid: farm-C)
```

---

## ✅ Security Features

1. **Access Validation:** All data is validated against assigned farms
2. **Role-Based Processing:** Different logic for farmers vs farm invited users
3. **Audit Trail:** All rejections are logged
4. **Data Isolation:** Farm invited users can only see/modify data in assigned farms
5. **No Data Leakage:** Rejected items are filtered out before processing

---

## 🎯 Summary

| Feature | Farmer | Farm Invited User |
|---------|--------|-------------------|
| **Sync Farms** | ✅ Yes | ❌ No |
| **Sync Livestock** | ✅ All | ✅ **YES** - Assigned farms only |
| **Sync Logs** | ✅ All | ✅ **YES** - Assigned livestock only |
| **Sync Vaccines** | ✅ All | ✅ **YES** - Assigned farms only |
| **Sync Farm Users** | ✅ Yes | ❌ No |
| **Access Validation** | ✅ None needed | ✅ Strict validation |

### ✅ **Farm Invited Users CAN Sync:**
1. **Livestock** - For assigned farms only (fully implemented)
2. **Logs** - For livestock in assigned farms only (fully implemented)
3. **Vaccines** - For assigned farms only (fully implemented)

### ❌ **Farm Invited Users CANNOT Sync:**
1. **Farms** - Only farmers can create/modify farms
2. **Farm Users** - Only farmers can create/modify farm users

---

## 🔄 Migration Notes

**Breaking Changes:**
- Farm invited users can no longer sync farms or farm users (by design)
- Access validation is now enforced for all sync operations

**Backward Compatibility:**
- Farmers: No changes, works as before
- Farm invited users: Now properly supported (previously blocked)

---

**Backend sync now properly handles both roles with secure access control!** ✅
