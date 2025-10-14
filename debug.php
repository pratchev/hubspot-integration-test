<?php
// Debug script to test HubSpot API connection

// Load environment variables from .env file if it exists
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        return;
    }
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Skip comments
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Load .env file
loadEnv(__DIR__ . '/.env');

$token = $_ENV['HUBSPOT_TOKEN'] ?? getenv('HUBSPOT_TOKEN') ?? 'NOT_SET';

echo "Token status: " . ($token === 'NOT_SET' ? 'NOT SET' : 'SET (' . strlen($token) . ' chars)') . "\n";
echo "Token starts with: " . substr($token, 0, 10) . "...\n";

// Test API call
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://api.hubapi.com/marketing/v3/forms?limit=5',
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
if ($error) {
    echo "cURL Error: $error\n";
}
echo "Response: " . substr($response, 0, 200) . "...\n";
?>