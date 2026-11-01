<?php

namespace App\Enums;

enum SexCode: string
{
    case MALE = 'male';
    case FEMALE = 'female';
    case INTERSEX = 'intersex';
    case UNKNOWN = 'unknown';
    case NOT_APPLICABLE = 'not_applicable';
}
