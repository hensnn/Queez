<?php
/**
 * Záloha databáze (konzistentní kopie i za běhu hry). Ponechá posledních N záloh.
 *   php tools/backup.php [cílová_složka] [počet_ponechaných]
 * Výchozí složka je data/backups. Hodí se do cronu (viz README).
 * Šifrovaný Gemini klíč (data/secrets/) se do zálohy záměrně nekopíruje.
 */
if (PHP_SAPI !== 'cli') {
    exit("Spouští se z příkazové řádky.\n");
}
require __DIR__ . '/../src/bootstrap.php';
$dir = $argv[1] ?? ((string)cfg('data_dir', ROOT . '/data') . '/backups');
$keep = max(1, (int)($argv[2] ?? 14));
if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
    exit("Nelze vytvořit složku $dir\n");
}
$file = $dir . '/kviz-' . date('Ymd-His') . '.sqlite';
Db::pdo()->exec("VACUUM INTO '" . str_replace("'", "''", $file) . "'");
echo "Záloha uložena: $file (" . round(filesize($file) / 1024) . " kB)\n";
$all = glob($dir . '/kviz-*.sqlite') ?: [];
sort($all);
foreach (array_slice($all, 0, max(0, count($all) - $keep)) as $old) {
    unlink($old);
    echo "Smazána stará záloha: " . basename($old) . "\n";
}
