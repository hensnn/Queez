<?php
declare(strict_types=1);

final class Quiz
{
    public static function list(): array
    {
        return Db::all(
            'SELECT q.id, q.title, q.description, q.category_id, c.name AS category, q.updated_at,
                    (SELECT COUNT(*) FROM questions x WHERE x.quiz_id = q.id) AS questions_count
             FROM quizzes q LEFT JOIN categories c ON c.id = q.category_id
             ORDER BY q.updated_at DESC'
        );
    }

    public static function get(int $id): ?array
    {
        $q = Db::one('SELECT * FROM quizzes WHERE id=?', [$id]);
        if (!$q) {
            return null;
        }
        $questions = Db::all('SELECT * FROM questions WHERE quiz_id=? ORDER BY position', [$id]);
        $opts = [];
        foreach (Db::all(
            'SELECT o.question_id, o.text, o.is_correct FROM options o
             JOIN questions x ON x.id = o.question_id WHERE x.quiz_id=? ORDER BY o.question_id, o.position',
            [$id]
        ) as $o) {
            $opts[$o['question_id']][] = ['text' => $o['text'], 'correct' => (bool)$o['is_correct']];
        }
        $outQ = [];
        foreach ($questions as $x) {
            $outQ[] = [
                'id'          => (int)$x['id'],
                'type'        => $x['type'],
                'text'        => $x['text'],
                'image'       => $x['image'],
                'time_limit'  => $x['time_limit'] === null ? null : (int)$x['time_limit'],
                'points'      => $x['points'] === null ? null : (int)$x['points'],
                'explanation' => $x['explanation'] ?? '',
                'source'      => $x['source'],
                'options'     => $opts[$x['id']] ?? [],
            ];
        }
        return [
            'id'          => (int)$q['id'],
            'title'       => $q['title'],
            'description' => $q['description'],
            'category_id' => $q['category_id'] === null ? null : (int)$q['category_id'],
            'settings'    => json_decode($q['settings_json'], true) ?: new stdClass(),
            'questions'   => $outQ,
        ];
    }

