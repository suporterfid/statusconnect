<?php

namespace App\Domain\Incidents;

enum IncidentAction: string
{
    case NONE = 'none';
    case OPEN = 'open';
    case RESOLVE = 'resolve';
    case CONFIGURATION_FAULT = 'configuration_fault';
}
