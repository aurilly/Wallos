<?php
// Run as a long-lived CLI worker, or use --once from an external scheduler.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
set_time_limit(0);
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/ai_recommendation_jobs.php';
require_once __DIR__ . '/../../includes/ai_recommendations.php';

$once = in_array('--once', $argv, true);
do {
    $job = null;
    try {
        $job = ai_job_claim($db);
        if ($job) {
            $userLanguage = ai_job_query($db, 'SELECT language FROM user WHERE id = :id',
                [':id' => (int) $job['user_id']])->fetchArray(SQLITE3_ASSOC);
            $lang = resolve_language($userLanguage['language'] ?? 'en');
            require __DIR__ . '/../../includes/i18n/' . $lang . '.php';
            $result = ai_generate_recommendations($db, $job['user_id'], $i18n);
            ai_job_finish($db, $job, $result);
        }
    } catch (Throwable $error) {
        // Do not log provider responses, notes, or credentials.
        error_log('[Wallos AI] Background recommendation job failed');
        if ($job) {
            try {
                ai_job_finish($db, $job, ['success' => false, 'message' => 'error']);
            } catch (Throwable $ignored) {
                // The lease expires if the database cannot be written.
            }
        }
        if (!$once) sleep(2);
    }
    if (!$job && !$once) sleep(2);
} while (!$once);
