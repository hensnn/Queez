<?php
declare(strict_types=1);

/** Jednoduchý rate limit s pevným oknem, uložený v SQLite. */
final class RateLimit
{
    private static function window(int $seconds): int
    {
        return intdiv(time(), $seconds) * $seconds;
    }

    public static function count(string $bucket, int $seconds): int
    {
        return (int)Db::val(
            'SELECT cnt FROM rate_limits WHERE bucket=? AND window_start=?',
            [$bucket, self::window($seconds)]
        );
    }

    /** Přičte 1 a vrátí nový počet. */
    public static function hit(string $bucket, int $seconds): int
    {
        $w = self::window($seconds);
        Db::exec(
            'INSERT INTO rate_limits(bucket,window_start,cnt) VALUES(?,?,1)
             ON CONFLICT(bucket,window_start) DO UPDATE SET cnt=cnt+1',
            [$bucket, $w]
        );
        if (random_int(1, 50) === 1) {
            Db::exec('DELETE FROM rate_limits WHERE window_start < ?', [time() - 7200]);
        }
        return self::count($bucket, $seconds);
    }
}
