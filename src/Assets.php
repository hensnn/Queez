<?php
declare(strict_types=1);

/**
 * Otisk verze webových souborů (JS, CSS, HTML stránky). Server ho posílá ve stavu hry
 * a otevřená stránka se po aktualizaci aplikace sama načte znovu, takže hráči a moderátor
 * nezůstanou na starém JavaScriptu z mezipaměti prohlížeče.
 */
final class Assets
{
    private static ?string $build = null;

    public static function build(): string
    {
        if (self::$build !== null) {
            return self::$build;
        }
        $pub = dirname(__DIR__) . '/public';
        $files = array_merge(
            glob($pub . '/assets/*.js') ?: [],
            glob($pub . '/assets/*.css') ?: [],
            glob($pub . '/{play,host,admin}/index.html', GLOB_BRACE) ?: []
        );
        $t = 0;
        $size = 0;
        foreach ($files as $f) {
            $t = max($t, (int)filemtime($f));
            $size += (int)filesize($f);
        }
        return self::$build = dechex($t) . '-' . dechex($size);
    }
}
