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
    echo "  php google_fit_connector.php auth              - Run automated authorization flow\n";
    echo "  php google_fit_connector.php token-health      - Check token status and refresh health\n";
    echo "  php google_fit_connector.php load [DATE]       - Load fitness data (DATE format: YYYY-MM-DD; if omitted, uses day after latest DB row)\n";
    echo "  php google_fit_connector.php csv [OUTPUT_PATH] - Export measurements table to CSV\n";
    exit(1);
}

$command = $argv[1];
$defaultTimezone = resolveConfiguredTimezone($config);
$date = isset($argv[2]) ? $argv[2] : null;

if ($command === 'auth') {
    handleAuthorization($config);
} elseif ($command === 'token-health') {
    printTokenHealth($config);
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
    echo "  php google_fit_connector.php auth              - Run automated authorization flow\n";
    echo "  php google_fit_connector.php token CODE        - Exchange code for token\n";
    echo "  php google_fit_connector.php token-health      - Check token status and refresh health\n";
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
function buildAuthorizationUrl($config) {
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/fitness.activity.read https://www.googleapis.com/auth/fitness.body.read https://www.googleapis.com/auth/fitness.location.read',
        'access_type' => 'offline',
        'prompt' => 'consent',
    ]);
}

/**
 * Run authorization flow, preferring automated local callback capture
 */
function handleAuthorization($config, $attemptAutomation = true) {
    $authUrl = buildAuthorizationUrl($config);

    if ($attemptAutomation && attemptAutomatedAuthorization($config, $authUrl)) {
        return;
    }

    echo "Please visit this URL to authorize:\n\n";
    echo $authUrl . "\n\n";
    echo "After authorization, you'll be redirected. Copy the 'code' parameter from the URL.\n";
    echo "Then run: php google_fit_connector.php token YOUR_AUTH_CODE\n";
}

/**
 * Attempt end-to-end automated OAuth flow
 */
function attemptAutomatedAuthorization($config, $authUrl) {
    $authCode = captureAuthorizationCodeFromLocalCallback($config['redirect_uri'], $authUrl);

    if ($authCode === null) {
        return false;
    }

    echo "Authorization code captured. Exchanging token...\n";
    exchangeTokenFromCode($config, $authCode);
    return true;
}

/**
 * Open URL in default browser
 */
function openUrlInDefaultBrowser($url) {
    $family = PHP_OS_FAMILY;

    if ($family === 'Windows') {
        $command = 'cmd /c start "" "' . str_replace('"', '\\"', $url) . '"';
    } elseif ($family === 'Darwin') {
        $command = 'open ' . escapeshellarg($url);
    } else {
        $command = 'xdg-open ' . escapeshellarg($url);
    }

    @exec($command, $output, $exitCode);
    return $exitCode === 0;
}

/**
 * Send minimal HTTP response to browser
 */
function writeOAuthBrowserResponse($connection, $statusCode, $body) {
    $statusText = $statusCode === 200 ? 'OK' : 'Bad Request';
    $response = "HTTP/1.1 {$statusCode} {$statusText}\r\n";
    $response .= "Content-Type: text/html; charset=UTF-8\r\n";
    $response .= "Content-Length: " . strlen($body) . "\r\n";
    $response .= "Connection: close\r\n\r\n";
    $response .= $body;
    fwrite($connection, $response);
}

/**
 * Listen on redirect URI and capture OAuth code automatically
 */
