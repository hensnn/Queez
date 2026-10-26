<?php
declare(strict_types=1);

final class Http
{
    public static function json($data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function fail(int $code, string $error, string $message): never
    {
        self::json(['error' => $error, 'message' => $message], $code);
    }

    /** Vstup: JSON tělo, jinak $_POST. */
    public static function input(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                return $j;
            }
        }
        return $_POST;
    }

    public static function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    /** Spustí handler, chyby převede na JSON odpověď. */
    public static function run(callable $fn): never
    {
        try {
            $r = $fn();
            self::json($r ?? ['ok' => true]);
        } catch (InvalidArgumentException $e) {
            self::fail(422, 'invalid', $e->getMessage());
        } catch (Throwable $e) {
            error_log((string)$e);
            self::fail(500, 'server', 'Chyba serveru. Podrobnosti jsou v logu.');
        }
    }
}
