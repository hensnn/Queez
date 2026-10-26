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

    /**
     * Nabídka podkategorií (upřesnění tématu) pro generování otázek, podle názvu kategorie.
     * Ve formuláři je vždy navíc možnost „Vlastní…“. U vlastních kategorií se nabídka nezobrazuje.
     */
    public const SUBCATEGORIES = [
        'Všeobecné znalosti' => ['Lehké hospodské otázky', 'Střední obtížnost', 'Zajímavosti a rekordy', 'Kdo, kde, kdy', 'Hádanky a logika', 'Slavná výročí'],
        'Zeměpis' => ['Evropa', 'Asie', 'Afrika', 'Amerika', 'Austrálie a Oceánie', 'Česká republika', 'Řeky a hory', 'Ostrovy', 'Velká města světa'],
        'Vlajky a hlavní města' => ['Hlavní města Evropy', 'Hlavní města světa', 'Vlajky Evropy', 'Vlajky světa', 'Státní symboly'],
        'Cestování a památky' => ['Světové divy', 'UNESCO v Česku', 'Hrady a zámky', 'Evropská města', 'Slavné mosty a věže'],
        'Dějepis' => ['Starověk', 'Středověk', 'Novověk', 'Objevné plavby', 'Války a bitvy', 'Panovníci'],
        'České dějiny' => ['Přemyslovci', 'Karel IV. a Lucemburkové', 'Husité', 'Habsburkové', 'První republika', 'Komunismus a sametová revoluce'],
        'Dějiny 20. století' => ['První světová válka', 'Druhá světová válka', 'Studená válka', 'Vesmírný závod', 'Objevy a vynálezy 20. století'],
        'Slavné osobnosti' => ['Vědci', 'Vynálezci', 'Umělci', 'Panovníci a politici', 'Sportovci', 'Čeští velikáni'],
        'Věda a příroda' => ['Fyzika', 'Chemie', 'Biologie', 'Geologie', 'Počasí a klima'],
        'Zvířata' => ['Savci', 'Ptáci', 'Mořský svět', 'Hmyz a pavoukovci', 'Domácí mazlíčci', 'Rekordy ze světa zvířat'],
        'Rostliny a příroda' => ['Stromy a lesy', 'Houby', 'Květiny', 'Národní parky', 'Ekologie'],
        'Lidské tělo a zdraví' => ['Kosti a svaly', 'Smysly', 'Orgány', 'Vitamíny a výživa', 'První pomoc'],
        'Kosmos a astronomie' => ['Sluneční soustava', 'Hvězdy a galaxie', 'Kosmonautika', 'Měsíc', 'Vesmírné mise'],
        'Technika a vynálezy' => ['Slavné vynálezy', 'Doprava', 'Energie', 'Stroje a nástroje', 'Elektronika'],
        'Počítače a internet' => ['Historie počítačů', 'Programování', 'Internet a sociální sítě', 'Mobilní telefony', 'Kyberbezpečnost'],
        'Matematika a logika' => ['Počty z hlavy', 'Geometrie', 'Čísla a posloupnosti', 'Hádanky', 'Zlomky a procenta'],
        'Film' => ['České filmy', 'České filmy 90. let', 'Hollywood', 'Animované filmy', 'Horor a sci-fi', 'Filmové hlášky', 'Oscaři'],
        'Seriály' => ['České seriály', 'Seriály pro dospělé', 'Animované seriály', 'Sitcomy', 'Fantasy a sci-fi seriály'],
        'Hudba' => ['Pop', 'Rock', 'Hip hop', 'Klasická hudba', 'Hudební nástroje', 'Hity 80. a 90. let', 'Světové hvězdy'],
        'Česká hudba' => ['Čeští zpěváci', 'Kapely', 'Písničkáři', 'Lidové písně', 'Hity minulých dekád', 'Muzikály'],
        'Literatura' => ['Česká klasika', 'Světová klasika', 'Poezie', 'Detektivky', 'Fantasy a sci-fi', 'Dětské knihy'],
        'Pohádky a komiksy' => ['České pohádky', 'Disney', 'Superhrdinové', 'Komiksy', 'Večerníčky', 'Bajky a báje'],
        'Výtvarné umění' => ['Malíři', 'Sochaři', 'Architektura', 'Slavná díla', 'Umělecké směry'],
        'Divadlo a tanec' => ['České divadlo', 'Opera a balet', 'Muzikály', 'Slavní herci', 'Tanec'],
        'Celebrity' => ['Čeští herci', 'Světové hvězdy', 'Youtubeři a influenceři', 'Moderátoři', 'Sportovní celebrity'],
        'Jídlo a vaření' => ['Česká kuchyně', 'Italská kuchyně', 'Světová kuchyně', 'Suroviny a koření', 'Sladké a pečení', 'Ovoce a zelenina'],
        'Pivo a nápoje' => ['Pivo a pivovary', 'České pivovary', 'Víno', 'Koktejly', 'Káva a čaj', 'Lihoviny'],
        'Sport' => ['Letní sporty', 'Zimní sporty', 'Rekordy a statistiky', 'Slavní sportovci', 'Pravidla sportů'],
        'Fotbal' => ['Mistrovství světa', 'Evropské poháry', 'Česká liga', 'Slavní hráči', 'Pravidla fotbalu'],
        'Hokej' => ['NHL', 'Mistrovství světa v hokeji', 'Česká extraliga', 'Slavní hokejisté', 'Nagano 1998'],
        'Olympijské hry' => ['Letní olympiády', 'Zimní olympiády', 'Čeští olympionici', 'Historie her', 'Rekordy'],
        'Auta a doprava' => ['Automobilky', 'Slavná auta', 'Formule 1', 'Motorky', 'Vlaky a letadla'],
        'Videohry a deskovky' => ['Retro hry', 'Nintendo', 'Minecraft a Fortnite', 'Deskové hry', 'Karty a šachy'],
        'Móda a design' => ['Módní značky', 'Historie módy', 'Design a architektura', 'Ikonické oblečení'],
        'Čeština a jazyk' => ['Pravopis', 'Slovní zásoba', 'Cizí slova', 'Nářečí a slang', 'Slovní hříčky'],
        'Rčení a přísloví' => ['Doplň přísloví', 'Rčení a frazeologismy', 'Citáty', 'Původ rčení'],
        'Mytologie a náboženství' => ['Řecká mytologie', 'Římská mytologie', 'Severská mytologie', 'Bible', 'Světová náboženství'],
        'Politika a státy' => ['Evropská unie', 'OSN a organizace', 'Státní zřízení', 'Čeští prezidenti', 'Volby a politika'],
        'Ekonomika a peníze' => ['Měny světa', 'Banky a finance', 'Podnikání', 'Slavné firmy', 'Zajímavosti o penězích'],
        'Reklamy a značky' => ['Reklamní slogany', 'Loga', 'České značky', 'Světové značky', 'Reklamy 90. let'],
        'Svátky a tradice' => ['Vánoce', 'Velikonoce', 'České tradice', 'Světové svátky', 'Svátky v kalendáři'],
        'Dětské otázky' => ['Zvířátka', 'Pohádky', 'Barvy a tvary', 'Počítání', 'Příroda kolem nás'],
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
