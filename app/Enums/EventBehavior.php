<?php

namespace App\Enums;

/** Must stay in step with the rows seeded into behavior_definitions. */
enum EventBehavior: string
{
    case RECORD_ONLY = 'record_only';
    case RECORD_TREATMENT = 'record_treatment';
    case UPDATE_WEIGHT = 'update_weight';
    case TRANSITION_STAGE = 'transition_stage';
    case CHANGE_LIFECYCLE_STATUS = 'change_lifecycle_status';
    case TRANSFER_SUBJECT = 'transfer_subject';
    case RECORD_BIRTH = 'record_birth';
    case CREATE_OFFSPRING = 'create_offspring';
    case RECORD_EGG_OR_CLUTCH = 'record_egg_or_clutch';
    case RECORD_HATCHING = 'record_hatching';
    case RECORD_SPAWNING = 'record_spawning';
    case RECORD_MORTALITY = 'record_mortality';
    case SPLIT_GROUP = 'split_group';
    case MERGE_GROUPS = 'merge_groups';
    case ADJUST_GROUP_QUANTITY = 'adjust_group_quantity';
    case RECORD_GROUP_CENSUS = 'record_group_census';
    case RECORD_BIOMASS_SAMPLE = 'record_biomass_sample';
    case RECORD_PRODUCTION_YIELD = 'record_production_yield';
    case RECORD_ENVIRONMENT_MEASUREMENT = 'record_environment_measurement';
    case ASSIGN_IDENTIFIER = 'assign_identifier';
    case RETIRE_IDENTIFIER = 'retire_identifier';
    case SCHEDULE_FOLLOWUP = 'schedule_followup';
}
