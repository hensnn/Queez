<?php
// GET = seznam; POST {action: add|rename|delete, id, name}
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    Auth::require();
    if (Http::method() === 'POST') {
        $in = Http::input();
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 60);
        $id = (int)($in['id'] ?? 0);
        switch ($in['action'] ?? '') {
            case 'add':
                if ($name === '') {
                    throw new InvalidArgumentException('Zadejte název kategorie.');
                }
                if (Db::one('SELECT 1 FROM categories WHERE name=?', [$name])) {
                    throw new InvalidArgumentException('Taková kategorie už existuje.');
                }
                Db::exec('INSERT INTO categories(name,sort) VALUES(?,?)',
                    [$name, (int)Db::val('SELECT COALESCE(MAX(sort),0)+1 FROM categories')]);
                break;
            case 'rename':
                if ($name === '') {
                    throw new InvalidArgumentException('Zadejte název kategorie.');
                }
                if (Db::one('SELECT 1 FROM categories WHERE name=? AND id<>?', [$name, $id])) {
                    throw new InvalidArgumentException('Taková kategorie už existuje.');
                }
                Db::exec('UPDATE categories SET name=? WHERE id=?', [$name, $id]);
                break;
            case 'delete':
                Db::exec('DELETE FROM categories WHERE id=?', [$id]);
                break;
            case 'seed':   // doplní doporučené kategorie, které chybí
                $n = Defaults::seedCategories();
                return ['categories' => Db::all('SELECT id,name FROM categories ORDER BY sort,name'), 'added' => $n];
            default:
                throw new InvalidArgumentException('Neznámá akce.');
        }
    }
    return ['categories' => Db::all('SELECT id,name FROM categories ORDER BY sort,name')];
});
