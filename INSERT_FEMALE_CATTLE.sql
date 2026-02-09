-- Insert 15 Female Cattle Records
-- Mapping identificationNumbers from notebook images to livestockUuid from milkings table
-- Farm UUID: 1765649478569-891341182
-- Livestock Type ID (Cattle): 1
-- Species ID (Cow): 10
-- Breed ID (Holstein): 30
-- Gender: female
-- Status: active

-- Format: All identificationNumbers are formatted as 3-digit codes (000-999) with leading zeros
-- Mapped IDs: 044, 032, 048, 049, 039, 007, 011, 015, 014, 013, 071, 072, 018, 012, 000 (Mpya placeholder)
-- Color Distribution:
--   - 60% (9 records): primaryColor = 'black', secondaryColor = NULL (IDs: 044, 032, 048, 049, 039, 007, 011, 015, 014)
--   - 20% (3 records): primaryColor = 'black', secondaryColor = 'white' (IDs: 013, 071, 072)
--   - 20% (3 records): primaryColor = 'brown', secondaryColor = NULL (IDs: 018, 012, 000)
-- Note: One UUID (1765765559320-1005852192) is left unmapped as we only have 15 unique IDs from the pages
-- Note: "Mpya" (Swahili for "New") is mapped as "000" - this may need to be updated when proper ID is available
-- Note: All livestock names are set to 'shambabora'

INSERT INTO livestocks (
    farmUuid,
    uuid,
    identificationNumber,
    dummyTagId,
    barcodeTagId,
    rfidTagId,
    livestockTypeId,
    name,
    dateOfBirth,
    motherUuid,
    fatherUuid,
    gender,
    breedId,
    speciesId,
    status,
    livestockObtainedMethodId,
    dateFirstEnteredToFarm,
    weightAsOnRegistration,
    primaryColor,
    secondaryColor,
    created_at,
    updated_at
) VALUES
-- Mapped records (15) - All identificationNumbers formatted as 3-digit codes (000-999)
-- Color distribution: 60% black only (9), 20% black+white (3), 20% brown only (3)
('1765649478569-891341182', '1765737474297-1005852192', '044', NULL, NULL, NULL, 1, 'shambabora', '2020-01-15', NULL, NULL, 'female', 30, 10, 'active', NULL, '2020-01-15', '250', 'black', NULL, NOW(), NOW()),
('1765649478569-891341182', '1765737142511-1005852192', '032', NULL, NULL, NULL, 1, 'shambabora', '2020-02-20', NULL, NULL, 'female', 30, 10, 'active', NULL, '2020-02-20', '280', 'black', NULL, NOW(), NOW()),
('1765649478569-891341182', '1765737612501-1005852192', '048', NULL, NULL, NULL, 1, 'shambabora', '2019-12-10', NULL, NULL, 'female', 30, 10, 'active', NULL, '2019-12-10', '300', 'black', NULL, NOW(), NOW()),
('1765649478569-891341182', '1765737750060-1005852192', '049', NULL, NULL, NULL, 1, 'shambabora', '2020-03-05', NULL, NULL, 'female', 30, 10, 'active', NULL, '2020-03-05', '270', 'black', NULL, NOW(), NOW()),
('1765649478569-891341182', '1765738301735-1005852192', '039', NULL, NULL, NULL, 1, 'shambabora', '2020-01-25', NULL, NULL, 'female', 30, 10, 'active', NULL, '2020-01-25', '260', 'black', NULL, NOW(), NOW()),
('1765649478569-891341182', '1765738482933-1005852192', '007', NULL, NULL, NULL, 1, 'shambabora', '2020-04-12', NULL, NULL, 'female', 30, 10, 'active', NULL, '2020-04-12', '240', 'black', NULL, NOW(), NOW()),
('1765649478569-891341182', '1765765924324-181506292', '011', NULL, NULL, NULL, 1, 'shambabora', '2020-02-08', NULL, NULL, 'female', 30, 10, 'active', NULL, '2020-02-08', '290', 'black', NULL, NOW(), NOW()),
('1765649478569-891341182', '1765907213619-1005852192', '015', NULL, NULL, NULL, 1, 'shambabora', '2020-03-18', NULL, NULL, 'female', 30, 10, 'active', NULL, '2020-03-18', '275', 'black', NULL, NOW(), NOW()),
('1765649478569-891341182', '1765736803268-1005852192', '014', NULL, NULL, NULL, 1, 'shambabora', '2020-01-30', NULL, NULL, 'female', 30, 10, 'active', NULL, '2020-01-30', '265', 'black', NULL, NOW(), NOW()),
('1765649478569-891341182', '1765764384562-1005852192', '013', NULL, NULL, NULL, 1, 'shambabora', '2020-02-15', NULL, NULL, 'female', 30, 10, 'active', NULL, '2020-02-15', '285', 'black', 'white', NOW(), NOW()),
('1765649478569-891341182', '1765737336206-1005852192', '071', NULL, NULL, NULL, 1, 'shambabora', '2020-04-01', NULL, NULL, 'female', 30, 10, 'active', NULL, '2020-04-01', '255', 'black', 'white', NOW(), NOW()),
('1765649478569-891341182', '1765736227116-1005852192', '072', NULL, NULL, NULL, 1, 'shambabora', '2020-03-22', NULL, NULL, 'female', 30, 10, 'active', NULL, '2020-03-22', '245', 'black', 'white', NOW(), NOW()),
('1765649478569-891341182', '1765738128976-1005852192', '018', NULL, NULL, NULL, 1, 'shambabora', '2020-02-28', NULL, NULL, 'female', 30, 10, 'active', NULL, '2020-02-28', '250', 'brown', NULL, NOW(), NOW()),
('1765649478569-891341182', '1765736612497-1005852192', '012', NULL, NULL, NULL, 1, 'shambabora', '2020-04-05', NULL, NULL, 'female', 30, 10, 'active', NULL, '2020-04-05', '235', 'brown', NULL, NOW(), NOW()),
('1765649478569-891341182', '1765736034578-1005852192', '000', NULL, NULL, NULL, 1, 'shambabora', '2020-05-01', NULL, NULL, 'female', 30, 10, 'active', NULL, '2020-05-01', '220', 'brown', NULL, NOW(), NOW());

-- Unmapped UUID (to be mapped later when more data is available):
-- '1765765559320-1005852192' (1 milking record)

-- Verify the inserts
SELECT 
    uuid,
    identificationNumber,
    name,
    livestockTypeId,
    speciesId,
    breedId,
    gender,
    status
FROM livestocks
WHERE farmUuid = '1765649478569-891341182'
ORDER BY identificationNumber;
