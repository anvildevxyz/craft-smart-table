<?php

$dsn = getenv('CRAFT_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=craft_smarttable_test';

return [
    'dsn' => $dsn,
    'user' => getenv('CRAFT_DB_USER') ?: 'root',
    'password' => getenv('CRAFT_DB_PASSWORD') ?: 'root',
    // Postgres needs a real schema for column introspection; an empty default
    // makes Yii build `d.nspname = ` (no value) and the suite dies at boot with
    // a "syntax error at or near ORDER" before any test runs. MySQL ignores it.
    'schema' => getenv('CRAFT_DB_SCHEMA') ?: (str_starts_with($dsn, 'pgsql') ? 'public' : ''),
    'tablePrefix' => getenv('CRAFT_DB_TABLE_PREFIX') ?: '',
    // utf8mb4 is MySQL-only; Postgres rejects it as an invalid client_encoding.
    // Use the driver-appropriate charset so the suite runs on both.
    'charset' => str_starts_with($dsn, 'pgsql') ? 'utf8' : 'utf8mb4',
];
