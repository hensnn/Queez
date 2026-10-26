<?php
// GET ?backup=1 = stažení zálohy DB; POST {action:"delete_old_games", days:N}
require __DIR__ . '/../../src/bootstrap.php';

if (Http::method() === 'GET' && isset($_GET['backup'])) {
    Auth::require();
    $tmp = (string)cfg('data_dir', ROOT . '/data') . '/backup-' . bin2hex(random_bytes(6)) . '.sqlite';
    Db::pdo()->exec("VACUUM INTO '" . str_replace("'", "''", $tmp) . "'");
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="kviz-zaloha-' . date('Ymd-His') . '.sqlite"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

Http::run(function () {
    Auth::require();
    if (Http::method() === 'POST' && !empty($_FILES['backup'])) {   // obnova ze zálohy (multipart)
        $f = $_FILES['backup'];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Soubor se nepodařilo nahrát (zkontrolujte limit velikosti uploadu).');
        }
        $safety = Db::restore($f['tmp_name']);
        return ['restored' => true, 'safety_copy' => basename($safety)];
    }
    $in = Http::input();
    if (($in['action'] ?? '') === 'delete_old_games') {
        $days = max(1, (int)($in['days'] ?? 30));
        $n = Db::exec('DELETE FROM games WHERE created_at < ?', [time() - $days * 86400]);
        return ['deleted' => $n];
    }
    throw new InvalidArgumentException('Neznámá akce.');
});
