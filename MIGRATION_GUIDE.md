# Data Migration Guide

## Migrating Location Data from Old Database to New Database

This guide explains how to migrate countries, regions, districts, wards, and villages from the old `itag-mycattle-apis-v1-master` database to the new `new_tag_and_seal_backend` database.

---

## Step 1: Configure Old Database Connection

### Update `.env` file

Add these lines to your `.env` file in the `new_tag_and_seal_backend` project:

```env
# Old Database Connection (itag-mycattle-apis-v1-master)
OLD_DB_HOST=127.0.0.1
OLD_DB_PORT=3306
OLD_DB_DATABASE=itag_mycattle
OLD_DB_USERNAME=root
OLD_DB_PASSWORD=
```

**Note:** Replace `itag_mycattle` with your actual old database name.

---

## Step 2: Run the Migration Seeder

Execute the following command to migrate location data:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/new_tag_and_seal_backend
php artisan db:seed --class=MigrateLocationsFromOldDatabase
```

---

## What Gets Migrated

### Data Flow

```
Old Database (snake_case)          →    New Database (camelCase)
─────────────────────────────────────────────────────────────────
countries.id                        →    countries.id
countries.name                      →    countries.name
countries.short_name                →    countries.shortName

regions.id                          →    regions.id
regions.name                        →    regions.name
regions.country_id                  →    regions.countryId
                                         regions.shortName (auto-generated)

districts.id                        →    districts.id
districts.name                      →    districts.name
districts.region_id                 →    districts.regionId

wards.id                            →    wards.id
wards.name                          →    wards.name
wards.district_id                   →    wards.districtId

villages.id                         →    villages.id
villages.name                       →    villages.name
villages.ward_id                    →    villages.wardId
```

### Migration Order

The seeder migrates in the correct hierarchy order:
1. ✅ Countries (parent)
2. ✅ Regions (depends on countries)
3. ✅ Districts (depends on regions)
4. ✅ Wards (depends on districts)
5. ✅ Villages (depends on wards)

---

## Seeder Features

### ✅ Safe Migration
- Uses `updateOrInsert()` - won't create duplicates
- Preserves original IDs - maintains referential integrity
- Auto-generates missing data (e.g., shortName for regions)
- Handles null timestamps gracefully

### ✅ Progress Feedback
The seeder provides real-time progress:
```
Migrating countries...
Migrated 10 countries
Migrating regions...
Migrated 25 regions
Migrating districts...
Migrated 150 districts
Migrating wards...
Migrated 500 wards
Migrating villages...
Migrated 2000 villages
Location data migration completed successfully!
```

---

## Verification

### Check Migrated Data

After migration, verify the data:

```bash
# Check counts
php artisan tinker
>>> DB::table('countries')->count()
>>> DB::table('regions')->count()
>>> DB::table('districts')->count()
>>> DB::table('wards')->count()
>>> DB::table('villages')->count()
```

### Test Sync Endpoint

```bash
curl -X GET http://localhost/api/sync/all \
  -H "Authorization: Bearer {token}"
```

**Expected Response:**
```json
{
    "status": true,
    "message": "All sync data retrieved successfully",
    "data": {
        "locations": {
            "countries": [...],
            "regions": [...],
            "districts": [...],
            "wards": [...],
            "villages": [...]
        }
    },
    "timestamp": "2025-10-21T10:00:00+00:00"
}
```

---

## Troubleshooting

### Error: Connection Refused

**Problem:** Cannot connect to old database

**Solution:**
1. Check old database is running
2. Verify database name in `.env`
3. Check credentials (username/password)
4. Ensure MySQL/MariaDB is running

```bash
# Test connection
mysql -u root -p
USE itag_mycattle;
SHOW TABLES;
```

### Error: Table Not Found

**Problem:** Old database tables don't exist

**Solution:**
1. Verify old database name
2. Check table names match (countries, regions, districts, wards, villages)
3. Run migrations on old database if needed

### Error: Foreign Key Constraint

**Problem:** Parent record doesn't exist

**Solution:**
The seeder migrates in correct order (parent first). If error persists:
1. Check old database has valid foreign keys
2. Run seeder again (updateOrInsert is safe to re-run)

---

## Manual Migration (Alternative)

If you prefer manual export/import:

### Step 1: Export from Old Database
```bash
mysqldump -u root -p itag_mycattle \
  countries regions districts wards villages \
  > locations_backup.sql
```

### Step 2: Transform Data
Use the provided seeder or create SQL scripts to transform:
- `snake_case` → `camelCase`
- Preserve IDs
- Generate missing fields

### Step 3: Import to New Database
```bash
mysql -u root -p tag_and_seal_new < locations_transformed.sql
```

---

## Post-Migration

After successful migration:

### 1. Verify Data Integrity
```sql
-- Check all regions have valid countries
SELECT * FROM regions WHERE countryId NOT IN (SELECT id FROM countries);

-- Check all districts have valid regions
SELECT * FROM districts WHERE regionId NOT IN (SELECT id FROM regions);

-- Check all wards have valid districts
SELECT * FROM wards WHERE districtId NOT IN (SELECT id FROM districts);

-- Check all villages have valid wards
SELECT * FROM villages WHERE wardId NOT IN (SELECT id FROM wards);
```

### 2. Update App Configuration
Ensure your app uses the new database connection.

### 3. Test Sync Endpoint
Use the `/api/sync/all` endpoint to verify data is accessible.

---

## Summary

✅ **Seeder Created:** `MigrateLocationsFromOldDatabase.php`  
✅ **Database Connection:** `old_itag` configured  
✅ **Migration Order:** Parent → Child (countries → villages)  
✅ **Field Mapping:** snake_case → camelCase  
✅ **Safe Migration:** updateOrInsert prevents duplicates  
✅ **ID Preservation:** Maintains referential integrity  

**Run the migration with:**
```bash
php artisan db:seed --class=MigrateLocationsFromOldDatabase
```

