# 🚀 Deployment Instructions for Live Server

## Issues Fixed

### ✅ Issue 1: Set User's Farm to Active Status
**Database Fix**: SQL script updates farm status to 'active'
**Farm UUID**: `1765649478569-891341182`
**Impact**: Farm will now sync to Flutter app (only active farms sync)

### ✅ Issue 2: SQL Script to Add Missing Cattle
**File**: `FIX_LIVE_SERVER.sql`
**Change**: Adds Cattle (ID=1) to livestock_types table
**Impact**: Cattle will appear in Flutter app dropdown

---

## 📋 Step-by-Step Deployment to Live Server

### Step 1: Run SQL on Live Database

```bash
# SSH into live server
ssh root@45.77.1.62

# Connect to MySQL
mysql -u your_db_user -p

# Use the database
USE tag_and_seal;  # or your database name

# Run the SQL fix
SOURCE /path/to/FIX_LIVE_SERVER.sql;
# OR copy-paste the SQL from FIX_LIVE_SERVER.sql

# Verify Cattle was added
SELECT * FROM livestock_types ORDER BY id;
```

**Expected Output**:
```
+----+----------------+---------------------+---------------------+
| id | name           | created_at          | updated_at          |
+----+----------------+---------------------+---------------------+
|  1 | Cattle         | 2026-01-24 XX:XX:XX | 2026-01-24 XX:XX:XX |
|  2 | Swine          | ...                 | ...                 |
|  3 | Goat           | ...                 | ...                 |
...
```

---

### Step 2: Deploy Updated Code to Live Server

**Option A: Direct File Edit on Live Server** (Quick Fix)

```bash
# SSH into live server
ssh root@45.77.1.62

# Navigate to live backend
cd /usr/apps/new-tag-and-seal-laravel-backend

# Edit the FarmController
nano app/Http/Controllers/Farm/FarmController.php

# Find line 193 and remove:
# ->where('status', 'active')

# Save and exit (Ctrl+X, Y, Enter)
```

**Option B: Git Deployment** (Recommended)

```bash
# On your local machine
cd /Applications/XAMPP/xamppfiles/htdocs/Backend/new-tag-and-seal-laravel-backend

# Commit the changes
git add .
git commit -m "fix: Remove status filter from farm sync and add Cattle to database"
git push origin main  # or your branch

# On live server
ssh root@45.77.1.62
cd /usr/apps/new-tag-and-seal-laravel-backend
git pull origin main  # or your branch
```

---

### Step 3: Clear Cache and Restart

```bash
# On live server
cd /usr/apps/new-tag-and-seal-laravel-backend

# Clear Laravel cache
php artisan cache:clear
php artisan config:clear
php artisan route:clear

# Restart PHP-FPM (if using)
sudo systemctl restart php-fpm
# OR restart Apache/Nginx
sudo systemctl restart nginx
# OR
sudo systemctl restart apache2
```

---

### Step 4: Test the Fixes

#### Test 1: Verify Cattle Appears in Sync Response
```bash
# Login
curl -X POST "http://45.77.1.62:8000/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "chachareli520@gmail.com",
    "password": "chachareli520@gmail.com"
  }'

# Use the returned token
curl "http://45.77.1.62:8000/api/v1/sync/splash-sync-all/32" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" | jq '.data.livestockReferenceData.livestockTypes'
```

**Expected**: Cattle (ID=1) should now appear in the list!

#### Test 2: Verify Farms Sync
```bash
curl "http://45.77.1.62:8000/api/v1/sync/splash-sync-all/32" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" | jq '.data.userSpecificData | {farms, farmsCount}'
```

**Expected**: Farms array should NOT be empty, farmsCount should be > 0

---

### Step 5: Test Flutter App

1. **Open Flutter app**
2. **Logout and Login again** (to force fresh sync)
3. **Check Dashboard**:
   - ✅ Farms should now appear
   - ✅ Livestock should display under their farms
4. **Check Livestock Form**:
   - ✅ Cattle should appear in Type dropdown

---

## 🔍 Troubleshooting

### If Cattle Still Doesn't Appear:
```bash
# Check if Cattle exists in database
mysql -u your_user -p -e "SELECT * FROM tag_and_seal.livestock_types WHERE id = 1;"
```

### If Farms Still Don't Sync:
```bash
# Check farm status
mysql -u your_user -p -e "
SELECT uuid, name, status 
FROM tag_and_seal.farms 
WHERE uuid = '1765649478569-891341182';"
```

### If Changes Don't Take Effect:
```bash
# Force cache clear and restart
php artisan optimize:clear
sudo systemctl restart php-fpm nginx
```

---

## 📞 Need Help?

If you encounter issues during deployment, check:
1. PHP error logs: `/var/log/php-fpm/error.log`
2. Laravel logs: `/usr/apps/new-tag-and-seal-laravel-backend/storage/logs/laravel.log`
3. Nginx/Apache logs: `/var/log/nginx/error.log`

---

## ✅ Verification Checklist

- [ ] SQL script executed successfully
- [ ] Cattle (ID=1) exists in livestock_types table
- [ ] Code changes deployed to live server
- [ ] Cache cleared
- [ ] PHP-FPM/Nginx restarted
- [ ] API test shows Cattle in livestock types
- [ ] API test shows farms are syncing
- [ ] Flutter app shows farms on dashboard
- [ ] Flutter app shows Cattle in dropdown
