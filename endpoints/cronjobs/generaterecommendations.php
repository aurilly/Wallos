<?php
require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/ai_recommendation_jobs.php';

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$runType = PHP_SAPI === 'cli' ? ($argv[1] ?? 'weekly') : ($_GET['run'] ?? 'weekly');
if (!in_array($runType, ['weekly', 'monthly'], true)) $runType = 'weekly';

$stmt = $db->prepare("SELECT DISTINCT user_id FROM ai_settings WHERE enabled = 1 AND model != '' AND run_schedule = :schedule");
$stmt->bindValue(':schedule', $runType, SQLITE3_TEXT);
$result = $stmt->execute();
$users = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) $users[] = $row['user_id'];
$stmt->close();

$queued = 0;
$failures = [];
foreach ($users as $id) {
    try {
        if (ai_job_enqueue($db, $id)) $queued++;
    } catch (Throwable $error) {
        $failures[] = ['user_id' => $id, 'reason' => 'could not queue recommendations'];
    }
}
echo json_encode(['success' => !$failures, 'run_type' => $runType, 'queued' => $queued, 'failures' => $failures]);
