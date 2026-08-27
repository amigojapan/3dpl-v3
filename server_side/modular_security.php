<?php
declare(strict_types=1);
// Source - https://stackoverflow.com/a
// Posted by Rajesh Patel, modified by community. See post 'Timeline' for change history
// Retrieved 2026-01-02, License - CC BY-SA 4.0

ini_set('display_startup_errors', 1);
ini_set('display_errors', 1);
error_reporting(-1);

// is_loged_in.php
// Modular auth checker for SQLite3 (salted SHA512).
// - Include from other scripts and call is_user_identified()
// - Or POST to this file directly with nickname/password and it returns OK / ERROR:...

/**
 * Compute SHA-512 hash using the same scheme as your DB:
 * hash('sha512', $password . $salt)
 */
function hash_string_with_salt(string $string, string $salt): string {
    return hash('sha512', $salt . $string);
}

/**
 * Verify nickname + password against SQLite DB.
 *
 * @param string      $nickname  User nickname
 * @param string      $password  Plain password provided by user
 * @param string      $dbPath    Path to SQLite DB file
 * @param string      $tableName Table name (default: users)
 * @param string|null $err       Output error text (optional)
 * @return bool
 */
function is_user_identified(
    $nickname,
    $password,
    $err = null
): bool {
    $dbPath = 'userdb.sqlite3';
    $tableName = 'users';

    $err = null;

    $nickname = trim($nickname);
    if ($nickname === '') { $err = 'nickname'; return false; }
    if ($password === '') { $err = 'pw_empty'; return false; }

    if (!is_file($dbPath)) { $err = 'db_missing'; return false; }

    // Open read-only if possible (safer)
    try {
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    } catch (Throwable $e) {
        $err = 'db_open';
        return false;
    }

    try {
        // NOTE: table name cannot be parameterized; whitelist a safe name format
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tableName)) {
            $err = 'bad_table';
            return false;
        }

        // Adjust column names here if yours differ:
        // expected columns: nick, pw, salt
        $sql = "SELECT pw, salt FROM {$tableName} WHERE nick = :nick LIMIT 1";
        $stmt = $db->prepare($sql);
        if (!$stmt) { $err = 'prepare'; return false; }

        $stmt->bindValue(':nick', $nickname, SQLITE3_TEXT);
        $res = $stmt->execute();
        if (!$res) { $err = 'query'; return false; }

        $row = $res->fetchArray(SQLITE3_ASSOC);
        if (!$row) { $err = 'no_user'; return false; }

        if (!isset($row['pw'], $row['salt'])) { $err = 'bad_row'; return false; }

        $computedHash = hash_string_with_salt($password, (string)$row['salt']);

        if (!hash_equals((string)$row['pw'], $computedHash)) {
            $err = 'pw';
            return false;
        }

        return true;
    } catch (Throwable $e) {
        $err = 'exception';
        return false;
    } finally {
        if (isset($db) && $db instanceof SQLite3) {
            $db->close();
        }
    }
}

/* -------------------------
   Optional: act like your old endpoint if POSTed directly
   ------------------------- 
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    header('Content-Type: text/plain');

    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        echo "ERROR:not POST method";
        exit;
    }

    // accept either nickname/password OR nick/pw
    //$nickname = (string)($_POST['nickname'] ?? $_POST['nick'] ?? '');
   // $password = (string)($_POST['password'] ?? $_POST['pw'] ?? '');

    // TODO: set this to your real DB path
    $dbPath = __DIR__ . '/users.sqlite3';   // <-- CHANGE THIS
    $table  = 'users';                      // <-- CHANGE if needed

    $err = null;
    $nickname="test";
    $password="test";

    if (is_user_identified($nickname, $password, $dbPath, $table, $err)) {
        echo "OK";
    } else {
        echo "ERROR1:" . ($err ?? 'unknown');
    }
    exit;
}
*/