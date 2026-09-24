<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/ai_client.php';
require_once '../../includes/ai_recommendation_jobs.php';

session_write_close();
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    $settings = ai_load_settings($db, $userId);
    if (empty($settings['enabled']) || empty($settings['model'])) {
        echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
        exit;
    }
    $job = ai_job_enqueue($db, $userId);
    if (!$job) {
        throw new RuntimeException('Account no longer exists');
    }
    http_response_code(202);
    echo json_encode(ai_job_response($job, $i18n));
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
}
