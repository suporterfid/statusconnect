<?php

namespace App\Domain\Monitoring;

enum AssertionOperator: string
{
    case EQUALS = 'equals';
    case IN = 'in';
    case BETWEEN = 'between';
    case LT = 'lt';
    case LTE = 'lte';
    case GTE = 'gte';
    case CONTAINS = 'contains';
    case NOT_CONTAINS = 'not_contains';
    case REGEX = 'regex';
    case EXISTS = 'exists';
}
