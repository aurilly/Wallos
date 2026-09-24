<?php

require_once WALLOS_ROOT . '/includes/ai_recommendation_jobs.php';
require_once WALLOS_ROOT . '/includes/ai_recommendations.php';
require_once WALLOS_ROOT . '/includes/i18n/getlang.php';

function ai_jobs_test_db()
{
    $db = wallos_test_open_database();
    $db->enableExceptions(true);
    wallos_test_create_user($db, 1, 'alice');
    wallos_test_create_user($db, 2, 'bob');
    $db->exec("INSERT INTO ai_settings (user_id, type, enabled, model) VALUES (1, 'ollama', 1, 'test')");
    $db->exec("INSERT INTO ai_recommendations (user_id, type, title, description, savings)
        VALUES (1, 'subscription', 'Old recommendation', 'Keep until success', '')");
    return $db;
}

function ai_jobs_test_result()
{
    return ['success' => true, 'recommendations' => [
        ['title' => 'New recommendation', 'description' => 'Different purpose', 'savings' => '5/month'],
    ]];
}

wallos_test('AI job queue deduplicates requests and claims each job only once', function () {
    $db = ai_jobs_test_db();
    $job = ai_job_enqueue($db, 1);
    assert_same('queued', $job['status'], 'enqueue returns immediately with queued state');
    assert_same($job['id'], ai_job_enqueue($db, 1)['id'], 'double clicks reuse the queued job');
    assert_same($job['id'], ai_job_claim($db)['id'], 'worker claims the job');
    assert_same(null, ai_job_claim($db), 'a second worker cannot claim it');
    assert_same($job['id'], ai_job_enqueue($db, 1)['id'], 'retries reuse the running job');
    assert_same(null, ai_job_status($db, 2), 'another user cannot poll this job');
    assert_same(null, ai_job_enqueue($db, 999), 'a deleted account cannot enqueue work');
    $db->close();
});

wallos_test('AI job claims are exclusive across database connections', function () {
    $path = wallos_test_database();
    $first = new SQLite3($path);
    $second = new SQLite3($path);
    wallos_test_create_user($first, 1, 'alice');
    $job = ai_job_enqueue($first, 1);
    assert_same($job['id'], ai_job_claim($second)['id'], 'another connection can take queued work');
    assert_same(null, ai_job_claim($first), 'the original connection cannot take running work');
    assert_same($job['id'], ai_job_enqueue($first, 1)['id'], 'a separate request observes the same active job');
    $first->close();
    $second->close();
});

wallos_test('AI job success replaces results atomically and a failure preserves them', function () {
    $db = ai_jobs_test_db();
    ai_job_enqueue($db, 1);
    $job = ai_job_claim($db);
    ai_job_finish($db, $job, ['success' => false, 'message' => 'Provider unavailable']);
    assert_same('failed', ai_job_status($db, 1)['status'], 'failure becomes pollable');
    assert_same('Old recommendation', $db->querySingle('SELECT title FROM ai_recommendations WHERE user_id = 1'), 'failure preserves existing results');

    $retry = ai_job_enqueue($db, 1);
    assert_true($retry['id'] !== $job['id'], 'retry has a new token');
    $retry = ai_job_claim($db);
    assert_true(ai_job_finish($db, $retry, ai_jobs_test_result()), 'success is saved');
    assert_same('completed', ai_job_status($db, 1)['status'], 'completed state is pollable');
    assert_same('New recommendation', $db->querySingle('SELECT title FROM ai_recommendations WHERE user_id = 1'), 'results are replaced');
    assert_true($db->querySingle('SELECT last_successful_run FROM ai_settings WHERE user_id = 1') !== null, 'successful runs update their timestamp');
    $db->close();
});

wallos_test('AI job result write failure rolls back deleted recommendations', function () {
    $db = ai_jobs_test_db();
    ai_job_enqueue($db, 1);
    $job = ai_job_claim($db);
    $db->exec("CREATE TRIGGER reject_ai_result BEFORE INSERT ON ai_recommendations BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    $failed = false;
    try {
        ai_job_finish($db, $job, ai_jobs_test_result());
    } catch (Throwable $error) {
        $failed = true;
    }
    assert_true($failed, 'the write failed');
    assert_same('Old recommendation', $db->querySingle('SELECT title FROM ai_recommendations WHERE user_id = 1'), 'the delete was rolled back');
    assert_same('running', ai_job_status($db, 1)['status'], 'completion was not recorded');
    $db->close();
});

wallos_test('expired and deleted AI jobs cannot publish late results', function () {
    $db = ai_jobs_test_db();
    ai_job_enqueue($db, 1);
    $old = ai_job_claim($db);
    $db->exec('UPDATE ai_recommendation_jobs SET expires_at = 0');
    ai_jobs_expire($db);
    assert_same('failed', ai_job_status($db, 1)['status'], 'abandoned jobs become retryable');
    ai_job_enqueue($db, 1);
    $new = ai_job_claim($db);
    assert_same(false, ai_job_finish($db, $old, ai_jobs_test_result()), 'old worker cannot overwrite newer work');
    $db->exec('DELETE FROM ai_recommendation_jobs WHERE user_id = 1');
    assert_same(false, ai_job_finish($db, $new, ai_jobs_test_result()), 'deleted account work cannot publish');
    assert_same('Old recommendation', $db->querySingle('SELECT title FROM ai_recommendations WHERE user_id = 1'), 'old results are untouched');
    $db->close();
});

wallos_test('background AI generation preserves note consent and normalizes provider results', function () {
    $db = ai_jobs_test_db();
    require WALLOS_ROOT . '/includes/i18n/en.php';
    $db->exec("INSERT INTO subscriptions (user_id, name, price, currency_id, next_payment, cycle, frequency, inactive, notes, ai_share_notes)
        VALUES (1, 'Phone backups', 5, 1, '2026-10-01', 3, 1, 0, 'Shared purpose', 1),
               (1, 'Computer backups', 5, 1, '2026-10-01', 3, 1, 0, 'Private secret', 0),
               (2, 'Other user', 5, 1, '2026-10-01', 3, 1, 0, 'Other user secret', 1)");
    $complete = function ($settings, $prompt, $db, $i18n, $userId, $timeout) {
        assert_contains('Shared purpose', $prompt, 'approved notes reach the provider');
        assert_not_contains('Private secret', $prompt, 'private notes never reach the provider');
        assert_not_contains('Other user secret', $prompt, 'another account is excluded');
        assert_same(900, $timeout, 'background requests allow long reasoning');
        return ['success' => true, 'content' => '{"recommendations":[{"title":" Keep both ","description":" Distinct uses ","savings":null},{"title":[],"description":"invalid"}]}'];
    };
    $result = ai_generate_recommendations($db, 1, $i18n, $complete);
    assert_same([['title' => 'Keep both', 'description' => 'Distinct uses', 'savings' => '']], $result['recommendations'], 'only valid normalized results survive');
    // A worker serves multiple users/jobs in one process; repeated calls must work.
    assert_same($result, ai_generate_recommendations($db, 1, $i18n, $complete), 'generation can run twice in a worker');
    assert_same('Old recommendation', $db->querySingle('SELECT title FROM ai_recommendations WHERE user_id = 1'), 'generation alone cannot publish without a job token');
    $db->close();
});

wallos_test('AI job migration can be rerun without dropping pending jobs', function () {
    $db = ai_jobs_test_db();
    $job = ai_job_enqueue($db, 1);
    require WALLOS_ROOT . '/migrations/000061.php';
    assert_same($job['id'], ai_job_status($db, 1)['id'], 'rerunning migration retains queued work');
    $db->close();
});

wallos_test('the real CLI AI worker processes queued work without an HTTP session', function () {
    $root = WALLOS_TEST_TMP . '/ai-worker-' . uniqid();
    foreach (['', '/db', '/includes', '/endpoints', '/endpoints/cronjobs'] as $directory) {
        mkdir($root . $directory, 0700);
    }
    copy(wallos_test_database(), $root . '/db/wallos.db');
    foreach (['connect_endpoint_crontabs.php', 'ai_recommendation_jobs.php', 'ai_recommendations.php',
        'ai_client.php', 'ssrf_helper.php', 'ai_subscription_notes.php'] as $file) {
        copy(WALLOS_ROOT . '/includes/' . $file, $root . '/includes/' . $file);
    }
    symlink(WALLOS_ROOT . '/includes/i18n', $root . '/includes/i18n');
    $worker = $root . '/endpoints/cronjobs/processrecommendations.php';
    copy(WALLOS_ROOT . '/endpoints/cronjobs/processrecommendations.php', $worker);
    $db = new SQLite3($root . '/db/wallos.db');
    wallos_test_create_user($db, 1, 'alice');
    ai_job_enqueue($db, 1);
    // Missing AI settings should become a pollable failure, without a network call.
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' --once 2>&1', $output, $exitCode);
    assert_same(0, $exitCode, 'worker starts successfully: ' . implode("\n", $output));
    assert_same('failed', ai_job_status($db, 1)['status'], 'worker records a terminal state');
    assert_true(ai_job_status($db, 1)['message'] !== '', 'the poller receives an error message');
    $db->close();
});