function captureAuthorizationCodeFromLocalCallback($redirectUri, $authUrl, $timeoutSeconds = 180) {
    if (php_sapi_name() !== 'cli') {
        return null;
    }

    $parsed = parse_url($redirectUri);
    if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
        return null;
    }

    $scheme = strtolower($parsed['scheme']);
    $host = $parsed['host'];
    $port = isset($parsed['port']) ? (int)$parsed['port'] : 80;
    $path = $parsed['path'] ?? '/';

    if ($scheme !== 'http') {
        echo "Automated authorization requires an http redirect_uri. Current: {$redirectUri}\n";
        return null;
    }

    if ($host !== 'localhost' && $host !== '127.0.0.1') {
        echo "Automated authorization requires redirect_uri host localhost or 127.0.0.1. Current: {$host}\n";
        return null;
    }

    $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
    if ($server === false) {
        echo "Unable to start local callback listener on {$host}:{$port} ({$errstr}).\n";
        return null;
    }

    stream_set_blocking($server, false);

    echo "Listening for OAuth callback on {$host}:{$port}{$path}...\n";
    if (openUrlInDefaultBrowser($authUrl)) {
        echo "Opened browser for Google authorization.\n";
    } else {
        echo "Could not open browser automatically. Open this URL manually:\n{$authUrl}\n";
    }

    $deadline = time() + $timeoutSeconds;
    $authCode = null;
    $oauthError = null;
    $oauthDescription = null;

    while (time() < $deadline) {
        $read = [$server];
        $write = null;
        $except = null;
        $remaining = $deadline - time();

        $ready = @stream_select($read, $write, $except, $remaining, 0);
        if ($ready === false) {
            break;
        }

        if ($ready === 0) {
            continue;
        }

        $connection = @stream_socket_accept($server, 1);
        if ($connection === false) {
            continue;
        }

        $requestLine = fgets($connection);
        if ($requestLine === false) {
            fclose($connection);
            continue;
        }

        while (($headerLine = fgets($connection)) !== false) {
            if (rtrim($headerLine) === '') {
                break;
            }
        }

        $requestTarget = '';
        if (preg_match('#^[A-Z]+\s+([^\s]+)\s+HTTP/#', trim($requestLine), $matches)) {
            $requestTarget = $matches[1];
        }

        $requestParts = parse_url($requestTarget);
        $requestPath = $requestParts['path'] ?? '/';
        $query = [];
        parse_str($requestParts['query'] ?? '', $query);

        if ($requestPath !== $path) {
            writeOAuthBrowserResponse($connection, 400, '<h2>Invalid callback path.</h2>');
            fclose($connection);
            continue;
        }

        if (isset($query['error'])) {
            $oauthError = $query['error'];
            $oauthDescription = $query['error_description'] ?? null;
            writeOAuthBrowserResponse($connection, 400, '<h2>Authorization failed.</h2><p>You can close this tab.</p>');
            fclose($connection);
            break;
        }

        if (isset($query['code']) && $query['code'] !== '') {
            $authCode = $query['code'];
            writeOAuthBrowserResponse($connection, 200, '<h2>Authorization successful.</h2><p>You can close this tab.</p>');
            fclose($connection);
            break;
        }

        writeOAuthBrowserResponse($connection, 400, '<h2>Authorization code was not provided.</h2>');
        fclose($connection);
    }

    fclose($server);

    if ($oauthError !== null) {
        $description = $oauthDescription ? " ({$oauthDescription})" : '';
        echo "Authorization failed: {$oauthError}{$description}\n";
        return null;
    }

    if ($authCode === null) {
        echo "Timed out waiting for OAuth callback after {$timeoutSeconds} seconds.\n";
        return null;
    }

    return $authCode;
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
 * Request refreshed access token from Google OAuth endpoint
 */
function requestTokenRefreshResponse($config, $refreshToken) {
    $ch = curl_init('https://oauth2.googleapis.com/token');

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
    ]));

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

    $responseData = null;
    if (is_string($response) && $response !== '') {
        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            $responseData = $decoded;
        }
    }

    return [
        'success' => (!$curlError && $httpCode === 200 && is_array($responseData)),
        'http_code' => $httpCode,
        'curl_error' => $curlError ?: null,
        'raw_response' => $response,
        'data' => $responseData,
        'oauth_error' => $responseData['error'] ?? null,
        'oauth_error_description' => $responseData['error_description'] ?? null,
    ];
}

/**
 * Refresh the access token using the refresh token
 */
