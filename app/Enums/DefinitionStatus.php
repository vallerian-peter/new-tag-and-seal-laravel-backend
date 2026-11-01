<?php

namespace App\Enums;

enum DefinitionStatus: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case RETIRED = 'retired';
}
