-- ============================================================================
-- SQL Script to Fix Live Server Database Issues
-- ============================================================================
-- Run this script on the LIVE database to fix the missing Cattle issue
-- Database: tag_and_seal (or your live database name)
-- ============================================================================

-- Fix 1: Add Cattle (ID=1) to livestock_types table
-- ----------------------------------------------------------------------------
INSERT INTO livestock_types (id, name, created_at, updated_at)
VALUES (1, 'Cattle', NOW(), NOW())
ON DUPLICATE KEY UPDATE
    name = 'Cattle',
    updated_at = NOW();

-- Verify Cattle was added
SELECT * FROM livestock_types ORDER BY id;

-- ============================================================================
-- Expected Result:
-- ============================================================================
-- | id | name             | created_at          | updated_at          |
-- |----|------------------|---------------------|---------------------|
-- | 1  | Cattle           | 2026-01-24 XX:XX:XX | 2026-01-24 XX:XX:XX |
-- | 2  | Swine            | ...                 | ...                 |
-- | 3  | Goat             | ...                 | ...                 |
-- | 4  | Sheep or Lamb    | ...                 | ...                 |
-- | 5  | Horse            | ...                 | ...                 |
-- | 6  | Chicken          | ...                 | ...                 |
-- | 7  | Turkey           | ...                 | ...                 |
-- | 8  | Duck             | ...                 | ...                 |
-- | 10 | Pets             | ...                 | ...                 |
-- ============================================================================

-- Fix 2: Check and Fix Farm Status (CRITICAL!)
-- ----------------------------------------------------------------------------
-- This will show you which farms are not 'active' and causing sync issues
SELECT 
    id,
    uuid,
    name,
    status,
    farmerId,
    created_at
FROM farms
WHERE uuid = '1765649478569-891341182'; -- User's farm from logs

-- ============================================================================
-- If the query above shows status = 'not-active', that's why the farm
-- is not syncing to the Flutter app!
-- ============================================================================

-- FIX: Set the user's farm to 'active' status
-- ----------------------------------------------------------------------------
UPDATE farms 
SET status = 'active', updated_at = NOW()
WHERE uuid = '1765649478569-891341182';

-- Verify the update
SELECT uuid, name, status FROM farms WHERE uuid = '1765649478569-891341182';

-- OPTIONAL: Set ALL farms to active (if needed)
-- ----------------------------------------------------------------------------
-- Uncomment if you want to activate all farms:
-- UPDATE farms SET status = 'active', updated_at = NOW() WHERE status != 'active';

-- ============================================================================
-- AFTER RUNNING THIS SQL:
-- ============================================================================
-- 1. Restart the Laravel app on live server: sudo systemctl restart php-fpm
-- 2. Clear Laravel cache: php artisan cache:clear
-- 3. Test the Flutter app - Cattle should now appear in dropdown
-- 4. Test farm sync - Farms should now sync if status was the issue
-- ============================================================================
