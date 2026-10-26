<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

$configFile = ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Chybí config.php – zkopírujte config.example.php na config.php a upravte ho.');
}
$GLOBALS['CFG'] = require $configFile;

function cfg(string $key, $default = null)
{
    return $GLOBALS['CFG'][$key] ?? $default;
}

spl_autoload_register(function (string $class): void {
    $f = __DIR__ . '/' . $class . '.php';
    if (is_file($f)) {
        require $f;
    }
});

date_default_timezone_set((string)cfg('timezone', 'Europe/Prague'));

// Logování chyb do souboru mimo webroot, nikdy ne na výstup
$logFile = (string)cfg('log_file', ROOT . '/logs/error.log');
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0775, true);
}
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $logFile);
error_reporting(E_ALL);

header('X-Content-Type-Options: nosniff');

function now_ms(): int
{
    return (int)round(microtime(true) * 1000);
}
