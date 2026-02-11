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
    echo "  php google_fit_connector.php auth              - Get authorization code\n";
    echo "  php google_fit_connector.php data [DATE]       - Fetch fitness data (DATE format: YYYY-MM-DD, default: today)\n";
    echo "  php google_fit_connector.php auto [DATE]       - Refresh token and fetch data (DATE format: YYYY-MM-DD, default: today)\n";
    echo "  php google_fit_connector.php csv START_DATE [END_DATE] [OUTPUT_PATH] - Export daily data to CSV\n";
    exit(1);
}

$command = $argv[1];
$date = isset($argv[2]) ? $argv[2] : date('Y-m-d'); // Get date parameter or use today

if ($command === 'auth') {
    handleAuthorization($config);
} elseif ($command === 'data') {
    fetchFitnessData($config, $date);
} elseif ($command === 'auto') {
    autoRefreshAndFetchData($config, $date);
} elseif ($command === 'csv' && isset($argv[2])) {
    $startDate = $argv[2];
    $endDate = isset($argv[3]) ? $argv[3] : null;
    $outputPath = isset($argv[4]) ? $argv[4] : null;
    ensureValidAccessToken($config);
    fetchDailyDataRangeToCsv($config, $startDate, $endDate, $outputPath);
} elseif ($command === 'token' && isset($argv[2])) {
    exchangeTokenFromCode($config, $argv[2]);
} else {
    echo "Usage:\n";
    echo "  php google_fit_connector.php auth              - Get authorization code\n";
    echo "  php google_fit_connector.php token CODE        - Exchange code for token\n";
    echo "  php google_fit_connector.php data [DATE]       - Fetch fitness data (DATE format: YYYY-MM-DD, default: today)\n";
    echo "  php google_fit_connector.php auto [DATE]       - Refresh token and fetch data (DATE format: YYYY-MM-DD, default: today)\n";
    echo "  php google_fit_connector.php csv START_DATE [END_DATE] [OUTPUT_PATH] - Export daily data to CSV\n";
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
        'scope' => 'https://www.googleapis.com/auth/fitness.activity.read https://www.googleapis.com/auth/fitness.body.read https://www.googleapis.com/auth/fitness.location.read',
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
    
    // Add expiration time
    $tokenData['expires_at'] = time() + ($tokenData['expires_in'] ?? 3600);
    
    // Save token to file
    file_put_contents($config['token_file'], json_encode($tokenData, JSON_PRETTY_PRINT));
    echo "Token saved! You can now fetch data.\n";
    echo "Run: php google_fit_connector.php data\n";
    echo "Or use automated mode: php google_fit_connector.php auto\n";
}

/**
 * Automatic token refresh and data fetch in one command
 */
function autoRefreshAndFetchData($config, $date = null) {
    if ($date === null) {
        $date = date('Y-m-d');
    }
    echo "Starting automated Google Fit data retrieval for " . $date . "...\n\n";
    
    // Check if token file exists
    if (!file_exists($config['token_file'])) {
        echo "Error: No token found at " . $config['token_file'] . "\n";
        echo "You must complete the initial authorization first:\n";
        echo "  1. php google_fit_connector.php auth\n";
        echo "  2. Visit the provided URL and authorize\n";
        echo "  3. Copy the authorization code\n";
        echo "  4. php google_fit_connector.php token YOUR_AUTH_CODE\n";
        exit(1);
    }

    // Load and check token
    $tokenData = json_decode(file_get_contents($config['token_file']), true);
    
    if (!isset($tokenData['refresh_token'])) {
        echo "Error: Refresh token not found in token file.\n";
        echo "Please re-authorize: php google_fit_connector.php auth\n";
        exit(1);
    }

    // Check if token is expired and refresh if needed
    $tokenExpiresAt = $tokenData['expires_at'] ?? 0;
    $now = time();

    if ($now > $tokenExpiresAt - 300) { // Refresh if expiring within 5 minutes
        echo "Token expired or expiring soon. Refreshing...\n";
        refreshAccessToken($config, $tokenData);
        echo "Token refreshed successfully.\n\n";
        
        // Reload token data after refresh
        $tokenData = json_decode(file_get_contents($config['token_file']), true);
    } else {
        $secondsLeft = $tokenExpiresAt - $now;
        echo "Token is valid (expires in ~" . round($secondsLeft / 60) . " minutes).\n\n";
    }

    // Now fetch the fitness data
    fetchFitnessData($config, $date);
}

/**
 * Refresh the access token using the refresh token
 */
