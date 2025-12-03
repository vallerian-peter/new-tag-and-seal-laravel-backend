# Data Audit Report - Multi-Livestock System

## Summary
This report shows the current state of all reference data tables and their distribution across livestock types.

## Livestock Types (9 total)
1. Cattle
2. Swine
3. Goat
4. Sheep or Lamb
5. Horse
6. Chicken
7. Turkey
8. Duck
9. Other

---

## 1. STAGES Table

### Current State:
- **Total Records**: 13
- **Cattle (ID: 1)**: 6 stages
  - Calf, Weaner, Heifer, Cow, Steer, Bull
- **Swine (ID: 2)**: 7 stages
  - Piglet, Weaner, Gilt, Sow, Barrow, Stag, Boar
- **Other Types**: 0 stages each

### Issues Found:
- ✅ Stages table correctly requires `livestockTypeId` (not nullable)
- ⚠️ Other livestock types (Goat, Sheep, etc.) have no stages seeded

---

## 2. BIRTH_TYPES Table

### Current State:
- **Total Records**: 11
- **Generic (NULL)**: 4 types
  - Abnormal, Assisted, Natural, Normal
- **Cattle (ID: 1)**: 4 types
  - Abnormal Calving, Assisted Calving, Caesarean Section, Normal Calving
- **Swine (ID: 2)**: 3 types
  - Abnormal Farrowing, Assisted Farrowing, Normal Farrowing

### Issues Found:
- ⚠️ **DUPLICATE/REUNDANT**: Generic types "Natural" and "Assisted" seem redundant with "Normal" and "Assisted"
- ⚠️ Generic "Abnormal" and "Normal" might conflict with species-specific names

---

## 3. BIRTH_PROBLEMS Table

### Current State:
- **Total Records**: 14
- **Generic (NULL)**: 6 problems
  - Dystocia, Fetatomy, No Problem, None, Respiratory problem, Retained Placenta
- **Cattle (ID: 1)**: 5 problems
  - Calving Related Nerve Paralysis, Fetatomy, Mastitis, Milk Fever, Surgical Procedure
- **Swine (ID: 2)**: 3 problems
  - Prolonged Farrowing, Stillborn Piglets, Weak Piglets

### Issues Found:
- ⚠️ **DUPLICATE**: "Fetatomy" appears in both Generic (NULL) and Cattle (ID: 1)
- ⚠️ Generic "No Problem" and "None" are duplicates
- ⚠️ "Respiratory problem" seems out of place for birth problems

---

## 4. BREEDS Table

### Current State:
- **Structure**: Has `livestockTypeId` (required, not nullable)
- **Status**: ✅ Correctly structured for multi-livestock support

---

## 5. BIRTH_EVENTS Table

### Current State:
- **Total Records**: 1
- **Structure**: 
  - Has `eventType` enum (calving, farrowing)
  - References `birth_types` and `birth_problems`
  - ✅ Correctly structured

---

## 6. ABORTED_PREGNANCIES Table

### Current State:
- **Total Records**: 0
- **Structure**: ✅ Correctly structured (pig-specific event)

---

## Recommendations

### High Priority:
1. **Remove duplicate "Fetatomy"** from generic birth problems (keep only in Cattle)
2. **Clean up generic birth types**: Remove "Natural" (redundant with "Normal")
3. **Remove duplicate "None"** from generic birth problems (keep "No Problem" or vice versa)
4. **Review "Respiratory problem"** - should this be a birth problem?

### Medium Priority:
1. Consider seeding stages for other livestock types (Goat, Sheep, etc.) when needed
2. Review generic birth types naming for clarity

### Low Priority:
1. Add more species-specific birth types/problems as needed
2. Document which generic types are applicable to which species

---

## Tables Status Summary

| Table | Has livestockTypeId | Status | Issues |
|-------|---------------------|--------|--------|
| stages | ✅ Required | ✅ OK | Missing data for other types |
| birth_types | ✅ Nullable | ⚠️ Needs cleanup | Duplicate/redundant generic types |
| birth_problems | ✅ Nullable | ⚠️ Needs cleanup | Duplicate entries |
| breeds | ✅ Required | ✅ OK | - |
| birth_events | ✅ (via eventType) | ✅ OK | - |
| aborted_pregnancies | N/A (pig-only) | ✅ OK | - |

