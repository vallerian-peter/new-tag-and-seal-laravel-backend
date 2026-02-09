-- =====================================================
-- UPDATE 15 Female Cattle Records with Colors
-- Farm: ShambaBora Mkuranga (UUID: 1765649478569-891341182)
-- =====================================================

-- Step 1: Update Farm Name
UPDATE farms
SET name = 'ShambaBora Mkuranga'
WHERE uuid = '1765649478569-891341182';

-- Step 2: Update All 15 Livestock Names to 'shambabora'
UPDATE livestocks
SET name = 'shambabora'
WHERE farmUuid = '1765649478569-891341182'
  AND identificationNumber IN (
    '044','032','048','049','039',
    '007','011','015','014','013',
    '071','072','018','012','000'
  );

-- Step 3: Set Colors - 60% (9 records) = Black only
UPDATE livestocks
SET primaryColor = 'black',
    secondaryColor = NULL
WHERE farmUuid = '1765649478569-891341182'
  AND identificationNumber IN ('044', '032', '048', '049', '039', '007', '011', '015', '014');

-- Step 4: Set Colors - 20% (3 records) = Black + White
UPDATE livestocks
SET primaryColor = 'black',
    secondaryColor = 'white'
WHERE farmUuid = '1765649478569-891341182'
  AND identificationNumber IN ('013', '071', '072');

-- Step 5: Set Colors - 20% (3 records) = Brown only
UPDATE livestocks
SET primaryColor = 'brown',
    secondaryColor = NULL
WHERE farmUuid = '1765649478569-891341182'
  AND identificationNumber IN ('018', '012', '000');

-- =====================================================
-- VERIFICATION QUERIES
-- =====================================================

-- Verify Farm Name
SELECT id, uuid, name FROM farms WHERE uuid = '1765649478569-891341182';

-- Verify All 15 Livestock Updates
SELECT 
    identificationNumber,
    name,
    primaryColor,
    secondaryColor,
    gender,
    status
FROM livestocks
WHERE farmUuid = '1765649478569-891341182'
  AND identificationNumber IN (
    '044','032','048','049','039',
    '007','011','015','014','013',
    '071','072','018','012','000'
  )
ORDER BY identificationNumber;

-- Count by Color Distribution
SELECT 
    primaryColor,
    secondaryColor,
    COUNT(*) as count
FROM livestocks
WHERE farmUuid = '1765649478569-891341182'
  AND identificationNumber IN (
    '044','032','048','049','039',
    '007','011','015','014','013',
    '071','072','018','012','000'
  )
GROUP BY primaryColor, secondaryColor
ORDER BY count DESC;