function refreshAccessToken($config, $tokenData) {
    if (!isset($tokenData['refresh_token'])) {
        echo "Error: Refresh token not available.\n";
        exit(1);
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'refresh_token' => $tokenData['refresh_token'],
        'grant_type' => 'refresh_token',
    ]));

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
        echo "cURL Error during token refresh: $curlError\n";
        exit(1);
    }

    if ($httpCode !== 200) {
        echo "Error refreshing token (HTTP $httpCode):\n";
        echo "Response: " . $response . "\n";
        exit(1);
    }

    $newTokenData = json_decode($response, true);
    
    // Preserve refresh token if not returned (Google doesn't always return it)
    if (!isset($newTokenData['refresh_token'])) {
        $newTokenData['refresh_token'] = $tokenData['refresh_token'];
    }
    
    // Add expiration time
    $newTokenData['expires_at'] = time() + ($newTokenData['expires_in'] ?? 3600);

    // Save updated token
    file_put_contents($config['token_file'], json_encode($newTokenData, JSON_PRETTY_PRINT));
}

/**
 * Ensure a valid access token is available, refreshing if needed
 */
function ensureValidAccessToken($config) {
    if (!file_exists($config['token_file'])) {
        echo "Error: No token found at " . $config['token_file'] . "\n";
        echo "You must complete the initial authorization first:\n";
        echo "  1. php google_fit_connector.php auth\n";
        echo "  2. Visit the provided URL and authorize\n";
        echo "  3. Copy the authorization code\n";
        echo "  4. php google_fit_connector.php token YOUR_AUTH_CODE\n";
        exit(1);
    }

    $tokenData = json_decode(file_get_contents($config['token_file']), true);

    if (!isset($tokenData['refresh_token'])) {
        echo "Error: Refresh token not found in token file.\n";
        echo "Please re-authorize: php google_fit_connector.php auth\n";
        exit(1);
    }

    $tokenExpiresAt = $tokenData['expires_at'] ?? 0;
    $now = time();

    if ($now > $tokenExpiresAt - 300) {
        echo "Token expired or expiring soon. Refreshing...\n";
        refreshAccessToken($config, $tokenData);
        echo "Token refreshed successfully.\n\n";
    }
}

/**
 * Step 3: Fetch fitness data from Google Fit API
 */
function fetchFitnessData($config, $date = null) {
    $data = fetchAggregatedFitnessData($config, $date);
    
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
                                $intValue = $value['intVal'];
                                echo "  " . $dataType . ": " . $intValue;
                                
                                // Convert distance from meters to miles if applicable
                                if (strpos($dataType, 'distance.delta') !== false) {
                                    $miles = $intValue * 0.000621371;
                                    echo " meters (" . round($miles, 2) . " miles)";
                                }
                                echo "\n";
                            } elseif (isset($value['fpVal'])) {
                                $fpValue = $value['fpVal'];
                                echo "  " . $dataType . ": " . round($fpValue, 2);
                                
                                // Convert distance from meters to miles if applicable
                                if (strpos($dataType, 'distance.delta') !== false) {
                                    $miles = $fpValue * 0.000621371;
                                    echo " meters (" . round($miles, 2) . " miles)";
                                }
                                echo "\n";
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

/**
 * Fetch aggregated fitness data for a single day
 */
function fetchAggregatedFitnessData($config, $date = null) {
    if (!file_exists($config['token_file'])) {
        echo "No token found. Run 'php google_fit_connector.php auth' first.\n";
        exit(1);
    }

    if ($date === null) {
        $date = date('Y-m-d');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo "Invalid date format. Use YYYY-MM-DD\n";
        exit(1);
    }

    $tokenData = json_decode(file_get_contents($config['token_file']), true);
    $accessToken = $tokenData['access_token'];

    $timezoneName = $config['timezone'] ?? date_default_timezone_get();
    try {
        $timezone = new DateTimeZone($timezoneName);
    } catch (Exception $e) {
        echo "Warning: Invalid timezone '$timezoneName'. Falling back to UTC.\n";
        $timezone = new DateTimeZone('UTC');
    }

    $startOfDay = new DateTime($date . ' 00:00:00', $timezone);
    $endOfDay = new DateTime($date . ' 23:59:59', $timezone);
    $startMs = (int)($startOfDay->getTimestamp() * 1000);
    $endMs = (int)($endOfDay->getTimestamp() * 1000);

    $requestBody = [
        'aggregateBy' => [
            [
                'dataTypeName' => 'com.google.step_count.delta',
            ],
            [
                'dataTypeName' => 'com.google.distance.delta',
            ],
            [
                // Uncomment to include calories data
                // 'dataTypeName' => 'com.google.calories.expended',
            ],
            [
                // Uncomment to include heart rate data
                // 'dataTypeName' => 'com.google.heart_rate.bpm',
            ],
        ],
        'bucketByTime' => [
            'durationMillis' => 86400000,
        ],
        'startTimeMillis' => $startMs,
        'endTimeMillis' => $endMs,
    ];

    $maxAttempts = 3;
    $lastCurlError = null;
    $lastHttpCode = null;
    $lastResponse = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init('https://www.googleapis.com/fitness/v1/users/me/dataset:aggregate');
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));

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

        if (!$curlError && $httpCode === 200) {
            return json_decode($response, true);
        }

        $lastCurlError = $curlError ?: null;
        $lastHttpCode = $httpCode;
        $lastResponse = $response;

        if ($attempt < $maxAttempts) {
            $sleepSeconds = $attempt; // 1s, 2s
            echo "Request failed (attempt $attempt/$maxAttempts). Retrying in $sleepSeconds seconds...\n";
            sleep($sleepSeconds);
        }
    }

    if ($lastCurlError) {
        echo "cURL Error: $lastCurlError\n";
        exit(1);
    }

    echo "Error fetching data (HTTP $lastHttpCode):\n";
    echo $lastResponse . "\n";
    exit(1);
}

