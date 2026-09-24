<?php

// Notes stay private unless sharing is explicitly enabled for a subscription.
$columnQuery = $db->query("SELECT * FROM pragma_table_info('subscriptions') WHERE name='ai_share_notes'");
if ($columnQuery->fetchArray(SQLITE3_ASSOC) === false) {
    $db->exec("ALTER TABLE subscriptions ADD COLUMN ai_share_notes INTEGER NOT NULL DEFAULT 0");
}
