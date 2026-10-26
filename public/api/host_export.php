<?php
// GET ?pin=PIN&key=KLÍČ → CSV s výsledky hry (UTF-8 s BOM, oddělovač „;“ – otevře se rovnou v českém Excelu)
require __DIR__ . '/../../src/bootstrap.php';
$g = Game::requireHost(trim((string)($_GET['pin'] ?? '')), (string)($_GET['key'] ?? ''));
$gid = (int)$g['id'];

/** Ochrana proti vložení vzorce při otevření v tabulkovém editoru (=, +, -, @ na začátku buňky). */
function csv_cell($v): string
{
    $v = (string)$v;
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
        $v = "'" . $v;
    }
    return '"' . str_replace('"', '""', $v) . '"';
}

$qs = Db::all('SELECT idx, text FROM game_questions WHERE game_id=? ORDER BY idx', [$gid]);
$players = Db::all('SELECT id, name, score FROM players WHERE game_id=? AND kicked=0 ORDER BY score DESC, joined_at, id', [$gid]);
$pts = [];
foreach (Db::all('SELECT player_id, q_idx, points FROM responses WHERE game_id=?', [$gid]) as $r) {
    $pts[(int)$r['player_id']][(int)$r['q_idx']] = (int)$r['points'];
}

$file = 'vysledky-' . $g['pin'] . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Cache-Control: no-store');
echo "\xEF\xBB\xBF";
$head = ['Pořadí', 'Jméno', 'Celkem bodů'];
foreach ($qs as $q) {
    $head[] = ($q['idx'] + 1) . '. ' . mb_substr($q['text'], 0, 40);
}
echo implode(';', array_map('csv_cell', $head)), "\r\n";
$rank = 0;
$prev = null;
foreach ($players as $i => $p) {
    if ($prev === null || (int)$p['score'] !== $prev) {
        $rank = $i + 1;
    }
    $prev = (int)$p['score'];
    $row = [$rank, $p['name'], $p['score']];
    foreach ($qs as $q) {
        $row[] = $pts[(int)$p['id']][(int)$q['idx']] ?? 0;
    }
    echo implode(';', array_map('csv_cell', $row)), "\r\n";
}
