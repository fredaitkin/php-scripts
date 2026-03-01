<?php

/**
 * Standalone Google Fit API Connector
 * 
 * This script handles OAuth 2.0 authentication and connects to Google Fit API
 * 
 * Usage:
 * 1. Set your credentials in the config section below
 * 2. Run: php google_fit_connector.php auth    (to get authorization)
 * 3. Run: php google_fit_connector.php load    (to fetch fitness data)
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
    echo "  php google_fit_connector.php load [DATE]       - Load fitness data (DATE format: YYYY-MM-DD; if omitted, uses day after latest DB row)\n";
    echo "  php google_fit_connector.php csv [OUTPUT_PATH] - Export measurements table to CSV\n";
    exit(1);
}

$command = $argv[1];
$defaultTimezone = resolveConfiguredTimezone($config);
$date = isset($argv[2]) ? $argv[2] : null;

if ($command === 'auth') {
    handleAuthorization($config);
} elseif ($command === 'load') {
    ensureValidAccessToken($config);
    load($config, $date);
} elseif ($command === 'csv') {
    ensureValidAccessToken($config);
    $outputPath = isset($argv[2]) ? $argv[2] : null;
    exportMeasurementsToCsv($config, $outputPath);
} elseif ($command === 'token' && isset($argv[2])) {
    exchangeTokenFromCode($config, $argv[2]);
} else {
    echo "Usage:\n";
    echo "  php google_fit_connector.php auth              - Get authorization code\n";
    echo "  php google_fit_connector.php token CODE        - Exchange code for token\n";
    echo "  php google_fit_connector.php load [DATE]       - Load fitness data (DATE format: YYYY-MM-DD; if omitted, uses day after latest DB row)\n";
    echo "  php google_fit_connector.php csv [OUTPUT_PATH] - Export measurements table to CSV\n";
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
    echo "Run: php google_fit_connector.php load\n";
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
 * Convert Google data source IDs to short output labels
 */
function formatDataTypeLabel($dataType) {
    if (preg_match('/([a-z_]+)\.delta/', $dataType, $matches)) {
        return $matches[1];
    }

    return $dataType;
}

/**
 * Convert Google activity type IDs to readable labels
 */
function formatActivityTypeLabel($activityType) {
    $activityMap = [
        1 => 'Biking',
        4 => 'Walking',
        7 => 'Walking',
        8 => 'Walking',
        58 => 'Treadmill',
    ];

    if (!is_numeric($activityType)) {
        return 'Unknown Activity';
    }

    $activityTypeInt = (int)$activityType;
    return $activityMap[$activityTypeInt] ?? ('Activity ' . $activityTypeInt);
}

/**
 * Resolve bucket date safely with fallback to requested day
 */
function resolveBucketDate($bucket, $timezone, $fallbackDate = null) {
    $startMsRaw = $bucket['startTimeMillis'] ?? null;

    if ($startMsRaw !== null && is_numeric($startMsRaw)) {
        $startMs = (float)$startMsRaw;
        if ($startMs > 0) {
            $timestamp = (int)floor($startMs / 1000);
            if ($timestamp > 0) {
                return (new DateTime('@' . $timestamp))
                    ->setTimezone($timezone)
                    ->format('Y-m-d');
            }
        }
    }

    if ($fallbackDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fallbackDate)) {
        return $fallbackDate;
    }

    return (new DateTime('now', $timezone))->format('Y-m-d');
}

/**
 * Step 3: Load fitness data from Google Fit API
 */
function load($config, $date = null) {
    if ($date === null || trim($date) === '') {
        $date = resolveLoadStartDateFromMeasurements($config);
    }

    if (startDateHasBeenProcessed($config, $date)) {
        echo "Error: start date {$date} is earlier than the latest date in measurements table.\n";
        exit(1);
    }

    $timezone = resolveConfiguredTimezone($config);

    $startDate = new DateTime($date . ' 00:00:00', $timezone);
    $endDate = new DateTime('yesterday', $timezone);
    $endDate->setTime(0, 0, 0);

    if ($startDate > $endDate) {
        echo "No dates to load. Start date " . $startDate->format('Y-m-d') . " is after previous day " . $endDate->format('Y-m-d') . ".\n";
        return;
    }

    $allMeasurements = [];
    $cursor = clone $startDate;
    while ($cursor <= $endDate) {
        $currentDate = $cursor->format('Y-m-d');
        $data = fetchAggregatedFitnessData($config, $currentDate);

        $measurements = buildMeasurementsFromAggregateData($data, $timezone, $currentDate);
        if (!empty($measurements)) {
            $allMeasurements = array_merge($allMeasurements, $measurements);
        }

        echo "=== Google Fit Data ({$currentDate}) ===\n\n";
        printLoadOutput($data, $timezone, $currentDate);
        echo "\n";

        $cursor->modify('+1 day');
    }

    insertMeasurements($config, $allMeasurements);
}

