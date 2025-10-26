# Location API Documentation

## Overview
This module provides CRUD operations for geographic/administrative locations without role-based restrictions. All authenticated users can access these endpoints.

---

## Module Structure

```
app/Http/Controllers/Location/
├── CountryController.php
├── RegionController.php
├── DistrictController.php
├── WardController.php
└── VillageController.php
```

---

## Hierarchy

```
Country
  └── Region
       └── District
            └── Ward
                 └── Village
```

---

## API Endpoints

### Country Routes

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/countries` | Get all countries |
| POST | `/api/countries` | Create a new country |
| GET | `/api/countries/{id}` | Get a specific country |
| PUT | `/api/countries/{id}` | Update a country |
| DELETE | `/api/countries/{id}` | Delete a country |

### Region Routes

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/regions` | Get all regions |
| GET | `/api/regions?countryId={id}` | Get regions by country |
| POST | `/api/regions` | Create a new region |
| GET | `/api/regions/{id}` | Get a specific region |
| PUT | `/api/regions/{id}` | Update a region |
| DELETE | `/api/regions/{id}` | Delete a region |

### District Routes

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/districts` | Get all districts |
| GET | `/api/districts?regionId={id}` | Get districts by region |
| POST | `/api/districts` | Create a new district |
| GET | `/api/districts/{id}` | Get a specific district |
| PUT | `/api/districts/{id}` | Update a district |
| DELETE | `/api/districts/{id}` | Delete a district |

### Ward Routes

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/wards` | Get all wards |
| GET | `/api/wards?districtId={id}` | Get wards by district |
| POST | `/api/wards` | Create a new ward |
| GET | `/api/wards/{id}` | Get a specific ward |
| PUT | `/api/wards/{id}` | Update a ward |
| DELETE | `/api/wards/{id}` | Delete a ward |

### Village Routes

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/villages` | Get all villages |
| GET | `/api/villages?wardId={id}` | Get villages by ward |
| POST | `/api/villages` | Create a new village |
| GET | `/api/villages/{id}` | Get a specific village |
| PUT | `/api/villages/{id}` | Update a village |
| DELETE | `/api/villages/{id}` | Delete a village |

---

## Request/Response Examples

### Countries

#### Create Country
**Request:**
```http
POST /api/countries
Authorization: Bearer {token}
Content-Type: application/json

{
    "name": "Tanzania",
    "shortName": "TZ"
}
```

**Response (201 Created):**
```json
{
    "status": true,
    "message": "Country created successfully",
    "data": {
        "id": 1,
        "name": "Tanzania",
        "shortName": "TZ",
        "created_at": "2025-10-21T10:00:00.000000Z",
        "updated_at": "2025-10-21T10:00:00.000000Z"
    }
}
```

#### Get All Countries
**Request:**
```http
GET /api/countries
Authorization: Bearer {token}
```

**Response (200 OK):**
```json
{
    "status": true,
    "message": "Countries retrieved successfully",
    "data": [
        {
            "id": 1,
            "name": "Tanzania",
            "shortName": "TZ",
            "created_at": "2025-10-21T10:00:00.000000Z",
            "updated_at": "2025-10-21T10:00:00.000000Z"
        }
    ]
}
```

---

### Regions

#### Create Region
**Request:**
```http
POST /api/regions
Authorization: Bearer {token}
Content-Type: application/json

{
    "name": "Arusha",
    "shortName": "ARU",
    "countryId": 1
}
```

**Response (201 Created):**
```json
{
    "status": true,
    "message": "Region created successfully",
    "data": {
        "id": 1,
        "name": "Arusha",
        "shortName": "ARU",
        "countryId": 1,
        "country": {
            "id": 1,
            "name": "Tanzania",
            "shortName": "TZ"
        },
        "created_at": "2025-10-21T10:00:00.000000Z",
        "updated_at": "2025-10-21T10:00:00.000000Z"
    }
}
```

#### Get Regions by Country
**Request:**
```http
GET /api/regions?countryId=1
Authorization: Bearer {token}
```

**Response (200 OK):**
```json
{
    "status": true,
    "message": "Regions retrieved successfully",
    "data": [
        {
            "id": 1,
            "name": "Arusha",
            "shortName": "ARU",
            "countryId": 1,
            "country": {
                "id": 1,
                "name": "Tanzania",
                "shortName": "TZ"
            }
        }
    ]
}
```

---

### Districts

#### Create District
**Request:**
```http
POST /api/districts
Authorization: Bearer {token}
Content-Type: application/json

{
    "name": "Arumeru",
    "regionId": 1
}
```

**Response (201 Created):**
```json
{
    "status": true,
    "message": "District created successfully",
    "data": {
        "id": 1,
        "name": "Arumeru",
        "regionId": 1,
        "region": {
            "id": 1,
            "name": "Arusha",
            "shortName": "ARU",
            "country": {
                "id": 1,
                "name": "Tanzania",
                "shortName": "TZ"
            }
        },
        "created_at": "2025-10-21T10:00:00.000000Z",
        "updated_at": "2025-10-21T10:00:00.000000Z"
    }
}
```

---

### Wards

#### Create Ward
**Request:**
```http
POST /api/wards
Authorization: Bearer {token}
Content-Type: application/json

