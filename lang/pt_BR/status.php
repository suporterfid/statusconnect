<?php

return [
    'components' => 'Componentes',
    'history' => 'Histórico de 90 dias',
    'history_for' => 'Histórico de 90 dias de :component',
    'incidents' => 'Histórico de incidentes',
    'incident_started' => 'Iniciado em :at',
    'incident_resolved' => 'Resolvido em :at',
    'incident_update' => 'Atualização :status em :at',
    'uptime_for' => 'Disponibilidade em :period',
    'uptime_formula' => 'A disponibilidade exclui manutenção programada e períodos sem dados. Tempo degradado é informado separadamente e não é indisponibilidade.',
    'states' => [
        'operational' => 'Operacional',
        'degraded' => 'Desempenho degradado',
        'major_outage' => 'Indisponibilidade grave',
        'maintenance' => 'Em manutenção',
        'no_data' => 'Sem dados',
    ],
];
