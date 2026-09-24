<?php

require_once WALLOS_ROOT . '/includes/ai_subscription_notes.php';

wallos_test('AI notes are omitted unless the subscription explicitly opts in', function () {
    $privateNote = 'Sensitive account details';
    assert_same([], ai_subscription_notes(['notes' => $privateNote]), 'missing consent excludes notes');
    foreach ([0, '0', null, false, '', 'false', 2] as $consent) {
        $data = ai_subscription_notes(['notes' => $privateNote, 'ai_share_notes' => $consent]);
        assert_same([], $data, 'disabled or invalid consent excludes the notes field entirely');
        assert_not_contains($privateNote, json_encode($data), 'private text never enters the JSON');
    }

    foreach ([1, '1'] as $consent) {
        $notes = "iPhone backups only\n**Family** plan: \"shared\"";
        $data = ai_subscription_notes(['notes' => $notes, 'ai_share_notes' => $consent]);
        assert_same(['notes' => $notes], json_decode(json_encode($data), true), 'opted-in Markdown survives JSON encoding');
    }
});

wallos_test('AI notes consent can be revoked without deleting the stored notes', function () {
    $subscription = ['notes' => 'Computer backups', 'ai_share_notes' => 1];
    assert_same(['notes' => 'Computer backups'], ai_subscription_notes($subscription), 'enabled notes are sent');
    $subscription['ai_share_notes'] = 0;
    assert_same([], ai_subscription_notes($subscription), 'revoking consent removes notes from future payloads');
    assert_same('Computer backups', $subscription['notes'], 'the original notes remain intact');
});

wallos_test('AI notes migration defaults existing and new subscriptions to private', function () {
    $db = new SQLite3(':memory:');
    $db->enableExceptions(true);
    $db->exec('CREATE TABLE subscriptions (id INTEGER PRIMARY KEY, notes TEXT)');
    $db->exec("INSERT INTO subscriptions (id, notes) VALUES (1, 'Existing private notes')");

    require WALLOS_ROOT . '/migrations/000060.php';
    assert_same(0, $db->querySingle('SELECT ai_share_notes FROM subscriptions WHERE id = 1'), 'existing notes are private');
    $db->exec("INSERT INTO subscriptions (id, notes) VALUES (2, 'New private notes')");
    assert_same(0, $db->querySingle('SELECT ai_share_notes FROM subscriptions WHERE id = 2'), 'new notes are private');

    $db->exec('UPDATE subscriptions SET ai_share_notes = 1 WHERE id = 1');
    require WALLOS_ROOT . '/migrations/000060.php';
    assert_same(1, $db->querySingle('SELECT ai_share_notes FROM subscriptions WHERE id = 1'), 'rerunning migration preserves consent');
    assert_same(0, $db->querySingle('SELECT ai_share_notes FROM subscriptions WHERE id = 2'), 'consent stays scoped to one subscription');
    $db->close();
});

wallos_test('manual and scheduled AI requests both use the notes consent filter', function () {
    foreach (['endpoints/ai/generate_recommendations.php', 'endpoints/cronjobs/generaterecommendations.php'] as $file) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $file);
        assert_contains('includes/ai_subscription_notes.php', $source, $file . ' loads the consent filter');
        assert_contains('...ai_subscription_notes($row)', $source, $file . ' filters notes before constructing the payload');
        assert_not_contains("\$row['notes']", $source, $file . ' does not read notes outside the consent filter');
    }
});
