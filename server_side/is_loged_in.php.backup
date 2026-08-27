<?php
header('Content-Type: text/plain');


error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "ERROR:not POST method";
    exit;
}
function hash_string_with_salt($string,$salt) {
    // Generate a secure random salt (e.g., 16 bytes for a 32-character hex salt)
    $salt_length = 16;

    // Concatenate the salt and the string before hashing
    $salted_string = $salt . $string;

    // Generate the SHA512 hash
    $hash = hash('sha512', $salted_string);

    return $hash;
}

// ---- POST PARAMETERS (same as register.php) ----
$nick = $_POST['nick'] ?? '';
$email    = $_POST['email']    ?? '';
$password = $_POST['pw'] ?? '';

// Basic validation
if ($email === '' || $password === '') {
    echo "ERROR:basic validation erro";
    exit;
}

try {
    // ---- OPEN SQLITE DATABASE ----
    // ⚠️ MUST be the SAME file used in register.php
    $db = new SQLite3(__DIR__ . '/userdb.sqlite3');

    // ---- FETCH USER ----
    // Email + nick match (safer than email alone)
    $stmt = $db->prepare(
        'SELECT nick, pw, salt FROM users
         WHERE email = :email AND nick = :nick
         LIMIT 1'
    );

    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
    $stmt->bindValue(':nick', $nick, SQLITE3_TEXT);

    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    // User not found
    if (!$row) {
        echo "ERROR:User not found";
        exit;
    }

    /*
    // Verify password hash
    if (!password_verify($password, $row['pw'])) {
        echo "ERROR:invalid password";
        exit;
    }

    // ---- SUCCESS ----
    echo "OK";
    test if is logged in

    var response = await CURL('https://amjp.psy-k.org/JPLY_BBS/server_side/is_loged_in.php', {
    data: 'nick=test&pw=test&email=test'
    });
    MORE(response);

    //invalid user
    var response = await CURL('https://amjp.psy-k.org/JPLY_BBS/server_side/is_loged_in.php', {
    data: 'nick=test1&pw=test&email=test'
    });
    MORE(response);

    //invalid password
    var response = await CURL('https://amjp.psy-k.org/JPLY_BBS/server_side/is_loged_in.php', {
    data: 'nick=test&pw=test2&email=test'
    });
    MORE(response);

*/
    // ---- RECREATE HASH ----
    $computedHash=hash_string_with_salt($password,$row['salt']);
    //$computedHash = hash('sha512', $password. $row['salt'] );

    if(!hash_equals($row['pw'], $computedHash)) {
        echo "ERROR:pw";
        exit;
    } else {
        echo "OK" ;
        exit;
    }
} catch (Exception $e) {
    echo "ERROR:".$e;
}
