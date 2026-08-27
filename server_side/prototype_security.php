<?PHP
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
echo "OK:welcome";
?>