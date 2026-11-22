<?php
/**
 * Class recordings: the Zoom (or any) link and passcode for each Sunday session,
 * kept in the database so they never sit in this public repo. Add and delete go
 * through actions.php (POST, page token, audit, message).
 *
 * The table makes itself on first use, so it needs no change to the store's
 * schema version.
 */

function admin_recordings_ready(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS recordings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        session_date DATE NOT NULL COMMENT 'The Sunday the class ran, IST',
        title VARCHAR(120) NOT NULL DEFAULT '',
        url VARCHAR(600) NOT NULL,
        passcode VARCHAR(80) NOT NULL DEFAULT '',
        note VARCHAR(200) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_date (session_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/** Newest class first. */
function admin_recordings(PDO $pdo): array
{
    admin_recordings_ready($pdo);
    return rows($pdo, 'SELECT * FROM recordings ORDER BY session_date DESC, id DESC');
}

function admin_recording_action(PDO $pdo, array $config, string $action): void
{
    admin_recordings_ready($pdo);

    if ($action === 'recording_add') {
        $date = (string) ($_POST['session_date'] ?? '');
        $url = trim((string) ($_POST['url'] ?? ''));
        $passcode = trim((string) ($_POST['passcode'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, ist());
        if ($day === false || $day->format('Y-m-d') !== $date) {
            admin_flash('bad', 'Pick the date of the class. Nothing was saved.');
            return;
        }
        if (!preg_match('~^https://[^\s<>"]+$~i', $url) || strlen($url) > 600 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            admin_flash('bad', 'The link must start with https:// and have no spaces. Nothing was saved.');
            return;
        }
        if (mb_strlen($passcode) > 80 || mb_strlen($title) > 120 || mb_strlen($note) > 200) {
            admin_flash('bad', 'One of the boxes is too long. Nothing was saved.');
            return;
        }
        if (!admin_code_ok($pdo, $config)) {
            return;
        }
        $pdo->prepare('INSERT INTO recordings (session_date, title, url, passcode, note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$date, $title, $url, $passcode, $note]);
        $id = (int) $pdo->lastInsertId();
        tg_audit($config, 'admin', 'recording_added', $date . ($title !== '' ? ', ' . $title : ''));
        admin_flash('good', 'Saved the recording for ' . $day->format('j M Y') . '.');
        $_POST['back'] = './?view=recordings#r' . $id;
        return;
    }

    if ($action === 'recording_delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $rec = $id > 0 ? row($pdo, 'SELECT session_date, title FROM recordings WHERE id = ?', [$id]) : [];
        if (!$rec) {
            admin_flash('bad', 'That recording is already gone.');
            return;
        }
        if (!admin_code_ok($pdo, $config)) {
            return;
        }
        $pdo->prepare('DELETE FROM recordings WHERE id = ?')->execute([$id]);
        tg_audit($config, 'admin', 'recording_deleted', $rec['session_date'] . ($rec['title'] !== '' ? ', ' . $rec['title'] : ''));
        admin_flash('good', 'Deleted the recording for ' . date('j M Y', strtotime($rec['session_date'])) . '.');
        $_POST['back'] = './?view=recordings#list';
    }
}
