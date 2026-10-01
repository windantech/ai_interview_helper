<?php

declare(strict_types=1);

return [
    'host'     => env('DB_HOST', 'localhost'),
    'port'     => (int) env('DB_PORT', 3306),
    'database' => env('DB_NAME', 'interview_copilot'),
    'username' => env('DB_USER', 'root'),
    'password' => env('DB_PASS', ''),
    'charset'  => 'utf8mb4',
    'collation'=> 'utf8mb4_unicode_ci',
];
