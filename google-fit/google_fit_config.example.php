<?php

/**
 * Google Fit API Configuration
 * 
 * Copy this file to google_fit_config.php and update with your credentials
 */

return [
    'client_id' => 'YOUR_CLIENT_ID_HERE',
    'client_secret' => 'YOUR_CLIENT_SECRET_HERE',
    'redirect_uri' => 'http://localhost:8000/callback',
    'token_file' => __DIR__ . '/google_fit_token.json',
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'fitness',
        'username' => 'fitness_user',
        'password' => 'change_this_password',
    ],
    // Optional: override timezone used for daily boundaries (PHP timezone identifier)
    'timezone' => 'America/New_York',
];
