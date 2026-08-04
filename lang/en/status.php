<?php

return [
    'components' => 'Components',
    'history' => '90-day history',
    'history_for' => '90-day history for :component',
    'incidents' => 'Incident history',
    'incident_started' => 'Started :at',
    'incident_resolved' => 'Resolved :at',
    'incident_update' => ':status update at :at',
    'uptime_for' => 'Uptime for :period',
    'uptime_formula' => 'Uptime excludes scheduled maintenance and periods without data. Degraded time is reported separately and is not downtime.',
    'states' => [
        'operational' => 'Operational',
        'degraded' => 'Degraded performance',
        'major_outage' => 'Major outage',
        'maintenance' => 'Under maintenance',
        'no_data' => 'No data',
    ],
];
