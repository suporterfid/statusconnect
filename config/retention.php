<?php

return [
    'check_results_days' => (int) env('RETENTION_CHECK_RESULTS_DAYS', 7),
    'delete_chunk_size' => (int) env('RETENTION_DELETE_CHUNK_SIZE', 500),
];