function refreshAccessToken($config, $tokenData) {
    if (!isset($tokenData['refresh_token'])) {
        echo "Error: Refresh token not available.\n";
        exit(1);
    }

    $refreshResult = requestTokenRefreshResponse($config, $tokenData['refresh_token']);
    $response = $refreshResult['raw_response'];
    $curlError = $refreshResult['curl_error'];
    $httpCode = $refreshResult['http_code'];

    if ($curlError) {
        echo "cURL Error during token refresh: $curlError\n";
        exit(1);
    }

    if ($httpCode !== 200) {
        $oauthError = $refreshResult['oauth_error'];
        $oauthDescription = $refreshResult['oauth_error_description'];

        if ($oauthError === 'invalid_grant') {
            if (file_exists($config['token_file'])) {
                @unlink($config['token_file']);
            }

            echo "Error: Refresh token is no longer valid ({$oauthDescription}).\n";
            echo "Starting re-authorization flow automatically...\n\n";

            if (attemptAutomatedAuthorization($config, buildAuthorizationUrl($config))) {
                echo "Re-authorization complete. Continuing with the original request.\n";
                return;
            }

            handleAuthorization($config, false);
            exit(1);
        }

        echo "Error refreshing token (HTTP $httpCode):\n";
        echo "Response: " . $response . "\n";
        exit(1);
    }

    $newTokenData = $refreshResult['data'];
    if (!is_array($newTokenData)) {
        echo "Error: Invalid token refresh response payload.\n";
        exit(1);
    }
    
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
 * Print token file status and non-mutating refresh health check
 */
function printTokenHealth($config) {
    $tokenFile = $config['token_file'];

    echo "Token file: {$tokenFile}\n";
    if (!file_exists($tokenFile)) {
        echo "Status: MISSING\n";
        exit(1);
    }

    $tokenRaw = file_get_contents($tokenFile);
    $tokenData = json_decode($tokenRaw, true);
    if (!is_array($tokenData)) {
        echo "Status: INVALID JSON\n";
        exit(1);
    }

    $hasAccessToken = isset($tokenData['access_token']) && $tokenData['access_token'] !== '';
    $hasRefreshToken = isset($tokenData['refresh_token']) && $tokenData['refresh_token'] !== '';
    $expiresAt = isset($tokenData['expires_at']) ? (int)$tokenData['expires_at'] : 0;
    $now = time();
    $secondsRemaining = $expiresAt - $now;

    echo "Access token: " . ($hasAccessToken ? 'PRESENT' : 'MISSING') . "\n";
    echo "Refresh token: " . ($hasRefreshToken ? 'PRESENT' : 'MISSING') . "\n";

    if ($expiresAt > 0) {
        $expiryText = date('Y-m-d H:i:s', $expiresAt);
        echo "Expires at (local): {$expiryText}\n";
        echo "Seconds remaining: {$secondsRemaining}\n";
        if ($secondsRemaining <= 0) {
            echo "Expiry status: EXPIRED\n";
        } elseif ($secondsRemaining <= 300) {
            echo "Expiry status: EXPIRING_SOON\n";
        } else {
            echo "Expiry status: VALID\n";
        }
    } else {
        echo "Expiry status: UNKNOWN (missing expires_at)\n";
    }

    if (!$hasRefreshToken) {
        echo "Refresh health: SKIPPED (no refresh token)\n";
        exit(1);
    }

    $refreshResult = requestTokenRefreshResponse($config, $tokenData['refresh_token']);
    if ($refreshResult['success']) {
        $nextExpiresIn = $refreshResult['data']['expires_in'] ?? null;
        echo "Refresh health: OK\n";
        if ($nextExpiresIn !== null) {
            echo "Refresh test expires_in: {$nextExpiresIn}\n";
        }
        echo "Note: Health check does not write token file.\n";
        return;
    }

    if ($refreshResult['curl_error']) {
        echo "Refresh health: FAILED (cURL error: {$refreshResult['curl_error']})\n";
        exit(1);
    }

    if ($refreshResult['oauth_error']) {
        $description = $refreshResult['oauth_error_description'];
        if ($description) {
            echo "Refresh health: FAILED ({$refreshResult['oauth_error']}: {$description})\n";
        } else {
            echo "Refresh health: FAILED ({$refreshResult['oauth_error']})\n";
        }
    } else {
        echo "Refresh health: FAILED (HTTP {$refreshResult['http_code']})\n";
    }

    exit(1);
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

    if (!is_array($tokenData)) {
        echo "Error: Token file is invalid JSON: " . $config['token_file'] . "\n";
        echo "Please re-authorize: php google_fit_connector.php auth\n";
        exit(1);
    }

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

        $measurements = buildMeasurementsFromAggregateData($data, $timezone);
        if (!empty($measurements)) {
            $allMeasurements = array_merge($allMeasurements, $measurements);
        }

        echo "=== Google Fit Data ({$currentDate}) ===\n\n";
        printLoadOutput($data, $timezone);
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
function printLoadOutput($data, $timezone) {
    if (isset($data['bucket']) && !empty($data['bucket'])) {
        foreach ($data['bucket'] as $bucket) {
            $bucketStartTimestamp = (int)($bucket['startTimeMillis'] / 1000);
            $bucketDate = (new DateTime('@' . $bucketStartTimestamp))
                ->setTimezone($timezone)
                ->format('Y-m-d');
            echo "Date: " . $bucketDate . "\n";

            foreach ($bucket['dataset'] as $dataset) {
                $dataType = $dataset['dataSourceId'] ?? 'Unknown';
                $outputLabel = formatDataTypeLabel($dataType);

                if (!empty($dataset['point'])) {
                    foreach ($dataset['point'] as $point) {
                        foreach ($point['value'] as $value) {
                            if (isset($value['intVal'])) {
                                $intValue = $value['intVal'];
                                if (strpos($dataType, 'distance.delta') !== false) {
                                    echo "  " . $outputLabel . ": " . round((float)$intValue) . "\n";
                                } else {
                                    echo "  " . $outputLabel . ": " . $intValue . "\n";
                                }
                            } elseif (isset($value['fpVal'])) {
                                $fpValue = $value['fpVal'];
                                if (strpos($dataType, 'distance.delta') !== false) {
                                    echo "  " . $outputLabel . ": " . round($fpValue) . "\n";
                                } else {
                                    echo "  " . $outputLabel . ": " . round($fpValue, 2) . "\n";
                                }
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
function buildMeasurementsFromAggregateData($data, $timezone) {
    $measurements = [];

    if (isset($data['bucket']) && !empty($data['bucket'])) {
        foreach ($data['bucket'] as $bucket) {
            $bucketStartTimestamp = (int)($bucket['startTimeMillis'] / 1000);
            $measurementDate = (new DateTime('@' . $bucketStartTimestamp))
                ->setTimezone($timezone)
                ->format('Y-m-d 00:00:00');

            $bucketTotals = extractDailyTotals(['bucket' => [$bucket]]);

            $measurements[] = [
                'date' => $measurementDate,
                'meters' => (int)round($bucketTotals['distance_meters']),
                'steps' => (int)$bucketTotals['steps'],
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

        $stmt = $pdo->prepare('INSERT INTO measurements (`date`, `meters`, `steps`) VALUES (:date, :meters, :steps)');

        foreach ($measurements as $measurement) {
            $stmt->execute([
                ':date' => $measurement['date'],
                ':meters' => (int)$measurement['meters'],
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
 * Export measurements table to CSV
 */
function exportMeasurementsToCsv($config, $outputPath = null) {
    if ($outputPath === null) {
        $outputPath = __DIR__ . '/measurements_export.csv';
    }

    try {
        $pdo = getDatabaseConnection($config);
        $stmt = $pdo->query('SELECT `date`, `steps`, `meters` FROM measurements ORDER BY `date` ASC');
        $rows = $stmt->fetchAll();

        $csvHandle = fopen($outputPath, 'w');
        if ($csvHandle === false) {
            echo "Unable to write CSV file to: $outputPath\n";
            exit(1);
        }

        fputcsv($csvHandle, ['date', 'steps', 'kilometres'], ',', '"', '\\');

        $weekSteps = 0;
        $weekMeters = 0;
        $monthSteps = 0;
        $monthMeters = 0;
        $yearSteps = 0;
        $yearMeters = 0;
        $maxSteps = null;
        $maxStepsDate = null;
        $maxMeters = null;
        $maxMetersDate = null;

        $rowCount = count($rows);
        for ($i = 0; $i < $rowCount; $i++) {
            $row = $rows[$i];
            $dateObj = new DateTime($row['date']);
            $weekKey = $dateObj->format('o-\\WW');
            $monthKey = $dateObj->format('Y-m');
            $yearKey = $dateObj->format('Y');

            $steps = (int)$row['steps'];
            $meters = (int)$row['meters'];
            $kilometres = round($meters / 1000, 3);

            if ($maxSteps === null || $steps > $maxSteps) {
                $maxSteps = $steps;
                $maxStepsDate = $dateObj->format('Y-m-d');
            }

            if ($maxMeters === null || $meters > $maxMeters) {
                $maxMeters = $meters;
                $maxMetersDate = $dateObj->format('Y-m-d');
            }

            $weekSteps += $steps;
            $weekMeters += $meters;
            $monthSteps += $steps;
            $monthMeters += $meters;
            $yearSteps += $steps;
            $yearMeters += $meters;

            fputcsv($csvHandle, [
                $row['date'],
                number_format($steps),
                $kilometres,
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
                    'WEEK TOTAL ' . $weekKey,
                    number_format($weekSteps),
                    round($weekMeters / 1000, 3),
                ], ',', '"', '\\');
                $weekSteps = 0;
                $weekMeters = 0;
            }

            if ($isMonthEnd) {
                fputcsv($csvHandle, [
                    'MONTH TOTAL ' . $monthKey,
                    number_format($monthSteps),
                    round($monthMeters / 1000, 3),
                ], ',', '"', '\\');
                $monthSteps = 0;
                $monthMeters = 0;
            }

            if ($isYearEnd) {
                fputcsv($csvHandle, [
                    'YEAR TOTAL ' . $yearKey,
                    number_format($yearSteps),
                    round($yearMeters / 1000, 3),
                ], ',', '"', '\\');
                $yearSteps = 0;
                $yearMeters = 0;
            }
        }

        if ($maxSteps !== null) {
            fputcsv($csvHandle, [
                'MAX STEPS DATE',
                $maxStepsDate,
                number_format($maxSteps),
            ], ',', '"', '\\');
        }

        if ($maxMeters !== null) {
            fputcsv($csvHandle, [
                'MAX KILOMETRES DATE',
                $maxMetersDate,
                round($maxMeters / 1000, 3),
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
