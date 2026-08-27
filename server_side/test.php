<?php
// test.php - Simple PHP script to test your JavaScript CURL POST function
// Run with: php -S localhost:8000
// Then call from JS: await CURL('http://localhost:8000/test.php', { data: 'param1=value1&param2=value2' });

header('Content-Type: text/plain');

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

    // Optional: Show raw input if you want to debug exactly what was received
    // echo "\nRaw input:\n";
    // echo file_get_contents('php://input');

} else {
    // Reject non-POST requests
    http_response_code(405); // Method Not Allowed
    echo "Error: This endpoint only accepts POST requests.\n";
}
?>