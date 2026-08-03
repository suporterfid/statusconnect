<?php

return [
    'target_duration_seconds' => (int) env('CHECK_TARGET_DURATION_SECONDS', 45),
    'budget_safety_margin_seconds' => (int) env('CHECK_BUDGET_SAFETY_MARGIN_SECONDS', 5),
    'execution_reserve_seconds' => (int) env('CHECK_EXECUTION_RESERVE_SECONDS', 1),
    'claim_ttl_minutes' => (int) env('CHECK_CLAIM_TTL_MINUTES', 5),
    'stale_claim_recovery_batch_size' => (int) env('CHECK_STALE_CLAIM_RECOVERY_BATCH_SIZE', 50),
];