{
    "name": "Usa River",
    "districtId": 1
}
```

**Response (201 Created):**
```json
{
    "status": true,
    "message": "Ward created successfully",
    "data": {
        "id": 1,
        "name": "Usa River",
        "districtId": 1,
        "district": {
            "id": 1,
            "name": "Arumeru",
            "region": {
                "id": 1,
                "name": "Arusha",
                "country": {
                    "id": 1,
                    "name": "Tanzania"
                }
            }
        },
        "created_at": "2025-10-21T10:00:00.000000Z",
        "updated_at": "2025-10-21T10:00:00.000000Z"
    }
}
```

---

### Villages

#### Create Village
**Request:**
```http
POST /api/villages
Authorization: Bearer {token}
Content-Type: application/json

{
    "name": "Ilboru",
    "wardId": 1
}
```

**Response (201 Created):**
```json
{
    "status": true,
    "message": "Village created successfully",
    "data": {
        "id": 1,
        "name": "Ilboru",
        "wardId": 1,
        "ward": {
            "id": 1,
            "name": "Usa River",
            "district": {
                "id": 1,
                "name": "Arumeru",
                "region": {
                    "id": 1,
                    "name": "Arusha",
                    "country": {
                        "id": 1,
                        "name": "Tanzania"
                    }
                }
            }
        },
        "created_at": "2025-10-21T10:00:00.000000Z",
        "updated_at": "2025-10-21T10:00:00.000000Z"
    }
}
```

---

## Validation Rules

### Country
- `name`: required, string, max:255, unique
- `shortName`: required, string, max:10, unique

### Region
- `name`: required, string, max:255
- `shortName`: required, string, max:10
- `countryId`: required, integer, must exist in countries table

### District
- `name`: required, string, max:255
- `regionId`: required, integer, must exist in regions table

### Ward
- `name`: required, string, max:255
- `districtId`: required, integer, must exist in districts table

### Village
- `name`: required, string, max:255
- `wardId`: required, integer, must exist in wards table

---

## Error Responses

### Validation Error (422)
```json
{
    "status": false,
    "message": "Validation failed",
    "errors": {
        "name": ["The name field is required."],
        "countryId": ["The selected country id is invalid."]
    }
}
```

### Not Found (404)
```json
{
    "status": false,
    "message": "Not found"
}
```

### Conflict/Cannot Delete (409)
```json
{
    "status": false,
    "message": "Cannot delete country. It may have associated records.",
    "error": "Integrity constraint violation"
}
```

---

## Features

### ✅ Clean Code Architecture
- Organized in `Location` module
- Consistent naming conventions
- Standard CRUD operations
- Proper validation

### ✅ Relationship Loading
- Automatic eager loading of parent relationships
- Full hierarchy visible in responses
- Efficient queries with `with()`

### ✅ Filtering Support
- Filter regions by `countryId`
- Filter districts by `regionId`
- Filter wards by `districtId`
- Filter villages by `wardId`

### ✅ Error Handling
- Proper HTTP status codes
- Descriptive error messages
- Validation feedback
- Cascade delete protection

---

## Usage Examples

### Complete Workflow

**1. Create Country:**
```bash
curl -X POST http://localhost/api/countries \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"name": "Tanzania", "shortName": "TZ"}'
```

**2. Create Region:**
```bash
curl -X POST http://localhost/api/regions \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"name": "Arusha", "shortName": "ARU", "countryId": 1}'
```

**3. Create District:**
```bash
curl -X POST http://localhost/api/districts \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"name": "Arumeru", "regionId": 1}'
```

**4. Create Ward:**
```bash
curl -X POST http://localhost/api/wards \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"name": "Usa River", "districtId": 1}'
```

**5. Create Village:**
```bash
curl -X POST http://localhost/api/villages \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"name": "Ilboru", "wardId": 1}'
```

**6. Get All Villages with Full Hierarchy:**
```bash
curl -X GET http://localhost/api/villages \
  -H "Authorization: Bearer {token}"
```

---

## Best Practices

### ✅ DO
- Always authenticate requests with Bearer token
- Use filtering parameters for efficient queries
- Check parent exists before creating child
- Handle cascade delete errors gracefully

### ❌ DON'T
- Don't delete countries with existing regions
- Don't create orphaned records
- Don't skip validation
- Don't hardcode IDs

---

## Database Relationships

```sql
countries (id)
    └── regions (countryId → countries.id) CASCADE DELETE
         └── districts (regionId → regions.id) CASCADE DELETE
              └── wards (districtId → districts.id) CASCADE DELETE
                   └── villages (wardId → wards.id) CASCADE DELETE
```

---

## Summary

✅ **5 Controllers Created** - Country, Region, District, Ward, Village  
✅ **25 Routes Added** - 5 CRUD endpoints per controller  
✅ **No Role Restrictions** - All authenticated users can access  
✅ **Clean Architecture** - Organized in Location module  
✅ **Full CRUD Operations** - Create, Read, Update, Delete  
✅ **Relationship Loading** - Eager loading with parent data  
✅ **Filtering Support** - Query by parent ID  
✅ **Proper Validation** - Laravel validation rules  
✅ **Error Handling** - Descriptive responses  

The Location API is ready for use! 🎉

