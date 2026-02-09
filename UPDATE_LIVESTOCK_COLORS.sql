-- Update Colors for 15 Female Cattle Records
-- 60% (9 records) = primaryColor = 'black', secondaryColor = NULL
-- 40% (6 records) = randomized between:
--   - primaryColor = 'black', secondaryColor = 'white' (3 records)
--   - primaryColor = 'brown', secondaryColor = NULL (3 records)

-- 1. Update Farm Name
UPDATE farms
SET name = 'ShambaBora Mkuranga'
WHERE uuid = '1765649478569-891341182';

-- 2. Update Livestock Names to 'shambabora'
UPDATE livestocks
SET name = 'shambabora'
WHERE farmUuid = '1765649478569-891341182'
  AND identificationNumber IN (
    '044','032','048','049','039',
    '007','011','015','014','013',
    '071','072','018','012','000'
  );

-- 3. Set Colors: 60% (9 records) with primaryColor = 'black'
UPDATE livestocks
SET primaryColor = 'black',
    secondaryColor = NULL
WHERE farmUuid = '1765649478569-891341182'
  AND identificationNumber IN ('044', '032', '048', '049', '039', '007', '011', '015', '014');

-- 4. Set Colors: 3 records with primaryColor = 'black', secondaryColor = 'white'
UPDATE livestocks
SET primaryColor = 'black',
    secondaryColor = 'white'
WHERE farmUuid = '1765649478569-891341182'
  AND identificationNumber IN ('013', '071', '072');

-- 5. Set Colors: 3 records with primaryColor = 'brown'
UPDATE livestocks
SET primaryColor = 'brown',
    secondaryColor = NULL
WHERE farmUuid = '1765649478569-891341182'
  AND identificationNumber IN ('018', '012', '000');

-- Verify the updates
SELECT 
    uuid,
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

-- Verify farm name
SELECT id, uuid, name FROM farms WHERE uuid = '1765649478569-891341182';
