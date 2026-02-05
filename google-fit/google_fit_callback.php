<?php

/**
 * Google Fit OAuth Callback Handler
 * 
 * This script runs a simple PHP web server on localhost:8000
 * to capture the authorization code from Google
 * 
 * Usage:
 * php google_fit_callback.php
 * 
 * Then visit the auth URL from google_fit_connector.php
 */

// Check if we have the authorization code
if (isset($_GET['code'])) {
    $authCode = $_GET['code'];
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Google Fit Authorization - Success</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; }
            .success { color: green; }
            .code { 
                background: #f5f5f5; 
                padding: 15px; 
                border-radius: 5px;
                word-break: break-all;
                margin: 20px 0;
                font-family: monospace;
            }
        </style>
    </head>
    <body>
        <h1 class="success">✓ Authorization Successful!</h1>
        <p>Copy the code below and run:</p>
        <div class="code"><?php echo htmlspecialchars($authCode); ?></div>
        <p><code>php tools/google_fit_connector.php token <?php echo htmlspecialchars($authCode); ?></code></p>
    </body>
    </html>
    <?php
    exit;
}

// Check for errors
if (isset($_GET['error'])) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Google Fit Authorization - Error</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; }
            .error { color: red; }
        </style>
    </head>
    <body>
        <h1 class="error">✗ Authorization Failed</h1>
        <p><strong>Error:</strong> <?php echo htmlspecialchars($_GET['error']); ?></p>
        <p><strong>Description:</strong> <?php echo htmlspecialchars($_GET['error_description'] ?? 'No description provided'); ?></p>
    </body>
    </html>
    <?php
    exit;
}

// No code or error - show startup instructions
?>
<!DOCTYPE html>
<html>
<head>
    <title>Google Fit Callback Server</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .info { background: #e3f2fd; padding: 15px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>Google Fit Callback Server Ready</h1>
    <div class="info">
        <p>This server is listening on <code>localhost:8000</code></p>
        <p>Run the auth command:</p>
        <pre>php tools/google_fit_connector.php auth</pre>
        <p>Then visit the URL shown and authorize your app.</p>
        <p>You will be redirected here with your authorization code.</p>
    </div>
</body>
</html>
