<?php

// Only explicitly opted-in notes may enter a recommendation payload.
function ai_subscription_notes(array $subscription): array
{
    if (!in_array($subscription['ai_share_notes'] ?? 0, [1, '1'], true)) {
        return [];
    }

    return ['notes' => $subscription['notes'] ?? ''];
}
