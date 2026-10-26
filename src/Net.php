<?php
declare(strict_types=1);

/** Práce s reverzní proxy (NPM → Caddy → PHP). */
final class Net
{
    private static function remote(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private static function trusted(string $ip): bool
    {
        foreach ((array)cfg('trusted_proxies', []) as $rule) {
            if (self::match($ip, (string)$rule)) {
                return true;
            }
        }
        return false;
    }

    private static function match(string $ip, string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            return $ip === $rule;
        }
        [$net, $bits] = explode('/', $rule, 2);
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = (int)$bits;
        $bytes = intdiv($bits, 8);
        if ($bytes && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    public static function clientIp(): string
    {
        $r = self::remote();
        if (self::trusted($r) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // první (původní klient) záznam; proxy řetěz je důvěryhodný
            $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return $r;
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return self::trusted(self::remote())
            && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
