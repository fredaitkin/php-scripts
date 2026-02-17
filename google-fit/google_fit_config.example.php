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
    // Optional: override timezone used for daily boundaries (PHP timezone identifier)
    'timezone' => 'America/New_York',
    // Optional: one-switch profile for distance behavior
    // 'distance_profile' => 'lifefitness', // API distance filtered to Life Fitness sources
    // 'distance_profile' => 'google_fit',  // steps_estimate with default step_length_meters
    // 'distance_profile' => 'custom',      // use keys below as-is (default)
    // Optional: force distance from matching source IDs (e.g. 'lifefitness')
    // 'preferred_distance_source_contains' => 'lifefitness',
    // Optional: include only matching distance source IDs (string or array)
    // 'distance_source_include_contains' => ['google', 'fitness'],
    // Optional: exclude matching distance source IDs (string or array)
    // 'distance_source_exclude_contains' => ['lifefitness'],
    // Optional: distance calculation mode: 'api' (default) or 'steps_estimate'
    // 'distance_mode' => 'steps_estimate',
    // Optional: meters per step used when distance_mode='steps_estimate'
    // 0.4315 gives ~1.05 mi for 3915 steps
    // 'step_length_meters' => 0.4315,
];