/**
 * Export daily step and distance totals from a start date to CSV
 */
function fetchDailyDataRangeToCsv($config, $startDate, $endDate = null, $outputPath = null) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        echo "Invalid start date format. Use YYYY-MM-DD\n";
        exit(1);
    }

    if ($endDate === null) {
        $endDate = date('Y-m-d');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        echo "Invalid end date format. Use YYYY-MM-DD\n";
        exit(1);
    }

    $start = new DateTime($startDate . ' 00:00:00');
    $end = new DateTime($endDate . ' 00:00:00');

    if ($end < $start) {
        echo "End date cannot be earlier than start date.\n";
        exit(1);
    }

    if ($outputPath === null) {
        $outputPath = __DIR__ . '/google_fit_daily_' . $startDate . '_to_' . $endDate . '.csv';
    }

    $csvHandle = fopen($outputPath, 'w');
    if ($csvHandle === false) {
        echo "Unable to write CSV file to: $outputPath\n";
        exit(1);
    }

    fputcsv($csvHandle, ['date', 'steps', 'miles'], ',', '"', '\\');

    $cursor = clone $start;
    $totalSteps = 0;
    $totalDistanceMiles = 0.0;
    $dayCount = 0;
    while ($cursor <= $end) {
        $currentDate = $cursor->format('Y-m-d');
        $data = fetchAggregatedFitnessData($config, $currentDate);
        $totals = extractDailyTotals($data);

        fputcsv($csvHandle, [
            $currentDate,
            $totals['steps'],
            round($totals['distance_miles'], 2),
        ], ',', '"', '\\');

        $totalSteps += (int)$totals['steps'];
        $totalDistanceMiles += (float)$totals['distance_miles'];
        $dayCount++;

        $cursor->modify('+1 day');
    }

    $avgSteps = $dayCount > 0 ? $totalSteps / $dayCount : 0;
    $avgMiles = $dayCount > 0 ? $totalDistanceMiles / $dayCount : 0;
    fputcsv($csvHandle, [
        'TOTALS',
        $totalSteps,
        round($totalDistanceMiles, 2),
    ], ',', '"', '\\');
    fputcsv($csvHandle, [
        'AVERAGE',
        round($avgSteps, 2),
        round($avgMiles, 2),
    ], ',', '"', '\\');

    fclose($csvHandle);
    echo "CSV export complete: $outputPath\n";
}

/**
 * Extract daily step and distance totals from an aggregate response
 */
function extractDailyTotals($data) {
    $steps = 0;
    $distanceMeters = 0.0;

    if (isset($data['bucket']) && !empty($data['bucket'])) {
        foreach ($data['bucket'] as $bucket) {
            foreach ($bucket['dataset'] as $dataset) {
                $dataType = $dataset['dataSourceId'] ?? '';

                if (!empty($dataset['point'])) {
                    foreach ($dataset['point'] as $point) {
                        foreach ($point['value'] as $value) {
                            $valueNum = null;
                            if (isset($value['intVal'])) {
                                $valueNum = (int)$value['intVal'];
                            } elseif (isset($value['fpVal'])) {
                                $valueNum = (float)$value['fpVal'];
                            }

                            if ($valueNum !== null) {
                                if (strpos($dataType, 'step_count.delta') !== false) {
                                    $steps += (int)$valueNum;
                                } elseif (strpos($dataType, 'distance.delta') !== false) {
                                    $distanceMeters += (float)$valueNum;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    $distanceMiles = $distanceMeters * 0.000621371;

    return [
        'steps' => $steps,
        'distance_miles' => $distanceMiles,
    ];
}
