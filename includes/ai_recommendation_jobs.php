<?php

function ai_job_query($db, $sql, array $values = [])
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare AI job query');
    }
    foreach ($values as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    $result = $stmt->execute();
    if (!$result) {
        throw new RuntimeException('Could not execute AI job query');
    }
    return $result;
}

function ai_jobs_expire($db)
{
    ai_job_query($db, "UPDATE ai_recommendation_jobs SET status = 'failed', message = 'ai_job_expired'
        WHERE status IN ('queued', 'running') AND expires_at <= :now", [':now' => time()]);
}

function ai_job_status($db, $userId)
{
    return ai_job_query($db, 'SELECT * FROM ai_recommendation_jobs WHERE user_id = :user_id',
        [':user_id' => (int) $userId])->fetchArray(SQLITE3_ASSOC) ?: null;
}

function ai_job_enqueue($db, $userId)
{
    $db->enableExceptions(true);
    $db->exec('BEGIN IMMEDIATE');
    try {
        ai_jobs_expire($db);
        $job = ai_job_status($db, $userId);
        if (!$job || !in_array($job['status'], ['queued', 'running'], true)) {
            ai_job_query($db, "INSERT INTO ai_recommendation_jobs (user_id, id, status, created_at, expires_at)
                SELECT :user_id, :id, 'queued', :now, :expiry WHERE EXISTS (SELECT 1 FROM user WHERE id = :user_id)
                ON CONFLICT(user_id) DO UPDATE SET id = excluded.id, status = 'queued',
                    created_at = excluded.created_at, expires_at = excluded.expires_at, message = ''", [
                ':user_id' => (int) $userId, ':id' => bin2hex(random_bytes(16)),
                ':now' => time(), ':expiry' => time() + 3600,
            ]);
            $job = ai_job_status($db, $userId);
        }
        $db->exec('COMMIT');
        return $job;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function ai_job_claim($db)
{
    $db->enableExceptions(true);
    $db->exec('BEGIN IMMEDIATE');
    try {
        ai_jobs_expire($db);
        $job = $db->query("SELECT * FROM ai_recommendation_jobs WHERE status = 'queued' ORDER BY created_at, user_id LIMIT 1")
            ->fetchArray(SQLITE3_ASSOC);
        if ($job) {
            ai_job_query($db, "UPDATE ai_recommendation_jobs SET status = 'running', expires_at = :expiry WHERE id = :id",
                [':expiry' => time() + 1200, ':id' => $job['id']]);
            $job['status'] = 'running';
        }
        $db->exec('COMMIT');
        return $job ?: null;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

// Save the results and terminal status together. An expired/deleted/replaced job
// must never overwrite recommendations, including after an account ID is reused.
function ai_job_finish($db, array $job, array $result)
{
    $db->enableExceptions(true);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $current = ai_job_status($db, $job['user_id']);
        if (!$current || $current['id'] !== $job['id'] || $current['status'] !== 'running'
            || $current['expires_at'] <= time()) {
            $db->exec('ROLLBACK');
            return false;
        }
        if ($result['success']) {
            if (empty($result['recommendations']) || !is_array($result['recommendations'])) {
                throw new RuntimeException('Empty AI recommendations');
            }
            ai_job_query($db, 'DELETE FROM ai_recommendations WHERE user_id = :user_id', [':user_id' => (int) $job['user_id']]);
            foreach ($result['recommendations'] as $rec) {
                ai_job_query($db, "INSERT INTO ai_recommendations (user_id, type, title, description, savings)
                    VALUES (:user_id, 'subscription', :title, :description, :savings)", [
                    ':user_id' => (int) $job['user_id'], ':title' => $rec['title'],
                    ':description' => $rec['description'], ':savings' => $rec['savings'],
                ]);
            }
            ai_job_query($db, 'UPDATE ai_settings SET last_successful_run = CURRENT_TIMESTAMP WHERE user_id = :user_id',
                [':user_id' => (int) $job['user_id']]);
        }
        ai_job_query($db, 'UPDATE ai_recommendation_jobs SET status = :status, message = :message WHERE id = :id', [
            ':id' => $job['id'], ':status' => $result['success'] ? 'completed' : 'failed',
            ':message' => $result['success'] ? '' : ($result['message'] ?? 'error'),
        ]);
        $db->exec('COMMIT');
        return true;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function ai_job_response($job, $i18n)
{
    $status = $job['status'] ?? 'idle';
    $message = match ($status) {
        'queued' => translate('ai_job_queued', $i18n),
        'running' => translate('ai_job_running', $i18n),
        'completed' => translate('success', $i18n),
        'failed' => in_array($job['message'], ['ai_job_expired', 'error'], true)
            ? translate($job['message'], $i18n) : $job['message'],
        default => '',
    };
    return ['success' => true, 'job_id' => $job['id'] ?? null, 'status' => $status, 'message' => $message];
}
