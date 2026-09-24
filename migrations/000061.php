<?php

// One current job per account, with an opaque token to fence late workers.
$db->exec("CREATE TABLE IF NOT EXISTS ai_recommendation_jobs (
    user_id INTEGER PRIMARY KEY,
    id TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    message TEXT NOT NULL DEFAULT '',
    FOREIGN KEY (user_id) REFERENCES user(id)
)");
