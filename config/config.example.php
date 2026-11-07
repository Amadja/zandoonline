<?php
declare(strict_types=1);

/*
 * Copy this file to config.php and update the values.
 * Never commit config.php or publish database credentials.
 */
return [
    'app_name' => 'Zandoonline',
    'base_url' => 'http://localhost/zandoonline/public',
    'environment' => 'development',
    'debug' => true,

    'database' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'zandoonline',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
];
