-- ============================================================================
-- Add Cattle (ID=1) to Live Server Database
-- ============================================================================
-- Copy this SQL and run it on the live server

USE tag_and_seal;

-- Check if Cattle already exists
SELECT * FROM livestock_types WHERE id = 1;

-- Add Cattle with ID=1
INSERT INTO livestock_types (id, name, created_at, updated_at)
VALUES (1, 'Cattle', NOW(), NOW())
ON DUPLICATE KEY UPDATE
    name = 'Cattle',
    updated_at = NOW();

-- Verify Cattle was added successfully
SELECT * FROM livestock_types ORDER BY id;

-- Expected output should show:
-- | 1  | Cattle           |
-- | 2  | Swine            |
-- | 3  | Goat             |
-- | 4  | Sheep or Lamb    |
-- | 5  | Horse            |
-- | 6  | Chicken          |
-- | 7  | Turkey           |
-- | 8  | Duck             |
-- | 10 | Pets             |
