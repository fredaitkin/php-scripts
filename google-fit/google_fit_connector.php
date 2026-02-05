<?php

/**
 * Standalone Google Fit API Connector
 * 
 * This script handles OAuth 2.0 authentication and connects to Google Fit API
 * 
 * Usage:
 * 1. Set your credentials in the config section below
 * 2. Run: php google_fit_connector.php auth    (to get authorization)
 * 3. Run: php google_fit_connector.php data    (to fetch fitness data)
 */

// ============================================================================
// CONFIGURATION
// ============================================================================

// Load configuration from file
$configFile = __DIR__ . '/google_fit_config.php';
if (!file_exists($configFile)) {
    echo "Error: Configuration file not found at $configFile\n";
    echo "Copy google_fit_config.example.php to google_fit_config.php and update your credentials.\n";
    exit(1);
}

$config = require $configFile;

// ============================================================================
// MAIN SCRIPT
// ============================================================================

if (!isset($argv[1])) {
    echo "Usage:\n";
    echo "  php google_fit_connector.php auth    - Get authorization code\n";
    echo "  php google_fit_connector.php data    - Fetch fitness data\n";
    exit(1);
}

$command = $argv[1];

if ($command === 'auth') {
    handleAuthorization($config);
} elseif ($command === 'data') {
    fetchFitnessData($config);
} elseif ($command === 'token' && isset($argv[2])) {
    exchangeTokenFromCode($config, $argv[2]);
} else {
    echo "Usage:\n";
    echo "  php google_fit_connector.php auth    - Get authorization code\n";
    echo "  php google_fit_connector.php token CODE - Exchange code for token\n";
    echo "  php google_fit_connector.php data    - Fetch fitness data\n";
    exit(1);
}

// ============================================================================
// FUNCTIONS
// ============================================================================

/**
 * Step 1: Generate authorization URL for user to visit
 */
function handleAuthorization($config) {
    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/fitness.activity.read https://www.googleapis.com/auth/fitness.body.read',
        'access_type' => 'offline',
        'prompt' => 'consent',
    ]);

    echo "Please visit this URL to authorize:\n\n";
    echo $authUrl . "\n\n";
    echo "After authorization, you'll be redirected. Copy the 'code' parameter from the URL.\n";
    echo "Then run: php google_fit_connector.php token YOUR_AUTH_CODE\n";
}

/**
 * Step 2: Exchange authorization code for access token
 */
function exchangeTokenFromCode($config, $authCode) {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'code' => $authCode,
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'redirect_uri' => $config['redirect_uri'],
        'grant_type' => 'authorization_code',
    ]));

    // Handle SSL certificate verification
    // For development on Windows, we may need to disable SSL verification
    // In production, use a proper CA bundle
    $caBundle = __DIR__ . '/cacert.pem';
    if (file_exists($caBundle)) {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
    } else {
        // For Windows development only - downloads CA bundle or disables verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        echo "cURL Error: $curlError\n";
        exit(1);
    }

    if ($httpCode !== 200) {
        echo "Error exchanging code for token (HTTP $httpCode):\n";
        echo "Response: " . $response . "\n\n";
        echo "Debugging info:\n";
        echo "- Auth Code: " . substr($authCode, 0, 10) . "...\n";
        echo "- Client ID: " . substr($config['client_id'], 0, 20) . "...\n";
        echo "- Redirect URI: " . $config['redirect_uri'] . "\n";
        exit(1);
    }

    $tokenData = json_decode($response, true);
    
    // Save token to file
    file_put_contents($config['token_file'], json_encode($tokenData, JSON_PRETTY_PRINT));
    echo "Token saved! You can now fetch data.\n";
    echo "Run: php google_fit_connector.php data\n";
}

/**
 * Step 3: Fetch fitness data from Google Fit API
 */
function fetchFitnessData($config) {
    if (!file_exists($config['token_file'])) {
        echo "No token found. Run 'php google_fit_connector.php auth' first.\n";
        exit(1);
    }

    $tokenData = json_decode(file_get_contents($config['token_file']), true);
    $accessToken = $tokenData['access_token'];

    // Get today's date range
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $startOfDay = clone $now;
    $startOfDay->setTime(0, 0, 0);
    
    $startMs = (int)($startOfDay->getTimestamp() * 1000);
    $endMs = (int)($now->getTimestamp() * 1000);

    // Request body for aggregated fitness data
    $requestBody = [
        'aggregateBy' => [
            [
                'dataTypeName' => 'com.google.step_count.delta',
            ],
            [
               // 'dataTypeName' => 'com.google.distance.delta',
            ],
            [
             //   'dataTypeName' => 'com.google.calories.expended',
            ],
            [
               // 'dataTypeName' => 'com.google.heart_rate.bpm',
            ],
        ],
        'bucketByTime' => [
            'durationMillis' => 86400000, // 1 day in milliseconds
        ],
        'startTimeMillis' => $startMs,
        'endTimeMillis' => $endMs,
    ];

    // Make API request
    $ch = curl_init('https://www.googleapis.com/fitness/v1/users/me/dataset:aggregate');
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));

    // Handle SSL certificate verification
    $caBundle = __DIR__ . '/cacert.pem';
    if (file_exists($caBundle)) {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        echo "cURL Error: $curlError\n";
        exit(1);
    }

    if ($httpCode !== 200) {
        echo "Error fetching data (HTTP $httpCode):\n";
        echo $response . "\n";
        exit(1);
    }

    $data = json_decode($response, true);
    
    echo "=== Google Fit Data ===\n\n";
    
    if (isset($data['bucket']) && !empty($data['bucket'])) {
        foreach ($data['bucket'] as $bucket) {
            echo "Date: " . date('Y-m-d', $bucket['startTimeMillis'] / 1000) . "\n";
            
            foreach ($bucket['dataset'] as $dataset) {
                $dataType = $dataset['dataSourceId'] ?? 'Unknown';
                
                if (!empty($dataset['point'])) {
                    foreach ($dataset['point'] as $point) {
                        foreach ($point['value'] as $value) {
                            if (isset($value['intVal'])) {
                                echo "  " . $dataType . ": " . $value['intVal'] . "\n";
                            } elseif (isset($value['fpVal'])) {
                                echo "  " . $dataType . ": " . round($value['fpVal'], 2) . "\n";
                            }
                        }
                    }
                }
            }
        }
    } else {
        echo "No data found.\n";
    }
}
