<?php
declare(strict_types=1);

/** Výchozí data aplikace. */
final class Defaults
{
    /** Kategorie pro generování otázek (spravují se v Nastavení → Gemini). */
    public const CATEGORIES = [
        'Všeobecné znalosti', 'Zeměpis', 'Vlajky a hlavní města', 'Cestování a památky',
        'Dějepis', 'České dějiny', 'Dějiny 20. století', 'Slavné osobnosti',
        'Věda a příroda', 'Zvířata', 'Rostliny a příroda', 'Lidské tělo a zdraví', 'Kosmos a astronomie',
        'Technika a vynálezy', 'Počítače a internet', 'Matematika a logika',
        'Film', 'Seriály', 'Hudba', 'Česká hudba', 'Literatura', 'Pohádky a komiksy',
        'Výtvarné umění', 'Divadlo a tanec', 'Celebrity',
        'Jídlo a vaření', 'Pivo a nápoje', 'Sport', 'Fotbal', 'Hokej', 'Olympijské hry',
        'Auta a doprava', 'Videohry a deskovky', 'Móda a design',
        'Čeština a jazyk', 'Rčení a přísloví', 'Mytologie a náboženství', 'Politika a státy',
        'Ekonomika a peníze', 'Reklamy a značky', 'Svátky a tradice', 'Dětské otázky',
    ];

    /** Doplní chybějící kategorie (existující nemění). Vrací počet nově přidaných. */
    public static function seedCategories(): int
    {
        $added = 0;
        $sort = (int)Db::val('SELECT COALESCE(MAX(sort),0) FROM categories');
        foreach (self::CATEGORIES as $name) {
            $sort++;
            $added += Db::exec('INSERT OR IGNORE INTO categories(name, sort) VALUES(?, ?)', [$name, $sort]);
        }
        return $added;
    }
}