    /** Uloží celý kvíz (i s otázkami) v jedné transakci. Vrací id. */
    public static function save(array $in): int
    {
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 120) {
            throw new InvalidArgumentException('Název kvízu je povinný (nejvýše 120 znaků).');
        }
        $desc = mb_substr(trim((string)($in['description'] ?? '')), 0, 500);
        $cat = (isset($in['category_id']) && $in['category_id'] !== '' && $in['category_id'] !== null)
            ? (int)$in['category_id'] : null;
        if ($cat !== null && !Db::one('SELECT 1 FROM categories WHERE id=?', [$cat])) {
            $cat = null;
        }
        $settings = Settings::cleanQuiz($in['settings'] ?? []);
        $questions = $in['questions'] ?? [];
        if (!is_array($questions) || count($questions) > 200) {
            throw new InvalidArgumentException('Kvíz může mít nejvýše 200 otázek.');
        }
        $clean = [];
        foreach (array_values($questions) as $i => $q) {
            $clean[] = self::cleanQuestion(is_array($q) ? $q : [], $i + 1);
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $t = time();
            $id = (int)($in['id'] ?? 0);
            if ($id && Db::one('SELECT 1 FROM quizzes WHERE id=?', [$id])) {
                Db::exec(
                    'UPDATE quizzes SET title=?, description=?, category_id=?, settings_json=?, updated_at=? WHERE id=?',
                    [$title, $desc, $cat, json_encode((object)$settings), $t, $id]
                );
                Db::exec('DELETE FROM questions WHERE quiz_id=?', [$id]);
            } else {
                Db::exec(
                    'INSERT INTO quizzes(title,description,category_id,settings_json,created_at,updated_at) VALUES(?,?,?,?,?,?)',
                    [$title, $desc, $cat, json_encode((object)$settings), $t, $t]
                );
                $id = Db::lastId();
            }
            foreach ($clean as $pos => $q) {
                Db::exec(
                    'INSERT INTO questions(quiz_id,position,type,text,image,time_limit,points,explanation,source,category_id,created_at)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                    [$id, $pos, $q['type'], $q['text'], $q['image'], $q['time_limit'], $q['points'],
                     $q['explanation'], $q['source'], $cat, $t]
                );
                $qid = Db::lastId();
                foreach ($q['options'] as $op => $o) {
                    Db::exec(
                        'INSERT INTO options(question_id,position,text,is_correct) VALUES(?,?,?,?)',
                        [$qid, $op, $o['text'], $o['correct'] ? 1 : 0]
                    );
                }
            }
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Ověří a upraví jednu otázku (použije i Gemini generátor). Při chybě vyhodí InvalidArgumentException. */
    public static function cleanQuestion(array $q, int $n): array
    {
        $err = fn(string $m) => new InvalidArgumentException("Otázka {$n}: {$m}");

        $type = (string)($q['type'] ?? 'choice');
        if (!in_array($type, ['choice', 'truefalse', 'poll'], true)) {
            throw $err('neznámý typ.');
        }
        $text = trim((string)($q['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 500) {
            throw $err('text je povinný (nejvýše 500 znaků).');
        }
        $image = $q['image'] ?? null;
        if ($image === '' || $image === null) {
            $image = null;
        } elseif (!Media::validName((string)$image)) {
            throw $err('neplatný obrázek.');
        }
        $tl = $q['time_limit'] ?? null;
        $tl = ($tl === null || $tl === '') ? null : (int)$tl;
        if ($tl !== null && ($tl < 0 || $tl > 300)) {
            throw $err('časový limit musí být 0–300 s.');
        }
        $pts = $q['points'] ?? null;
        $pts = ($pts === null || $pts === '' || $type === 'poll') ? null : (int)$pts;
        if ($pts !== null && ($pts < 0 || $pts > 10000)) {
            throw $err('body musí být 0–10000.');
        }
        $expl = trim((string)($q['explanation'] ?? ''));
        $expl = $expl === '' ? null : mb_substr($expl, 0, 500);

        $in = is_array($q['options'] ?? null) ? array_values($q['options']) : [];
        $opts = [];
        foreach ($in as $o) {
            $opts[] = ['text' => trim((string)($o['text'] ?? '')), 'correct' => !empty($o['correct'])];
        }
        if ($type === 'truefalse') {
            $opts = array_slice($opts, 0, 2);
            if (count($opts) !== 2) {
                $opts = [['text' => 'Pravda', 'correct' => false], ['text' => 'Nepravda', 'correct' => false]];
            }
        }
        if (count($opts) < 2 || count($opts) > 6) {
            throw $err('musí mít 2 až 6 odpovědí.');
        }
        foreach ($opts as $o) {
            if ($o['text'] === '' || mb_strlen($o['text']) > 200) {
                throw $err('každá odpověď musí mít text (nejvýše 200 znaků).');
            }
        }
        $correct = count(array_filter($opts, fn($o) => $o['correct']));
        if ($type === 'poll') {
            foreach ($opts as &$o) {
                $o['correct'] = false;
            }
            unset($o);
        } elseif ($type === 'truefalse' && $correct !== 1) {
            throw $err('u pravda/nepravda označte právě jednu správnou odpověď.');
        } elseif ($correct < 1) {
            throw $err('označte aspoň jednu správnou odpověď.');
        }
        $source = ($q['source'] ?? 'manual') === 'gemini' ? 'gemini' : 'manual';
        return ['type' => $type, 'text' => $text, 'image' => $image, 'time_limit' => $tl, 'points' => $pts,
                'explanation' => $expl, 'options' => $opts, 'source' => $source];
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM quizzes WHERE id=?', [$id]);
    }

    public static function duplicate(int $id): int
    {
        $q = self::get($id);
        if (!$q) {
            throw new InvalidArgumentException('Kvíz neexistuje.');
        }
        unset($q['id']);
        $q['title'] = mb_substr($q['title'], 0, 110) . ' (kopie)';
        return self::save($q);
    }

    public static function export(int $id): ?array
    {
        $q = self::get($id);
        if (!$q) {
            return null;
        }
        $cat = $q['category_id'] ? Db::val('SELECT name FROM categories WHERE id=?', [$q['category_id']]) : null;
        foreach ($q['questions'] as &$x) {
            unset($x['id']);
        }
        return [
            'format'      => 'kviz-v1',
            'title'       => $q['title'],
            'description' => $q['description'],
            'category'    => $cat,
            'settings'    => $q['settings'],
            'questions'   => $q['questions'],   // obrázky se neexportují, jen odkazy na soubory
        ];
    }

    public static function import(array $d): int
    {
        if (($d['format'] ?? '') !== 'kviz-v1') {
            throw new InvalidArgumentException('Soubor není export kvízu (chybí format „kviz-v1“).');
        }
        $catId = null;
        if (!empty($d['category'])) {
            $catId = Db::val('SELECT id FROM categories WHERE name=?', [(string)$d['category']]);
            if (!$catId) {
                Db::exec('INSERT INTO categories(name,sort) VALUES(?,?)', [mb_substr((string)$d['category'], 0, 60), 99]);
                $catId = Db::lastId();
            }
        }
        $d['category_id'] = $catId ? (int)$catId : null;
        unset($d['id']);
        foreach ($d['questions'] ?? [] as $i => $q) {
            // obrázek z cizí instalace by nefungoval → vynecháme, pokud soubor u nás není
            if (!empty($q['image']) && !Media::path((string)$q['image'])) {
                $d['questions'][$i]['image'] = null;
            }
        }
        return self::save($d);
    }
}
