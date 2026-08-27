<?php
    /* schema
    CREATE TABLE graffiti (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        graffiti_text TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    */
    // Source - https://stackoverflow.com/a
    // Posted by Rajesh Patel, modified by community. See post 'Timeline' for change history
    // Retrieved 2026-01-02, License - CC BY-SA 4.0

    ini_set('display_startup_errors', 1);
    ini_set('display_errors', 1);
    error_reporting(-1);

    require_once __DIR__ . '/modular_security.php';

    $err = null;
    if (!is_user_identified($_POST["NICK"], $_POST["PASSWORD"], $err)) {
        echo "ERROR:" . $err;
        exit;
    }

    // ✅ user is properly identified — do protected stuff here
    header('Content-Type: application/json; charset=utf-8');

    // Path to your SQLite database
    $dbPath = __DIR__ . '/userdb.sqlite3'; // <-- adjust if needed

    try {
        // Open SQLite database
        $db = new SQLite3($dbPath);
        $db->enableExceptions(true);

        // SQL query
        $sql = "
            SELECT
                u.nick AS username,
                g.graffiti_text,
                g.created_at
            FROM graffiti g
            JOIN users u
                ON g.user_id = u.userID
            ORDER BY g.created_at DESC
            LIMIT 3
        ";

        $result = $db->query($sql);

        $rows = [];

        // Fetch rows
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        // Output JSON
        echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Database error',
            'message' => $e->getMessage()
        ]);
    }
?>