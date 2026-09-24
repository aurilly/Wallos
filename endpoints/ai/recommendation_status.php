<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/ai_recommendation_jobs.php';

session_write_close();
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    ai_jobs_expire($db);
    echo json_encode(ai_job_response(ai_job_status($db, $userId), $i18n));
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
}
