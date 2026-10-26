<?php
declare(strict_types=1);

final class Auth
{
    private const LOGIN_WINDOW = 900;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = (string)cfg('data_dir', ROOT . '/data') . '/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        session_save_path($dir);
        session_name('kvizadm');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => Net::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.gc_maxlifetime', (string)((int)cfg('session_hours', 12) * 3600));
        // Debian vypíná úklid sessions v PHP (dělá ho cron jen pro systémovou složku) – naše složka se čistí sama
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_start();
    }

    /** Stav přihlášení pro načtení stránky (vrací CSRF token jen přihlášenému). */
    public static function status(): array
    {
        self::start();
        $ok = !empty($_SESSION['admin']) && self::fresh();
        $out = ['authenticated' => $ok, 'csrf' => $ok ? ($_SESSION['csrf'] ?? '') : ''];
        session_write_close();
        return $out;
    }

    private static function fresh(): bool
    {
        return (time() - (int)($_SESSION['t'] ?? 0)) < (int)cfg('session_hours', 12) * 3600;
    }

    public static function login(string $password): array
    {
        $ip = Net::clientIp();
        $bucket = 'login:' . $ip;
        $max = (int)cfg('login_max_attempts', 5);
        if (RateLimit::count($bucket, self::LOGIN_WINDOW) >= $max) {
            Http::fail(429, 'rate_limit', 'Příliš mnoho pokusů. Zkuste to za 15 minut.');
        }
        $hash = (string)cfg('admin_password_hash', '');
        if ($hash === '' || !password_verify($password, $hash)) {
            RateLimit::hit($bucket, self::LOGIN_WINDOW);
            usleep(600000);
            Http::fail(401, 'bad_password', 'Nesprávné heslo.');
        }
        self::start();
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['t'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        $csrf = $_SESSION['csrf'];
        session_write_close();
        return ['csrf' => $csrf];
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    /** Vyžaduje přihlášeného admina; u nečtecích požadavků i CSRF token. */
    public static function require(): void
    {
        self::start();
        if (empty($_SESSION['admin']) || !self::fresh()) {
            session_write_close();
            Http::fail(401, 'unauthorized', 'Přihlaste se.');
        }
        if (Http::method() !== 'GET') {
            $t = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $t)) {
                session_write_close();
                Http::fail(403, 'csrf', 'Neplatný bezpečnostní token, obnovte stránku.');
            }
        }
        $_SESSION['t'] = time();
        session_write_close();   // uvolní zámek session pro paralelní požadavky
    }
}
