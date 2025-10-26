# Old Database Connection Setup

## Configuration

Add these variables to your `.env` file to connect to the old `itag_dev` database:

```env
# Old Database Connection (for data migration)
OLD_DB_HOST=127.0.0.1
OLD_DB_PORT=3306
OLD_DB_DATABASE=itag_dev
OLD_DB_USERNAME=root
OLD_DB_PASSWORD=
```

## Migration Command

Once the `.env` is configured, run:

```bash
php artisan db:seed --class=MigrateReferenceDataSeeder
```

## What Gets Migrated

This will copy reference data from `itag_dev` to `new_tag_and_seal`:

- ✅ Legal Statuses (4 records)
- ✅ Species (1 record: Cattle)
- ✅ Livestock Types (5 records: Cattle, Swine, Goat, Sheep, Horse)
- ✅ Breeds (11 records: Singida White, Watusi, etc.)
- ✅ Livestock Obtained Methods (4 records: Purchased, Born on farm, Gift, Donation)

## Tables

### legal_statuses
- Certificate of Occupancy or Village Land Certificate
- Registered business entity with BRELA
- Valid business license from local authorities
- Phytosanitary certification for farms involved in exports

### species
- Cattle

### livestock_types
- Cattle
- Swine
- Goat
- Sheep or Lamb
- Horse

### breeds
- Singida White
- Watusi
- Iringa Red
- Zenga
- Bahima
- Mkalama Dun
- Chagga
- Pare
- Zanzibar Zebu
- Improved Boran

### livestock_obtained_methods
- Purchased
- Born on farm
- Gift
- Donation


