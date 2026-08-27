<?php
// test.php - Simple PHP script to test your JavaScript CURL POST function
// Run with: php -S localhost:8000
// Then call from JS: await CURL('http://localhost:8000/test.php', { data: 'param1=value1&param2=value2' });

header('Content-Type: text/plain');

function hash_string_with_salt($string) {
    // Generate a secure random salt (e.g., 16 bytes for a 32-character hex salt)
    $salt_length = 16;
    try {
        $salt = bin2hex(random_bytes($salt_length));
    } catch (Exception $e) {
        // Handle potential errors in random_bytes generation (rare)
        die("Could not generate secure salt: " . $e->getMessage());
    }

    // Concatenate the salt and the string before hashing
    $salted_string = $salt . $string;

    // Generate the SHA512 hash
    $hash = hash('sha512', $salted_string);

    // Return both the hash and the salt so they can be stored and verified later
    return [
        'hash' => $hash,
        'salt' => $salt
    ];
}


// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "POST request received successfully!\n\n";
    echo "Received data:\n";

    if (!empty($_POST)) {
        foreach ($_POST as $key => $value) {
            // Handle array values (e.g., param[]=a&param[]=b)
            if (is_array($value)) {
                echo "$key: " . implode(', ', $value) . "\n";
            } else {
                echo "$key: $value\n";
            }
        }
    } else {
        echo "No form data was sent.\n";
    }
    /*
    -- Optional: Ensure the database is using UTF-8 (only works before the first table is created)
PRAGMA encoding = "UTF-8";

CREATE TABLE users (
    userID INTEGER PRIMARY KEY AUTOINCREMENT,
    nick TEXT NOT NULL,
    pw TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    salt TEXT NOT NULL UNIQUE
);
    */

    // Example usage:
    $password = $_POST["pw"];
    $result = hash_string_with_salt($password);

    echo "Original String: " . htmlspecialchars($password) . "\n";
    echo "Generated Salt: " . htmlspecialchars($result['salt']) . "\n";
    echo "SHA512 Hash: " . htmlspecialchars($result['hash']) . "\n";

    // Optional: Show raw input if you want to debug exactly what was received
    // echo "\nRaw input:\n";
    // echo file_get_contents('php://input');

} else {
    // Reject non-POST requests
    http_response_code(405); // Method Not Allowed
    echo "Error: This endpoint only accepts POST requests.\n";
}
?>