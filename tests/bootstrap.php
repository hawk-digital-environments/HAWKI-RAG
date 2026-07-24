<?php

declare(strict_types=1);

/*
 * The application image wraps PHP and restores Compose variables before every
 * CLI invocation. PHPUnit's forced <env> values update getenv() and $_ENV, but
 * Laravel also reads $_SERVER. Mirror the isolated test selectors into all
 * sources before Composer or Laravel can initialize its environment repository.
 */
$testingEnvironmentVariables = [
    'APP_ENV',
    'APP_MAINTENANCE_DRIVER',
    'BCRYPT_ROUNDS',
    'CACHE_STORE',
    'DB_CONNECTION',
    'DB_DATABASE',
    'DB_URL',
    'DB_CACHE_CONNECTION',
    'DB_CACHE_LOCK_CONNECTION',
    'DB_QUEUE_CONNECTION',
    'MAIL_MAILER',
    'PULSE_ENABLED',
    'HAWKI_RAG_QUERY_ALL_DATASETS_BY_DEFAULT',
    'QUEUE_CONNECTION',
    'SESSION_CONNECTION',
    'SESSION_DRIVER',
    'TELESCOPE_ENABLED',
];

foreach ($testingEnvironmentVariables as $name) {
    $value = getenv($name);

    if ($value === false) {
        continue;
    }

    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require dirname(__DIR__).'/vendor/autoload.php';
