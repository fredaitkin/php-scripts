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
$config = applyDistanceProfile($config);

// ============================================================================
// MAIN SCRIPT
// ============================================================================

if (!isset($argv[1])) {
    echo "Usage:\n";
    echo "  php google_fit_connector.php auth              - Get authorization code\n";
    echo "  php google_fit_connector.php data [DATE]       - Fetch fitness data (DATE format: YYYY-MM-DD, default: today)\n";
    echo "  php google_fit_connector.php auto [DATE]       - Refresh token and fetch data (DATE format: YYYY-MM-DD, default: today)\n";
    echo "  php google_fit_connector.php csv START_DATE [END_DATE] [OUTPUT_PATH] [--debug] - Export daily data to CSV\n";
    exit(1);
}

$command = $argv[1];
$debugMode = in_array('--debug', $argv, true);
$defaultTimezone = resolveConfiguredTimezone($config);
$date = isset($argv[2]) ? $argv[2] : (new DateTime('now', $defaultTimezone))->format('Y-m-d'); // Get date parameter or use today

if ($command === 'auth') {
    handleAuthorization($config);
} elseif ($command === 'data') {
    fetchFitnessData($config, $date);
} elseif ($command === 'auto') {
    autoRefreshAndFetchData($config, $date);
} elseif ($command === 'csv' && isset($argv[2])) {
    $csvArgs = array_values(array_filter(array_slice($argv, 2), function ($arg) {
        return strpos((string)$arg, '--') !== 0;
    }));

    $startDate = $csvArgs[0] ?? null;
    $endDate = $csvArgs[1] ?? null;
    $outputPath = $csvArgs[2] ?? null;

    if ($startDate === null) {
        echo "Usage:\n";
        echo "  php google_fit_connector.php csv START_DATE [END_DATE] [OUTPUT_PATH] [--debug] - Export daily data to CSV\n";
        exit(1);
    }

    ensureValidAccessToken($config);
    fetchDailyDataRangeToCsv($config, $startDate, $endDate, $outputPath, $debugMode);
} elseif ($command === 'token' && isset($argv[2])) {
    exchangeTokenFromCode($config, $argv[2]);
} else {
    echo "Usage:\n";
    echo "  php google_fit_connector.php auth              - Get authorization code\n";
    echo "  php google_fit_connector.php token CODE        - Exchange code for token\n";
    echo "  php google_fit_connector.php data [DATE]       - Fetch fitness data (DATE format: YYYY-MM-DD, default: today)\n";
    echo "  php google_fit_connector.php auto [DATE]       - Refresh token and fetch data (DATE format: YYYY-MM-DD, default: today)\n";
    echo "  php google_fit_connector.php csv START_DATE [END_DATE] [OUTPUT_PATH] [--debug] - Export daily data to CSV\n";
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
 * Apply preset distance profile overrides
 */
function applyDistanceProfile($config) {
    $profile = strtolower((string)($config['distance_profile'] ?? 'custom'));

    if ($profile === 'lifefitness') {
        $config['distance_mode'] = 'api';
        $config['distance_source_include_contains'] = 'lifefitness';
        unset($config['distance_source_exclude_contains']);
        return $config;
    }

    if ($profile === 'google_fit') {
        $config['distance_mode'] = 'steps_estimate';
        if (!isset($config['step_length_meters'])) {
            $config['step_length_meters'] = 0.4315;
        }
        unset($config['distance_source_include_contains']);
        unset($config['distance_source_exclude_contains']);
        return $config;
    }

    return $config;
}

/**
 * Resolve configured timezone with safe UTC fallback
 */
function resolveConfiguredTimezone($config) {
    $timezoneName = $config['timezone'] ?? date_default_timezone_get();

    try {
        return new DateTimeZone($timezoneName);
    } catch (Exception $e) {
        echo "Warning: Invalid timezone '$timezoneName'. Falling back to UTC.\n";
        return new DateTimeZone('UTC');
    }
}

/**
 * Step 3: Fetch fitness data from Google Fit API
 */
function fetchFitnessData($config, $date = null) {
    $data = fetchAggregatedFitnessData($config, $date);

    $timezone = resolveConfiguredTimezone($config);
    
    echo "=== Google Fit Data ===\n\n";
    
    if (isset($data['bucket']) && !empty($data['bucket'])) {
        foreach ($data['bucket'] as $bucket) {
            $bucketStartTimestamp = (int)($bucket['startTimeMillis'] / 1000);
            $bucketDate = (new DateTime('@' . $bucketStartTimestamp))
                ->setTimezone($timezone)
                ->format('Y-m-d');
            echo "Date: " . $bucketDate . "\n";
            
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

    $timezone = resolveConfiguredTimezone($config);

    $startOfDay = new DateTime($date . ' 00:00:00', $timezone);
    $endOfDay = (clone $startOfDay)->modify('+1 day');
    $startMs = (int)($startOfDay->getTimestamp() * 1000);
    $endMs = (int)($endOfDay->getTimestamp() * 1000);

    $stepDataSourceId = $config['fit_step_data_source'] ?? 'derived:com.google.step_count.delta:com.google.android.gms:merge_step_deltas';
    $distanceDataSourceId = $config['fit_distance_data_source'] ?? 'derived:com.google.distance.delta:com.google.android.gms:merge_distance_delta';

    $requestBody = [
        'aggregateBy' => [
            [
                'dataSourceId' => $stepDataSourceId,
            ],
            [
                'dataSourceId' => $distanceDataSourceId,
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
function fetchDailyDataRangeToCsv($config, $startDate, $endDate = null, $outputPath = null, $debugMode = false) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        echo "Invalid start date format. Use YYYY-MM-DD\n";
        exit(1);
    }

    $timezone = resolveConfiguredTimezone($config);

    if ($endDate === null) {
        $endDate = (new DateTime('now', $timezone))->format('Y-m-d');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        echo "Invalid end date format. Use YYYY-MM-DD\n";
        exit(1);
    }

    $start = new DateTime($startDate . ' 00:00:00', $timezone);
    $end = new DateTime($endDate . ' 00:00:00', $timezone);

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
    $monthlyTotals = [];
    $yearlyTotals = [];
    while ($cursor <= $end) {
        $currentDate = $cursor->format('Y-m-d');
        $data = fetchAggregatedFitnessData($config, $currentDate);

        $preferredDistanceMeters = null;
        $preferredDistanceSources = [];
        $distanceSourceInclude = $config['distance_source_include_contains'] ?? ($config['preferred_distance_source_contains'] ?? null);
        $distanceSourceExclude = $config['distance_source_exclude_contains'] ?? null;
        $hasSourceFilter = (is_string($distanceSourceInclude) && $distanceSourceInclude !== '')
            || (is_array($distanceSourceInclude) && !empty($distanceSourceInclude))
            || (is_string($distanceSourceExclude) && $distanceSourceExclude !== '')
            || (is_array($distanceSourceExclude) && !empty($distanceSourceExclude));

        if ($hasSourceFilter) {
            $preferredDistanceResult = fetchDistanceFromMatchingRawSources(
                $config,
                $currentDate,
                $distanceSourceInclude,
                $distanceSourceExclude,
                $debugMode
            );
            $preferredDistanceSources = $preferredDistanceResult['matched_sources'];
                $preferredDistanceSourceTotals = $preferredDistanceResult['matched_source_totals'];
            if ($preferredDistanceResult['distance_meters'] > 0) {
                $preferredDistanceMeters = $preferredDistanceResult['distance_meters'];
            }
        }

            $totals = extractDailyTotals($data, $debugMode, $currentDate, $preferredDistanceMeters, $preferredDistanceSources, $preferredDistanceSourceTotals ?? [], $config);

        fputcsv($csvHandle, [
            $currentDate,
            $totals['steps'],
            round($totals['distance_miles'], 2),
        ], ',', '"', '\\');

        $totalSteps += (int)$totals['steps'];
        $totalDistanceMiles += (float)$totals['distance_miles'];
        $dayCount++;

        $monthKey = $cursor->format('M Y');
        if (!isset($monthlyTotals[$monthKey])) {
            $monthlyTotals[$monthKey] = [
                'steps' => 0,
                'miles' => 0.0,
                'days' => 0,
            ];
        }
        $monthlyTotals[$monthKey]['steps'] += (int)$totals['steps'];
        $monthlyTotals[$monthKey]['miles'] += (float)$totals['distance_miles'];
        $monthlyTotals[$monthKey]['days']++;

        $yearKey = $cursor->format('Y');
        if (!isset($yearlyTotals[$yearKey])) {
            $yearlyTotals[$yearKey] = [
                'steps' => 0,
                'miles' => 0.0,
                'days' => 0,
            ];
        }
        $yearlyTotals[$yearKey]['steps'] += (int)$totals['steps'];
        $yearlyTotals[$yearKey]['miles'] += (float)$totals['distance_miles'];
        $yearlyTotals[$yearKey]['days']++;

        $cursor->modify('+1 day');
    }

    $avgSteps = $dayCount > 0 ? $totalSteps / $dayCount : 0;
    $avgMiles = $dayCount > 0 ? $totalDistanceMiles / $dayCount : 0;

    foreach ($monthlyTotals as $month => $monthTotals) {
        $monthAvgSteps = $monthTotals['days'] > 0 ? $monthTotals['steps'] / $monthTotals['days'] : 0;
        $monthAvgMiles = $monthTotals['days'] > 0 ? $monthTotals['miles'] / $monthTotals['days'] : 0;
        fputcsv($csvHandle, [
            'MONTH TOTAL ' . $month,
            $monthTotals['steps'],
            round($monthTotals['miles'], 2),
        ], ',', '"', '\\');
        fputcsv($csvHandle, [
            'MONTH AVERAGE ' . $month,
            round($monthAvgSteps, 2),
            round($monthAvgMiles, 2),
        ], ',', '"', '\\');
    }

    foreach ($yearlyTotals as $year => $yearTotals) {
        $yearAvgSteps = $yearTotals['days'] > 0 ? $yearTotals['steps'] / $yearTotals['days'] : 0;
        $yearAvgMiles = $yearTotals['days'] > 0 ? $yearTotals['miles'] / $yearTotals['days'] : 0;
        fputcsv($csvHandle, [
            'YEAR TOTAL ' . $year,
            $yearTotals['steps'],
            round($yearTotals['miles'], 2),
        ], ',', '"', '\\');
        fputcsv($csvHandle, [
            'YEAR AVERAGE ' . $year,
            round($yearAvgSteps, 2),
            round($yearAvgMiles, 2),
        ], ',', '"', '\\');
    }

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
 * Fetch distance from raw data sources using include/exclude source filters
 */
function fetchDistanceFromMatchingRawSources($config, $date, $sourceContains = null, $sourceExcludes = null, $debugMode = false) {
    if (!file_exists($config['token_file'])) {
        return [
            'distance_meters' => 0.0,
            'matched_sources' => [],
            'matched_source_totals' => [],
        ];
    }

    $tokenData = json_decode(file_get_contents($config['token_file']), true);
    $accessToken = $tokenData['access_token'] ?? null;
    if (!$accessToken) {
        return [
            'distance_meters' => 0.0,
            'matched_sources' => [],
            'matched_source_totals' => [],
        ];
    }

    $timezone = resolveConfiguredTimezone($config);
    $startOfDay = new DateTime($date . ' 00:00:00', $timezone);
    $endOfDay = (clone $startOfDay)->modify('+1 day');
    $startNs = (int)($startOfDay->getTimestamp() * 1000000000);
    $endNs = (int)($endOfDay->getTimestamp() * 1000000000);
    $datasetId = $startNs . '-' . $endNs;

    $dataSourcesResponse = googleFitApiGetJson($config, 'https://www.googleapis.com/fitness/v1/users/me/dataSources', $accessToken);
    $dataSources = $dataSourcesResponse['dataSource'] ?? [];

    $includePatterns = normalizeSourceFilterPatterns($sourceContains);
    $excludePatterns = normalizeSourceFilterPatterns($sourceExcludes);

    $allDistanceSources = [];
    $matchedDistanceSources = [];
    foreach ($dataSources as $source) {
        $streamId = $source['dataStreamId'] ?? '';
        $dataTypeName = $source['dataType']['name'] ?? '';

        if ($dataTypeName !== 'com.google.distance.delta') {
            continue;
        }

        $allDistanceSources[] = $streamId;

        $matchesInclude = empty($includePatterns) ? true : sourceMatchesAnyPattern($streamId, $includePatterns);
        $matchesExclude = !empty($excludePatterns) && sourceMatchesAnyPattern($streamId, $excludePatterns);

        if ($matchesInclude && !$matchesExclude) {
            $matchedDistanceSources[] = $streamId;
        }
    }

    $distanceMeters = 0.0;
    $matchedSourceTotals = [];
    foreach ($matchedDistanceSources as $streamId) {
        $datasetUrl = 'https://www.googleapis.com/fitness/v1/users/me/dataSources/' . rawurlencode($streamId) . '/datasets/' . $datasetId;
        $datasetResponse = googleFitApiGetJson($config, $datasetUrl, $accessToken);

        $sourceMeters = 0.0;

        if (!empty($datasetResponse['point'])) {
            foreach ($datasetResponse['point'] as $point) {
                foreach (($point['value'] ?? []) as $value) {
                    if (isset($value['fpVal'])) {
                        $sourceMeters += (float)$value['fpVal'];
                    } elseif (isset($value['intVal'])) {
                        $sourceMeters += (float)$value['intVal'];
                    }
                }
            }
        }

        $distanceMeters += $sourceMeters;
        $matchedSourceTotals[$streamId] = $sourceMeters;
    }

    if ($debugMode) {
        echo "  raw distance sources: " . (empty($allDistanceSources) ? 'none' : implode(', ', $allDistanceSources)) . "\n";
        echo "  preferred raw matches: " . (empty($matchedDistanceSources) ? 'none' : implode(', ', $matchedDistanceSources)) . "\n";
        if (!empty($matchedDistanceSources)) {
            $distanceMiles = $distanceMeters * 0.000621371;
            echo "  preferred raw distance: " . round($distanceMeters, 2) . " m (" . round($distanceMiles, 2) . " mi)\n";
        }
    }

    return [
        'distance_meters' => $distanceMeters,
        'matched_sources' => $matchedDistanceSources,
        'matched_source_totals' => $matchedSourceTotals,
    ];
}

/**
 * Perform a Google Fit API GET and decode JSON response
 */
function googleFitApiGetJson($config, $url, $accessToken) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
    ]);

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

    if ($curlError || $httpCode !== 200 || !$response) {
        return [];
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Normalize include/exclude source filter values to array
 */
function normalizeSourceFilterPatterns($value) {
    if (is_array($value)) {
        return array_values(array_filter(array_map('strval', $value), function ($item) {
            return $item !== '';
        }));
    }

    if (is_string($value) && $value !== '') {
        return [$value];
    }

    return [];
}

/**
 * Case-insensitive source id match against filter patterns
 */
function sourceMatchesAnyPattern($sourceId, $patterns) {
    foreach ($patterns as $pattern) {
        if (stripos($sourceId, $pattern) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Sum numeric point values in a dataset
 */
function sumDatasetPointValues($dataset) {
    $total = 0.0;

    if (empty($dataset['point'])) {
        return $total;
    }

    foreach ($dataset['point'] as $point) {
        foreach ($point['value'] as $value) {
            if (isset($value['intVal'])) {
                $total += (float)$value['intVal'];
            } elseif (isset($value['fpVal'])) {
                $total += (float)$value['fpVal'];
            }
        }
    }

    return $total;
}

/**
 * Extract daily step and distance totals from an aggregate response
 */
function extractDailyTotals($data, $debugMode = false, $dateLabel = null, $preferredDistanceMeters = null, $preferredDistanceSources = [], $preferredDistanceSourceTotals = [], $config = []) {
    $stepsFromMerged = 0.0;
    $distanceFromMergedMeters = 0.0;
    $stepsFromAllSources = 0.0;
    $distanceFromAllSourcesMeters = 0.0;
    $hasMergedSteps = false;
    $hasMergedDistance = false;
    $debugRows = [];
    $distanceBySource = [];

    if (isset($data['bucket']) && !empty($data['bucket'])) {
        foreach ($data['bucket'] as $bucket) {
            foreach ($bucket['dataset'] as $dataset) {
                $dataType = $dataset['dataSourceId'] ?? '';

                $datasetTotal = sumDatasetPointValues($dataset);
                if ($datasetTotal === 0.0) {
                    continue;
                }

                $isMergedDataset = false;
                $metric = null;

                if (strpos($dataType, 'step_count.delta') !== false) {
                    $metric = 'steps';
                    $stepsFromAllSources += $datasetTotal;
                    if (strpos($dataType, 'merge_step_deltas') !== false) {
                        $hasMergedSteps = true;
                        $stepsFromMerged += $datasetTotal;
                        $isMergedDataset = true;
                    }
                } elseif (strpos($dataType, 'distance.delta') !== false) {
                    $metric = 'distance';
                    $distanceFromAllSourcesMeters += $datasetTotal;
                    if (!isset($distanceBySource[$dataType])) {
                        $distanceBySource[$dataType] = 0.0;
                    }
                    $distanceBySource[$dataType] += $datasetTotal;
                    if (strpos($dataType, 'merge_distance_delta') !== false) {
                        $hasMergedDistance = true;
                        $distanceFromMergedMeters += $datasetTotal;
                        $isMergedDataset = true;
                    }
                }

                if ($debugMode && $metric !== null) {
                    $debugRows[] = [
                        'metric' => $metric,
                        'source' => $dataType,
                        'is_merged' => $isMergedDataset,
                        'value' => $datasetTotal,
                    ];
                }
            }
        }
    }

    $steps = $hasMergedSteps ? (int)round($stepsFromMerged) : (int)round($stepsFromAllSources);
    $distanceMeters = $hasMergedDistance ? $distanceFromMergedMeters : $distanceFromAllSourcesMeters;
    $distanceSelectionMode = $hasMergedDistance ? 'merged' : 'all_sources_fallback';
    if ($preferredDistanceMeters !== null && $preferredDistanceMeters > 0) {
        $distanceMeters = $preferredDistanceMeters;
        $distanceSelectionMode = 'preferred_raw_source_filter';
    }

    $distanceMode = $config['distance_mode'] ?? 'api';
    if ($distanceMode === 'steps_estimate') {
        $stepLengthMeters = isset($config['step_length_meters']) ? (float)$config['step_length_meters'] : 0.4315;
        if ($stepLengthMeters > 0) {
            $distanceMeters = $steps * $stepLengthMeters;
            $distanceSelectionMode = 'steps_estimate';
        }
    }

    $distanceMiles = $distanceMeters * 0.000621371;

    if ($debugMode) {
        $label = $dateLabel ?? 'unknown-date';
        echo "\n[DEBUG] Daily source breakdown for $label\n";
        foreach ($debugRows as $row) {
            if ($row['metric'] === 'steps') {
                echo "  steps | " . ($row['is_merged'] ? 'MERGED ' : 'SOURCE ') . "| " . (int)round($row['value']) . " | " . $row['source'] . "\n";
            } else {
                $miles = $row['value'] * 0.000621371;
                echo "  dist  | " . ($row['is_merged'] ? 'MERGED ' : 'SOURCE ') . "| " . round($row['value'], 2) . " m (" . round($miles, 2) . " mi) | " . $row['source'] . "\n";
            }
        }

        echo "  chosen steps source: " . ($hasMergedSteps ? 'merged' : 'all_sources_fallback') . " => $steps\n";
        echo "  chosen dist source:  " . $distanceSelectionMode . " => " . round($distanceMeters, 2) . " m (" . round($distanceMiles, 2) . " mi)\n";
        if ($distanceSelectionMode === 'steps_estimate') {
            $stepLengthMeters = isset($config['step_length_meters']) ? (float)$config['step_length_meters'] : 0.4315;
            echo "  steps estimate config: step_length_meters=" . round($stepLengthMeters, 4) . "\n";
        }
        if (!empty($preferredDistanceSources)) {
            echo "  preferred dist sources used: " . implode(', ', $preferredDistanceSources) . "\n";
            foreach ($preferredDistanceSourceTotals as $sourceId => $sourceMeters) {
                $sourceMiles = $sourceMeters * 0.000621371;
                echo "    - " . round($sourceMeters, 2) . " m (" . round($sourceMiles, 2) . " mi) | " . $sourceId . "\n";
            }
        }
    }

    return [
        'steps' => $steps,
        'distance_miles' => $distanceMiles,
    ];
}
