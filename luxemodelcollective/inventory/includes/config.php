<?php
/**
 * Database connection configuration.
 * Fill in the values from your cPanel MySQL Databases page.
 */

return [
    // Usually 'localhost' on shared hosts
    'host'     => 'localhost',

    // Your database name (e.g., 'cpaneluser_furry')
    'database' => 'CHANGE_ME_DATABASE',

    // Your database user (e.g., 'cpaneluser_furryadmin')
    'username' => 'CHANGE_ME_USERNAME',

    // The password you set when creating the DB user
    'password' => 'CHANGE_ME_PASSWORD',

    // Should always be utf8mb4 for emoji and full Unicode support
    'charset'  => 'utf8mb4',
];
