<?php

namespace App\Domain\Monitoring;

enum AssertionType: string
{
    case STATUS_CODE = 'status_code';
    case LATENCY_MS = 'latency_ms';
    case BODY_CONTAINS = 'body_contains';
    case BODY_MATCHES = 'body_matches';
    case HEADER = 'header';
    case JSON_PATH = 'json_path';
    case TLS_EXPIRES_IN_DAYS = 'tls_expires_in_days';
}