/**
 * Check whether a start date is earlier than max measurements date
 */
function startDateHasBeenProcessed($config, $date) {
    try {
        $pdo = getDatabaseConnection($config);
        $stmt = $pdo->query('SELECT MAX(`date`) AS max_date FROM measurements');
        $row = $stmt->fetch();
        $maxDate = $row['max_date'] ?? null;

        if ($maxDate === null) {
            return false;
        }

        $timezone = resolveConfiguredTimezone($config);
        $startDate = new DateTime($date . ' 00:00:00', $timezone);
        $maxDateObj = new DateTime($maxDate, $timezone);
        $maxDateObj->setTime(0, 0, 0);

        return $startDate < $maxDateObj;
    } catch (Exception $e) {
        echo "Error: Unable to validate start date in measurements table: " . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * Print load output for aggregate API data
 */
function printLoadOutput($data, $timezone, $fallbackDate = null) {
    if (isset($data['bucket']) && !empty($data['bucket'])) {
        $dailyActivityTotals = [];

        foreach ($data['bucket'] as $bucket) {
            $bucketDate = resolveBucketDate($bucket, $timezone, $fallbackDate);

            $activityType = isset($bucket['activity']) ? (int)$bucket['activity'] : -1;
            $activityLabel = formatActivityTypeLabel($activityType);

            if (!isset($dailyActivityTotals[$bucketDate])) {
                $dailyActivityTotals[$bucketDate] = [];
            }

            if (!isset($dailyActivityTotals[$bucketDate][$activityLabel])) {
                $dailyActivityTotals[$bucketDate][$activityLabel] = [
                    'steps' => 0,
                    'distance_meters' => 0.0,
                    'activity_types' => [],
                ];
            }

            if ($activityType >= 0) {
                $dailyActivityTotals[$bucketDate][$activityLabel]['activity_types'][$activityType] = true;
            }

            foreach ($bucket['dataset'] as $dataset) {
                $dataType = $dataset['dataSourceId'] ?? 'Unknown';

                if (!empty($dataset['point'])) {
                    foreach ($dataset['point'] as $point) {
                        foreach ($point['value'] as $value) {
                            $valueNum = null;
                            if (isset($value['intVal'])) {
                                $valueNum = (float)$value['intVal'];
                            } elseif (isset($value['fpVal'])) {
                                $valueNum = (float)$value['fpVal'];
                            }

                            if ($valueNum !== null) {
                                if (strpos($dataType, 'step_count.delta') !== false) {
                                    $dailyActivityTotals[$bucketDate][$activityLabel]['steps'] += (int)$valueNum;
                                } elseif (strpos($dataType, 'distance.delta') !== false) {
                                    $dailyActivityTotals[$bucketDate][$activityLabel]['distance_meters'] += $valueNum;
                                }
                            }
                        }
                    }
                }
            }
        }

        foreach ($dailyActivityTotals as $date => $activityGroups) {
            echo "Date: " . $date . "\n";

            foreach ($activityGroups as $activityLabel => $totals) {
                $activityTypeIds = array_keys($totals['activity_types']);
                sort($activityTypeIds);
                $activityTypeSuffix = !empty($activityTypeIds)
                    ? ' (' . implode(',', $activityTypeIds) . ')'
                    : '';

                echo "  activity_type: " . $activityLabel . $activityTypeSuffix . "\n";
                echo "    steps: " . (int)$totals['steps'] . "\n";
                echo "    distance: " . round((float)$totals['distance_meters']) . "\n";
            }
        }
    } else {
        echo "No data found.\n";
    }
}

/**
 * Resolve load start date from max measurements.date + 1 day
 */
function resolveLoadStartDateFromMeasurements($config) {
    $pdo = getDatabaseConnection($config);

    try {
        $stmt = $pdo->query('SELECT MAX(`date`) AS max_date FROM measurements');
        $row = $stmt->fetch();
        $maxDate = $row['max_date'] ?? null;

        if ($maxDate === null) {
            echo "Error: start date is required.\n";
            exit(1);
        }

        $timezone = resolveConfiguredTimezone($config);
        $startDate = new DateTime($maxDate, $timezone);
        $startDate->modify('+1 day');

        return $startDate->format('Y-m-d');
    } catch (Exception $e) {
        echo "Error: Unable to resolve start date from measurements table: " . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * Create and return PDO connection from config
 */
function getDatabaseConnection($config) {
    if (!isset($config['db']) || !is_array($config['db'])) {
        echo "Error: DB config missing.\n";
        exit(1);
    }

    $db = $config['db'];
    $host = $db['host'] ?? '127.0.0.1';
    $port = $db['port'] ?? 3306;
    $database = $db['database'] ?? 'fitness';
    $username = $db['username'] ?? '';
    $password = $db['password'] ?? '';

    if ($username === '') {
        echo "Error: DB username missing.\n";
        exit(1);
    }

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Exception $e) {
        echo "Error: Failed to connect to database: " . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * Build measurement rows (date, meters, steps) from aggregate API response
 */
function buildMeasurementsFromAggregateData($data, $timezone, $fallbackDate = null) {
    $measurements = [];
    $dailyTotals = [];

    if (isset($data['bucket']) && !empty($data['bucket'])) {
        foreach ($data['bucket'] as $bucket) {
            $dateOnly = resolveBucketDate($bucket, $timezone, $fallbackDate);
            $activityType = isset($bucket['activity']) ? (int)$bucket['activity'] : -1;
            $activityLabel = formatActivityTypeLabel($activityType);

            if (!isset($dailyTotals[$dateOnly])) {
                $dailyTotals[$dateOnly] = [
                    'steps' => 0,
                    'distance_meters' => 0.0,
                    'walking_meters' => 0.0,
                    'biking_meters' => 0.0,
                    'treadmill_meters' => 0.0,
                ];
            }

            $bucketTotals = extractDailyTotals(['bucket' => [$bucket]]);
            $dailyTotals[$dateOnly]['steps'] += (int)$bucketTotals['steps'];
            $dailyTotals[$dateOnly]['distance_meters'] += (float)$bucketTotals['distance_meters'];

            if ($activityLabel === 'Walking') {
                $dailyTotals[$dateOnly]['walking_meters'] += (float)$bucketTotals['distance_meters'];
            } elseif ($activityLabel === 'Biking') {
                $dailyTotals[$dateOnly]['biking_meters'] += (float)$bucketTotals['distance_meters'];
            } elseif ($activityLabel === 'Treadmill') {
                $dailyTotals[$dateOnly]['treadmill_meters'] += (float)$bucketTotals['distance_meters'];
            }
        }

        ksort($dailyTotals);

        foreach ($dailyTotals as $dateOnly => $totals) {
            $measurementDate = (new DateTime($dateOnly . ' 00:00:00', $timezone))
                ->setTimezone($timezone)
                ->format('Y-m-d 00:00:00');

            $measurements[] = [
                'date' => $measurementDate,
                'meters' => (int)round($totals['distance_meters']),
                'walking' => (int)round($totals['walking_meters']),
                'biking' => (int)round($totals['biking_meters']),
                'treadmill' => (int)round($totals['treadmill_meters']),
                'steps' => (int)$totals['steps'],
            ];
        }
    }

    return $measurements;
}

/**
 * Insert measurement rows into MySQL measurements table
 */
function insertMeasurements($config, $measurements) {
    if (empty($measurements)) {
        return;
    }

    try {
        $pdo = getDatabaseConnection($config);

        $stmt = $pdo->prepare('INSERT INTO measurements (`date`, `meters`, `walking`, `biking`, `treadmill`, `steps`) VALUES (:date, :meters, :walking, :biking, :treadmill, :steps)');

        foreach ($measurements as $measurement) {
            $stmt->execute([
                ':date' => $measurement['date'],
                ':meters' => (int)$measurement['meters'],
                ':walking' => (int)($measurement['walking'] ?? 0),
                ':biking' => (int)($measurement['biking'] ?? 0),
                ':treadmill' => (int)($measurement['treadmill'] ?? 0),
                ':steps' => (int)$measurement['steps'],
            ]);
        }

        echo "Inserted " . count($measurements) . " measurement row(s) into database.\n";
    } catch (Exception $e) {
        echo "Warning: Failed to insert measurements: " . $e->getMessage() . "\n";
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
        'bucketByActivityType' => [
            'minDurationMillis' => 60000,
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
 * Export measurements table to CSV
 */
function exportMeasurementsToCsv($config, $outputPath = null) {
    if ($outputPath === null) {
        $outputPath = __DIR__ . '/measurements_export.csv';
    }

    try {
        $pdo = getDatabaseConnection($config);
        $stmt = $pdo->query('SELECT `date`, `steps`, `meters`, `walking`, `biking`, `treadmill` FROM measurements ORDER BY `date` ASC');
        $rows = $stmt->fetchAll();

        $csvHandle = fopen($outputPath, 'w');
        if ($csvHandle === false) {
            echo "Unable to write CSV file to: $outputPath\n";
            exit(1);
        }

        fputcsv($csvHandle, ['date', 'steps', 'kilometres', 'walking_kilometres', 'biking_kilometres', 'treadmill_kilometres'], ',', '"', '\\');

        $weekSteps = 0;
        $weekMeters = 0;
        $weekWalkingMeters = 0;
        $weekBikingMeters = 0;
        $weekTreadmillMeters = 0;
        $monthSteps = 0;
        $monthMeters = 0;
        $monthWalkingMeters = 0;
        $monthBikingMeters = 0;
        $monthTreadmillMeters = 0;
        $yearSteps = 0;
        $yearMeters = 0;
        $yearWalkingMeters = 0;
        $yearBikingMeters = 0;
        $yearTreadmillMeters = 0;
        $maxSteps = null;
        $maxStepsDate = null;
        $maxWalkingMeters = null;
        $maxWalkingDate = null;
        $maxBikingMeters = null;
        $maxBikingDate = null;
        $maxTreadmillMeters = null;
        $maxTreadmillDate = null;

        $rowCount = count($rows);
        $currentYearHeader = null;
        for ($i = 0; $i < $rowCount; $i++) {
            $row = $rows[$i];
            $dateObj = new DateTime($row['date']);
            $weekKey = $dateObj->format('o-\\WW');
            $monthKey = $dateObj->format('Y-m');
            $monthDateFromKey = DateTime::createFromFormat('!Y-m', $monthKey);
            $monthName = $monthDateFromKey ? $monthDateFromKey->format('F') : $monthKey;
            $yearKey = $dateObj->format('Y');

            if ($currentYearHeader !== $yearKey) {
                fputcsv($csvHandle, [
                    'YEAR ' . $yearKey,
                    '',
                    '',
                ], ',', '"', '\\');
                $currentYearHeader = $yearKey;
            }

            $steps = (int)$row['steps'];
            $meters = (int)$row['meters'];
            $walkingMeters = (int)($row['walking'] ?? 0);
            $bikingMeters = (int)($row['biking'] ?? 0);
            $treadmillMeters = (int)($row['treadmill'] ?? 0);
            $kilometres = round($meters / 1000, 3);
            $walkingKilometres = round($walkingMeters / 1000, 3);
            $bikingKilometres = round($bikingMeters / 1000, 3);
            $treadmillKilometres = round($treadmillMeters / 1000, 3);

            if ($maxSteps === null || $steps > $maxSteps) {
                $maxSteps = $steps;
                $maxStepsDate = $dateObj->format('Y-m-d');
            }

            if ($maxWalkingMeters === null || $walkingMeters > $maxWalkingMeters) {
                $maxWalkingMeters = $walkingMeters;
                $maxWalkingDate = $dateObj->format('Y-m-d');
            }

            if ($maxBikingMeters === null || $bikingMeters > $maxBikingMeters) {
                $maxBikingMeters = $bikingMeters;
                $maxBikingDate = $dateObj->format('Y-m-d');
            }

            if ($maxTreadmillMeters === null || $treadmillMeters > $maxTreadmillMeters) {
                $maxTreadmillMeters = $treadmillMeters;
                $maxTreadmillDate = $dateObj->format('Y-m-d');
            }

            $weekSteps += $steps;
            $weekMeters += $meters;
            $weekWalkingMeters += $walkingMeters;
            $weekBikingMeters += $bikingMeters;
            $weekTreadmillMeters += $treadmillMeters;
            $monthSteps += $steps;
            $monthMeters += $meters;
            $monthWalkingMeters += $walkingMeters;
            $monthBikingMeters += $bikingMeters;
            $monthTreadmillMeters += $treadmillMeters;
            $yearSteps += $steps;
            $yearMeters += $meters;
            $yearWalkingMeters += $walkingMeters;
            $yearBikingMeters += $bikingMeters;
            $yearTreadmillMeters += $treadmillMeters;

            fputcsv($csvHandle, [
                $row['date'],
                number_format($steps),
                $kilometres,
                $walkingKilometres,
                $bikingKilometres,
                $treadmillKilometres,
            ], ',', '"', '\\');

            $nextRow = ($i + 1 < $rowCount) ? $rows[$i + 1] : null;
            $isLastRow = ($nextRow === null);

            $isWeekEnd = $isLastRow;
            $isMonthEnd = $isLastRow;
            $isYearEnd = $isLastRow;

            if (!$isLastRow) {
                $nextDateObj = new DateTime($nextRow['date']);
                $nextWeekKey = $nextDateObj->format('o-\\WW');
                $nextMonthKey = $nextDateObj->format('Y-m');
                $nextYearKey = $nextDateObj->format('Y');

                $isWeekEnd = ($nextWeekKey !== $weekKey);
                $isMonthEnd = ($nextMonthKey !== $monthKey);
                $isYearEnd = ($nextYearKey !== $yearKey);
            }

            if ($isWeekEnd) {
                fputcsv($csvHandle, [
                    'WEEK TOTAL',
                    number_format($weekSteps),
                    round($weekMeters / 1000, 3),
                    round($weekWalkingMeters / 1000, 3),
                    round($weekBikingMeters / 1000, 3),
                    round($weekTreadmillMeters / 1000, 3),
                ], ',', '"', '\\');
                $weekSteps = 0;
                $weekMeters = 0;
                $weekWalkingMeters = 0;
                $weekBikingMeters = 0;
                $weekTreadmillMeters = 0;
            }

            if ($isMonthEnd) {
                fputcsv($csvHandle, [
                    'MONTH TOTAL ' . $monthName,
                    number_format($monthSteps),
                    round($monthMeters / 1000, 3),
                    round($monthWalkingMeters / 1000, 3),
                    round($monthBikingMeters / 1000, 3),
                    round($monthTreadmillMeters / 1000, 3),
                ], ',', '"', '\\');
                $monthSteps = 0;
                $monthMeters = 0;
                $monthWalkingMeters = 0;
                $monthBikingMeters = 0;
                $monthTreadmillMeters = 0;
            }

            if ($isYearEnd) {
                fputcsv($csvHandle, [
                    'YEAR TOTAL ' . $yearKey,
                    number_format($yearSteps),
                    round($yearMeters / 1000, 3),
                    round($yearWalkingMeters / 1000, 3),
                    round($yearBikingMeters / 1000, 3),
                    round($yearTreadmillMeters / 1000, 3),
                ], ',', '"', '\\');
                $yearSteps = 0;
                $yearMeters = 0;
                $yearWalkingMeters = 0;
                $yearBikingMeters = 0;
                $yearTreadmillMeters = 0;
            }
        }

        if ($maxSteps !== null) {
            fputcsv($csvHandle, [
                'MAX STEPS DATE',
                $maxStepsDate,
                number_format($maxSteps),
                '',
                '',
                '',
            ], ',', '"', '\\');
        }

        if ($maxWalkingMeters !== null) {
            fputcsv($csvHandle, [
                'MAX WALKING KILOMETRES DATE',
                $maxWalkingDate,
                round($maxWalkingMeters / 1000, 3),
                '',
                '',
                '',
            ], ',', '"', '\\');
        }

        if ($maxBikingMeters !== null) {
            fputcsv($csvHandle, [
                'MAX BIKING KILOMETRES DATE',
                $maxBikingDate,
                round($maxBikingMeters / 1000, 3),
                '',
                '',
                '',
            ], ',', '"', '\\');
        }

        if ($maxTreadmillMeters !== null) {
            fputcsv($csvHandle, [
                'MAX TREADMILL KILOMETRES DATE',
                $maxTreadmillDate,
                round($maxTreadmillMeters / 1000, 3),
                '',
                '',
                '',
            ], ',', '"', '\\');
        }

        fclose($csvHandle);
        echo "CSV export complete: $outputPath\n";
    } catch (Exception $e) {
        echo "Error exporting measurements to CSV: " . $e->getMessage() . "\n";
        exit(1);
    }
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

    return [
        'steps' => $steps,
        'distance_meters' => $distanceMeters,
    ];
}
