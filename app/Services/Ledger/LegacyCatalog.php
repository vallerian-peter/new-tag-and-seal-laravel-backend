<?php

namespace App\Services\Ledger;

/**
 * How every legacy log table maps onto the event ledger: the family it belongs to,
 * the configured events that replace it per species, and the column ↔ field mapping.
 *
 * Field keys are the payload keys of the configured form. A field with `role` feeds the
 * behaviour; a field with `lookup` stores the option code of a mirrored reference table.
 */
class LegacyCatalog
{
    public const FAMILIES = ['births', 'health', 'production', 'breeding', 'growth', 'movement'];

    /** Canonical species codes the legacy app records events for. */
    public const LEGACY_SPECIES = ['cattle', 'pig', 'goat', 'sheep', 'chicken'];

    private const RUMINANTS = ['cattle', 'goat', 'sheep'];

    private const MAMMALS = ['cattle', 'pig', 'goat', 'sheep'];

    /** @return array<string, array<string, mixed>> table => definition */
    public static function tables(): array
    {
        return [
            'birth_events' => [
                'family' => 'births',
                'events' => [
                    self::event('record_calving', 'Calving', 'Kuzaa (ndama)', 'record_birth', self::RUMINANTS, 'calving', ['eventType' => 'calving'], 'female'),
                    self::event('record_farrowing', 'Farrowing', 'Kuzaa (nguruwe)', 'record_birth', ['pig'], 'farrowing', ['eventType' => 'farrowing'], 'female'),
                ],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('aliveCount', 'born_alive', 'integer', true, ['role' => 'born_alive', 'label' => ['en' => 'Born alive', 'sw' => 'Waliozaliwa hai']]),
                    self::field('deadCount', 'born_dead', 'integer', false, ['role' => 'born_dead', 'label' => ['en' => 'Born dead', 'sw' => 'Waliozaliwa wafu']]),
                    self::field('birthTypeId', 'birth_type', 'single_select', true, ['lookup' => 'birth_types', 'role' => 'birth_type', 'label' => ['en' => 'Birth type', 'sw' => 'Aina ya uzazi']]),
                    self::field('birthProblemsId', 'birth_problem', 'single_select', false, ['lookup' => 'birth_problems', 'label' => ['en' => 'Birth problem', 'sw' => 'Tatizo la uzazi']]),
                    self::field('reproductiveProblemId', 'reproductive_problem', 'single_select', false, ['lookup' => 'reproductive_problems', 'label' => ['en' => 'Reproductive problem', 'sw' => 'Tatizo la uzazi wa mnyama']]),
                    self::field('startDate', 'labour_started_on', 'date', false, ['label' => ['en' => 'Labour started on', 'sw' => 'Uchungu ulianza']]),
                    self::field('endDate', 'labour_ended_on', 'date', false, ['label' => ['en' => 'Labour ended on', 'sw' => 'Uchungu uliisha']]),
                    self::field('remarks', 'remarks', 'multiline_text', false),
                ],
                'offspring' => true,
                'derived' => ['totalBorn' => ['sum' => ['aliveCount', 'deadCount']], 'startDate' => ['default_event_date' => true]],
                'void' => ['status' => 'not_active'],
            ],
            'aborted_pregnancies' => [
                'family' => 'births',
                'events' => [self::event('record_abortion', 'Aborted pregnancy', 'Mimba iliyoharibika', 'record_only', self::MAMMALS, 'abortedPregnancy', [], 'female')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('abortionDate', 'abortion_date', 'date', true, ['label' => ['en' => 'Abortion date', 'sw' => 'Tarehe ya kuharibika']]),
                    self::field('reproductiveProblemId', 'reproductive_problem', 'single_select', false, ['lookup' => 'reproductive_problems', 'label' => ['en' => 'Reproductive problem', 'sw' => 'Tatizo la uzazi wa mnyama']]),
                    self::field('remarks', 'remarks', 'multiline_text', false),
                ],
                'void' => ['status' => 'inactive'],
            ],
            'vaccinations' => [
                'family' => 'health',
                'events' => [self::event('record_vaccination', 'Vaccination', 'Chanjo', 'record_only', self::LEGACY_SPECIES, 'vaccination')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('vaccinationNo', 'vaccination_number', 'text', true, ['label' => ['en' => 'Vaccination number', 'sw' => 'Namba ya chanjo']]),
                    self::field('vaccineUuid', 'vaccine', 'text', false, ['label' => ['en' => 'Vaccine', 'sw' => 'Chanjo']]),
                    self::field('diseaseId', 'disease', 'single_select', true, ['lookup' => 'diseases', 'label' => ['en' => 'Disease', 'sw' => 'Ugonjwa']]),
                    self::field('vetId', 'vet', 'text', false, ['label' => ['en' => 'Veterinarian', 'sw' => 'Daktari wa mifugo']]),
                    self::field('extensionOfficerId', 'extension_officer', 'text', false, ['label' => ['en' => 'Extension officer', 'sw' => 'Afisa ugani']]),
                ],
                'void' => ['status' => 'failed'],
            ],
            'dewormings' => [
                'family' => 'health',
                'events' => [self::event('record_deworming', 'Deworming', 'Dawa ya minyoo', 'record_treatment', self::LEGACY_SPECIES, 'deworming')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('medicineId', 'medicine', 'single_select', true, ['lookup' => 'medicines', 'role' => 'product', 'label' => ['en' => 'Medicine', 'sw' => 'Dawa']]),
                    self::field('administrationRouteId', 'administration_route', 'single_select', true, ['lookup' => 'administration_routes', 'label' => ['en' => 'Administration route', 'sw' => 'Njia ya kutoa dawa']]),
                    self::field('quantity', 'quantity', 'text', true, ['label' => ['en' => 'Quantity', 'sw' => 'Kiasi']]),
                    self::field('dose', 'dose_text', 'text', false, ['label' => ['en' => 'Dose', 'sw' => 'Kipimo']]),
                    self::field('nextAdministrationDate', 'next_administration_date', 'date', false, ['label' => ['en' => 'Next administration', 'sw' => 'Dozi inayofuata']]),
                    self::field('vetId', 'vet', 'text', false, ['label' => ['en' => 'Veterinarian', 'sw' => 'Daktari wa mifugo']]),
                    self::field('extensionOfficerId', 'extension_officer', 'text', false, ['label' => ['en' => 'Extension officer', 'sw' => 'Afisa ugani']]),
                ],
            ],
            'treatments' => [
                'family' => 'health',
                'events' => [self::event('record_medication', 'Treatment', 'Matibabu', 'record_treatment', self::LEGACY_SPECIES, 'treatment')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('diseaseId', 'disease', 'single_select', true, ['lookup' => 'diseases', 'label' => ['en' => 'Disease', 'sw' => 'Ugonjwa']]),
                    self::field('medicineId', 'medicine', 'single_select', true, ['lookup' => 'medicines', 'role' => 'product', 'label' => ['en' => 'Medicine', 'sw' => 'Dawa']]),
                    self::field('quantity', 'quantity', 'text', false, ['label' => ['en' => 'Quantity', 'sw' => 'Kiasi']]),
                    self::field('withdrawalPeriod', 'withdrawal_period', 'text', false, ['label' => ['en' => 'Withdrawal period', 'sw' => 'Muda wa kusubiri']]),
                    self::field('medicationDate', 'medication_date', 'date', false, ['label' => ['en' => 'Medication date', 'sw' => 'Tarehe ya dawa']]),
                    self::field('nextMedicationDate', 'next_medication_date', 'date', false, ['label' => ['en' => 'Next medication', 'sw' => 'Dawa inayofuata']]),
                    self::field('remarks', 'remarks', 'multiline_text', false),
                ],
            ],
            'milkings' => [
                'family' => 'production',
                'events' => [self::event('record_milking', 'Milking', 'Kukamua', 'record_production_yield', self::RUMINANTS, 'milking', [], 'female')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('amount', 'milk_quantity', 'measurement', true, ['role' => 'quantity', 'unit' => 'l', 'label' => ['en' => 'Milk quantity', 'sw' => 'Kiasi cha maziwa']]),
                    self::field(null, 'product', 'single_select', true, ['role' => 'product', 'constant' => 'milk', 'options' => [['value' => 'milk', 'label' => ['en' => 'Milk', 'sw' => 'Maziwa']]], 'label' => ['en' => 'Product', 'sw' => 'Bidhaa']]),
                    self::field('session', 'session', 'single_select', false, ['options' => self::options(['morning' => ['Morning', 'Asubuhi'], 'evening' => ['Evening', 'Jioni'], 'night' => ['Night', 'Usiku'], 'midnight' => ['Midnight', 'Usiku wa manane']]), 'label' => ['en' => 'Session', 'sw' => 'Kipindi']]),
                    self::field('milkingMethodId', 'milking_method', 'single_select', false, ['lookup' => 'milking_methods', 'label' => ['en' => 'Milking method', 'sw' => 'Njia ya kukamua']]),
                    self::field('lactometerReading', 'lactometer_reading', 'decimal', false, ['label' => ['en' => 'Lactometer reading', 'sw' => 'Kipimo cha lactometer']]),
                    self::field('correctedLactometerReading', 'corrected_lactometer_reading', 'decimal', false, ['label' => ['en' => 'Corrected lactometer reading', 'sw' => 'Kipimo cha lactometer kilichosahihishwa']]),
                    self::field('solid', 'solids', 'decimal', false, ['label' => ['en' => 'Solids', 'sw' => 'Yabisi']]),
                    self::field('solidNonFat', 'solids_non_fat', 'decimal', false, ['label' => ['en' => 'Solids non-fat', 'sw' => 'Yabisi bila mafuta']]),
                    self::field('protein', 'protein', 'decimal', false, ['label' => ['en' => 'Protein', 'sw' => 'Protini']]),
                    self::field('totalSolids', 'total_solids', 'decimal', false, ['label' => ['en' => 'Total solids', 'sw' => 'Jumla ya yabisi']]),
                    self::field('colonyFormingUnits', 'colony_forming_units', 'decimal', false, ['label' => ['en' => 'Colony forming units', 'sw' => 'Vijidudu (CFU)']]),
                    self::field('acidity', 'acidity', 'decimal', false, ['label' => ['en' => 'Acidity', 'sw' => 'Asidi']]),
                ],
                'void' => ['status' => 'not-active'],
            ],
            'inseminations' => [
                'family' => 'breeding',
                'events' => [self::event('record_insemination', 'Insemination', 'Uhimilishaji', 'record_only', self::MAMMALS, 'insemination', [], 'female')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('currentHeatTypeId', 'heat_type', 'single_select', true, ['lookup' => 'heat_types', 'label' => ['en' => 'Heat type', 'sw' => 'Aina ya joto']]),
                    self::field('inseminationServiceId', 'insemination_service', 'single_select', true, ['lookup' => 'insemination_services', 'label' => ['en' => 'Service', 'sw' => 'Huduma']]),
                    self::field('semenStrawTypeId', 'semen_straw_type', 'single_select', true, ['lookup' => 'semen_straw_types', 'label' => ['en' => 'Semen straw type', 'sw' => 'Aina ya mbegu']]),
                    self::field('lastHeatDate', 'last_heat_date', 'date', false, ['label' => ['en' => 'Last heat date', 'sw' => 'Joto la mwisho']]),
                    self::field('inseminationDate', 'insemination_date', 'date', false, ['label' => ['en' => 'Insemination date', 'sw' => 'Tarehe ya uhimilishaji']]),
                    self::field('bullCode', 'bull_code', 'text', false, ['label' => ['en' => 'Bull code', 'sw' => 'Namba ya dume']]),
                    self::field('bullBreed', 'bull_breed', 'text', false, ['label' => ['en' => 'Bull breed', 'sw' => 'Aina ya dume']]),
                    self::field('semenProductionDate', 'semen_production_date', 'date', false, ['label' => ['en' => 'Semen production date', 'sw' => 'Tarehe ya mbegu kuzalishwa']]),
                    self::field('productionCountry', 'production_country', 'text', false, ['label' => ['en' => 'Production country', 'sw' => 'Nchi ya uzalishaji']]),
                    self::field('semenBatchNumber', 'semen_batch_number', 'text', false, ['label' => ['en' => 'Semen batch number', 'sw' => 'Namba ya kundi la mbegu']]),
                    self::field('internationalId', 'international_id', 'text', false, ['label' => ['en' => 'International ID', 'sw' => 'Namba ya kimataifa']]),
                    self::field('aiCode', 'ai_code', 'text', false, ['label' => ['en' => 'AI code', 'sw' => 'Namba ya AI']]),
                    self::field('manufacturerName', 'manufacturer_name', 'text', false, ['label' => ['en' => 'Manufacturer', 'sw' => 'Mtengenezaji']]),
                    self::field('semenSupplier', 'semen_supplier', 'text', false, ['label' => ['en' => 'Semen supplier', 'sw' => 'Msambazaji wa mbegu']]),
                ],
            ],
            'pregnancies' => [
                'family' => 'breeding',
                'events' => [self::event('record_pregnancy_test', 'Pregnancy test', 'Kipimo cha mimba', 'record_only', self::MAMMALS, 'pregnancy', [], 'female')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('testResultId', 'test_result', 'single_select', true, ['lookup' => 'test_results', 'label' => ['en' => 'Test result', 'sw' => 'Matokeo ya kipimo']]),
                    self::field('noOfMonths', 'months_pregnant', 'integer', false, ['label' => ['en' => 'Months pregnant', 'sw' => 'Miezi ya mimba']]),
                    self::field('testDate', 'test_date', 'date', false, ['label' => ['en' => 'Test date', 'sw' => 'Tarehe ya kipimo']]),
                    self::field('remarks', 'remarks', 'multiline_text', false),
                ],
                'void' => ['status' => 'not-active'],
            ],
            'dryoffs' => [
                'family' => 'breeding',
                'events' => [self::event('record_dryoff', 'Dry-off', 'Kukausha', 'record_only', self::RUMINANTS, 'dryoff', [], 'female')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('startDate', 'dry_off_start', 'date', true, ['label' => ['en' => 'Dry-off start', 'sw' => 'Mwanzo wa kukausha']]),
                    self::field('endDate', 'dry_off_end', 'date', false, ['label' => ['en' => 'Dry-off end', 'sw' => 'Mwisho wa kukausha']]),
                    self::field('reason', 'reason', 'text', false, ['label' => ['en' => 'Reason', 'sw' => 'Sababu']]),
                    self::field('remarks', 'remarks', 'multiline_text', false),
                ],
            ],
            'weight_changes' => [
                'family' => 'growth',
                'events' => [self::event('record_weight', 'Weight change', 'Badiliko la uzito', 'update_weight', self::LEGACY_SPECIES, 'weightChange')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('newWeight', 'new_weight', 'measurement', true, ['role' => 'weight', 'unit' => 'kg', 'label' => ['en' => 'New weight', 'sw' => 'Uzito mpya']]),
                    self::field('oldWeight', 'old_weight', 'measurement', false, ['unit' => 'kg', 'label' => ['en' => 'Previous weight', 'sw' => 'Uzito wa awali']]),
                    self::field('remarks', 'remarks', 'multiline_text', false),
                ],
                'derived' => ['oldWeight' => ['previous_weight' => true]],
            ],
            'feedings' => [
                'family' => 'growth',
                'events' => [self::event('record_feeding', 'Feeding', 'Ulishaji', 'record_only', self::LEGACY_SPECIES, 'feeding')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('feedingTypeId', 'feeding_type', 'single_select', true, ['lookup' => 'feeding_types', 'label' => ['en' => 'Feed type', 'sw' => 'Aina ya chakula']]),
                    self::field('amount', 'amount', 'measurement', true, ['unit' => 'kg', 'label' => ['en' => 'Amount (kg)', 'sw' => 'Kiasi (kg)']]),
                    self::field('nextFeedingTime', 'next_feeding_time', 'date_time', true, ['label' => ['en' => 'Next feeding time', 'sw' => 'Muda wa kulisha tena']]),
                    self::field('remarks', 'remarks', 'multiline_text', false),
                ],
            ],
            'iron_injections' => [
                'family' => 'growth',
                'events' => [self::event('record_iron_injection', 'Iron injection', 'Sindano ya madini chuma', 'record_treatment', ['pig'], 'ironInjection')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('medicineId', 'medicine', 'single_select', false, ['lookup' => 'medicines', 'role' => 'product', 'label' => ['en' => 'Medicine', 'sw' => 'Dawa']]),
                    self::field('dosage', 'dosage', 'text', false, ['label' => ['en' => 'Dosage', 'sw' => 'Kipimo']]),
                    self::field('notes', 'notes', 'multiline_text', false, ['label' => ['en' => 'Notes', 'sw' => 'Maelezo']]),
                ],
            ],
            'teeth_clippings' => [
                'family' => 'growth',
                'events' => [self::event('record_teeth_clipping', 'Teeth clipping', 'Kukata meno', 'record_only', ['pig'], 'teethClipping')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('method', 'method', 'text', false, ['label' => ['en' => 'Method', 'sw' => 'Njia']]),
                    self::field('notes', 'notes', 'multiline_text', false, ['label' => ['en' => 'Notes', 'sw' => 'Maelezo']]),
                ],
            ],
            'tail_dockings' => [
                'family' => 'growth',
                'events' => [self::event('record_tail_docking', 'Tail docking', 'Kukata mkia', 'record_only', ['pig'], 'tailDocking')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('method', 'method', 'text', false, ['label' => ['en' => 'Method', 'sw' => 'Njia']]),
                    self::field('notes', 'notes', 'multiline_text', false, ['label' => ['en' => 'Notes', 'sw' => 'Maelezo']]),
                ],
            ],
            'livestock_markings' => [
                'family' => 'growth',
                'events' => [self::event('record_marking', 'Marking', 'Kuweka alama', 'record_only', self::LEGACY_SPECIES, 'livestockMarking')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('markingType', 'marking_type', 'text', true, ['label' => ['en' => 'Marking type', 'sw' => 'Aina ya alama']]),
                    self::field('description', 'description', 'multiline_text', false, ['label' => ['en' => 'Description', 'sw' => 'Maelezo ya alama']]),
                    self::field('notes', 'notes', 'multiline_text', false, ['label' => ['en' => 'Notes', 'sw' => 'Maelezo']]),
                ],
            ],
            'stage_changes' => [
                'family' => 'growth',
                'events' => [self::event('record_stage_change', 'Stage change', 'Badiliko la hatua', 'transition_stage', self::MAMMALS, 'stageChange')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('toStageId', 'new_stage', 'single_select', true, ['lookup' => 'stages', 'role' => 'stage_code', 'label' => ['en' => 'New stage', 'sw' => 'Hatua mpya']]),
                    self::field('fromStageId', 'previous_stage', 'single_select', false, ['lookup' => 'stages', 'label' => ['en' => 'Previous stage', 'sw' => 'Hatua ya awali']]),
                    self::field('notes', 'notes', 'multiline_text', false, ['label' => ['en' => 'Notes', 'sw' => 'Maelezo']]),
                ],
                'livestock' => ['stageId' => ['column' => 'toStageId']],
            ],
            'prepuce_conditions' => [
                'family' => 'health',
                'events' => [self::event('record_prepuce_condition', 'Prepuce condition', 'Hali ya govi', 'record_treatment', ['cattle', 'pig'], 'prepuceCondition', [], 'male')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('conditionTypeId', 'condition_type', 'single_select', false, ['lookup' => 'prepuce_condition_types', 'label' => ['en' => 'Condition', 'sw' => 'Hali']]),
                    self::field('severityId', 'severity', 'single_select', false, ['lookup' => 'prepuce_severities', 'label' => ['en' => 'Severity', 'sw' => 'Ukali']]),
                    self::field('causeRiskId', 'cause_risk', 'single_select', false, ['lookup' => 'prepuce_cause_risks', 'label' => ['en' => 'Cause or risk', 'sw' => 'Chanzo au hatari']]),
                    self::field('clinicalSignIds', 'clinical_signs', 'multi_select', false, ['lookup' => 'prepuce_clinical_signs', 'label' => ['en' => 'Clinical signs', 'sw' => 'Dalili']]),
                    self::field('treatmentGivenIds', 'treatments_given', 'multi_select', false, ['lookup' => 'prepuce_treatments_given', 'label' => ['en' => 'Treatment given', 'sw' => 'Matibabu yaliyotolewa']]),
                    self::field('medicineId', 'medicine', 'single_select', false, ['lookup' => 'medicines', 'role' => 'product', 'label' => ['en' => 'Medicine', 'sw' => 'Dawa']]),
                    self::field('administrationRouteId', 'administration_route', 'single_select', false, ['lookup' => 'administration_routes', 'label' => ['en' => 'Administration route', 'sw' => 'Njia ya kutoa dawa']]),
                    self::field('quantity', 'quantity', 'text', false, ['label' => ['en' => 'Quantity', 'sw' => 'Kiasi']]),
                    self::field('dose', 'dose_text', 'text', false, ['label' => ['en' => 'Dose', 'sw' => 'Kipimo']]),
                    self::field('breedingStatusId', 'breeding_status', 'single_select', false, ['lookup' => 'prepuce_breeding_statuses', 'label' => ['en' => 'Breeding status', 'sw' => 'Hali ya kuzalisha']]),
                    self::field('healingStatusId', 'healing_status', 'single_select', false, ['lookup' => 'prepuce_healing_statuses', 'label' => ['en' => 'Healing status', 'sw' => 'Hali ya kupona']]),
                    self::field('followUpDate', 'follow_up_on', 'date_time', false, ['label' => ['en' => 'Follow-up', 'sw' => 'Ufuatiliaji']]),
                    self::field('vetId', 'vet', 'text', false, ['label' => ['en' => 'Veterinarian', 'sw' => 'Daktari wa mifugo']]),
                    self::field('extensionOfficerId', 'extension_officer', 'text', false, ['label' => ['en' => 'Extension officer', 'sw' => 'Afisa ugani']]),
                    self::field('notes', 'notes', 'multiline_text', false, ['label' => ['en' => 'Notes', 'sw' => 'Maelezo']]),
                ],
            ],
            'transfers' => [
                'family' => 'movement',
                'events' => [self::event('record_transfer', 'Transfer', 'Uhamisho', 'record_only', self::LEGACY_SPECIES, 'transfer')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('toFarmUuid', 'destination_farm', 'text', true, ['label' => ['en' => 'Destination farm', 'sw' => 'Shamba unakohamia']]),
                    self::field('transporterId', 'transporter', 'text', false, ['label' => ['en' => 'Transporter', 'sw' => 'Msafirishaji']]),
                    self::field('reason', 'reason', 'text', false, ['label' => ['en' => 'Reason', 'sw' => 'Sababu']]),
                    self::field('price', 'price', 'decimal', false, ['label' => ['en' => 'Price', 'sw' => 'Bei']]),
                    self::field('transferDate', 'transfer_date', 'date', false, ['label' => ['en' => 'Transfer date', 'sw' => 'Tarehe ya uhamisho']]),
                    self::field('remarks', 'remarks', 'multiline_text', false),
                ],
                'void' => ['status' => 'cancelled'],
            ],
            'disposals' => [
                'family' => 'movement',
                'events' => [self::event('record_disposal', 'Disposal', 'Kuondoa mnyama', 'record_only', self::LEGACY_SPECIES, 'disposal')],
                'fields' => [
                    self::field('eventDate', 'event_date', 'date_time', false, ['label' => ['en' => 'Event date', 'sw' => 'Tarehe ya tukio']]),
                    self::field('disposalTypeId', 'disposal_type', 'single_select', true, ['lookup' => 'disposal_types', 'label' => ['en' => 'Disposal type', 'sw' => 'Aina ya kuondoa']]),
                    self::field('reasons', 'reasons', 'multiline_text', true, ['label' => ['en' => 'Reasons', 'sw' => 'Sababu']]),
                    self::field('saleWeight', 'sale_weight', 'measurement', false, ['unit' => 'kg', 'label' => ['en' => 'Sale weight', 'sw' => 'Uzito wa kuuza']]),
                    self::field('salePrice', 'sale_price', 'decimal', false, ['label' => ['en' => 'Sale price', 'sw' => 'Bei ya kuuza']]),
                    self::field('buyerName', 'buyer_name', 'text', false, ['label' => ['en' => 'Buyer', 'sw' => 'Mnunuzi']]),
                    self::field('remarks', 'remarks', 'multiline_text', true),
                ],
                'void' => ['status' => 'failed'],
                'livestock' => ['status' => ['value' => 'notActive']],
            ],
        ];
    }

    public static function table(string $table): ?array
    {
        return self::tables()[$table] ?? null;
    }

    /** @return array<int, string> */
    public static function tablesFor(string $family): array
    {
        return array_keys(array_filter(self::tables(), fn (array $spec) => $spec['family'] === $family));
    }

    /** The legacy table and event spec that own a configured event code. */
    public static function forEventCode(string $code): ?array
    {
        foreach (self::tables() as $table => $spec) {
            foreach ($spec['events'] as $event) {
                if ($event['code'] === $code) {
                    return [$table, $spec, $event];
                }
            }
        }

        return null;
    }

    /** The configured event that a legacy row of this species belongs to. */
    public static function eventFor(array $spec, string $speciesCode, array $row): ?array
    {
        foreach ($spec['events'] as $event) {
            if (! in_array($speciesCode, $event['species'], true)) {
                continue;
            }
            foreach ($event['match'] as $column => $value) {
                if (($row[$column] ?? null) !== $value) {
                    continue 2;
                }
            }

            return $event;
        }

        return null;
    }

    /** @return array<int, string> legacy reference tables used by any field */
    public static function referenceTables(): array
    {
        return collect(self::tables())->flatMap(fn (array $spec) => array_filter(array_column($spec['fields'], 'lookup')))->unique()->values()->all();
    }

    private static function event(string $code, string $name, string $sw, string $behavior, array $species, string $legacyLogType, array $match = [], ?string $sex = null): array
    {
        return compact('code', 'name', 'sw', 'behavior', 'species', 'legacyLogType', 'match', 'sex');
    }

    private static function field(?string $column, string $key, string $component, bool $required, array $extra = []): array
    {
        return ['column' => $column, 'key' => $key, 'component' => $component, 'required' => $required] + $extra + [
            'label' => ['en' => str($key)->replace('_', ' ')->ucfirst()->toString(), 'sw' => $key === 'remarks' ? 'Maoni' : null],
        ];
    }

    private static function options(array $values): array
    {
        return collect($values)->map(fn (array $label, string $value) => ['value' => $value, 'label' => ['en' => $label[0], 'sw' => $label[1]]])->values()->all();
    }
}
