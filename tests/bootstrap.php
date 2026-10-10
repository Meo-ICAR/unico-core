<?php

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/Support/helpers.php';

// Le variabili CORE_TEST_* possono stare nel .env del pacchetto; quelle già nell'ambiente hanno la precedenza.
if (is_file(__DIR__.'/../.env')) {
    Dotenv\Dotenv::createUnsafeImmutable(__DIR__.'/..')->safeLoad();
}
