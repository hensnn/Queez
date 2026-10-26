<?php
declare(strict_types=1);

/**
 * Šifrované úložiště tajných hodnot (např. Gemini API klíč), oddělené od databáze.
 *
 *  data/secrets/key.bin      – náhodný 256bitový šifrovací klíč (vytvoří se při prvním použití, práva 0600)
 *  data/secrets/secrets.enc  – JSON se všemi hodnotami zašifrovaný AES-256-GCM
 *
 * Záloha databáze tedy tajné hodnoty neobsahuje. Hodnoty se nikdy nevracejí do prohlížeče,
 * ven jde jen nápověda (poslední 4 znaky).
 * Ochrana je „v klidu“: kdo získá obě složky z disku serveru, hodnoty přečte – proto leží mimo webroot
 * a mají omezená práva. Před kopírováním složky data/ jinam smažte složku secrets/.
 */
final class Secrets
{
    private const NAMES = ['gemini_api_key'];

    private static function dir(): string
    {
        $d = (string)cfg('data_dir', ROOT . '/data') . '/secrets';
        if (!is_dir($d)) {
            @mkdir($d, 0700, true);
        }
        return $d;
    }

    private static function key(): string
    {
        $f = self::dir() . '/key.bin';
        if (!is_file($f)) {
            $tmp = $f . '.' . bin2hex(random_bytes(4));
            file_put_contents($tmp, random_bytes(32), LOCK_EX);
            @chmod($tmp, 0600);
            rename($tmp, $f);
        }
        $k = (string)file_get_contents($f);
        if (strlen($k) !== 32) {
            throw new RuntimeException('Poškozený šifrovací klíč v data/secrets/key.bin.');
        }
        return $k;
    }

    private static function load(): array
    {
        $f = self::dir() . '/secrets.enc';
        if (!is_file($f)) {
            return [];
        }
        $raw = base64_decode((string)file_get_contents($f), true);
        if ($raw === false || strlen($raw) < 29) {
            return [];
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            error_log('Secrets: nelze dešifrovat secrets.enc (změněný klíč?).');
            return [];
        }
        $j = json_decode($plain, true);
        return is_array($j) ? $j : [];
    }

    private static function store(array $all): void
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(json_encode($all), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Šifrování selhalo (chybí rozšíření openssl?).');
        }
        $f = self::dir() . '/secrets.enc';
        $tmp = $f . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, base64_encode($iv . $tag . $cipher), LOCK_EX);
        @chmod($tmp, 0600);
        rename($tmp, $f);
    }

    private static function check(string $name): void
    {
        if (!in_array($name, self::NAMES, true)) {
            throw new InvalidArgumentException('Neznámá tajná hodnota.');
        }
    }

    public static function get(string $name): string
    {
        self::check($name);
        return (string)(self::load()[$name]['v'] ?? '');
    }

    public static function set(string $name, string $value): void
    {
        self::check($name);
        $all = self::load();
        $all[$name] = ['v' => $value, 'hint' => substr($value, -4), 't' => time()];
        self::store($all);
    }

    public static function delete(string $name): void
    {
        self::check($name);
        $all = self::load();
        unset($all[$name]);
        self::store($all);
    }

    /** Stav bez prozrazení hodnoty. */
    public static function status(string $name): array
    {
        self::check($name);
        $e = self::load()[$name] ?? null;
        return $e ? ['set' => true, 'hint' => (string)$e['hint'], 'saved_at' => (int)$e['t']]
                  : ['set' => false, 'hint' => '', 'saved_at' => 0];
    }
}
