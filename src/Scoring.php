<?php
declare(strict_types=1);

/**
 * Bodování – jediné místo, kde se body počítají (popis vzorce je v Nastavení → Hra).
 *
 *   rychlostní_faktor = 1 − R × čas / limit        (R = speed_weight/100; jen když má otázka limit)
 *   základ            = body_otázky × rychlostní_faktor
 *   bonus_série       = streak_bonus × min(správných_v_řadě_před_touto_otázkou, streak_max)
 *   body              = základ + bonus_série        (jen za správnou odpověď)
 *
 * U více správných odpovědí v režimu „poměrně“ se základ násobí podílem
 * max(0, (trefené − špatné) / počet_správných); série a bonus se přidají jen za plně správnou odpověď.
 */
final class Scoring
{
    /**
     * @param int[] $chosen   zvolené pozice
     * @param int[] $correct  správné pozice
     * @return array{0: ?bool, 1: int}  [plně správně (null = anketa), body]
     */
    public static function evaluate(
        string $type,
        array $chosen,
        array $correct,
        int $basePoints,
        array $settings,
        int $elapsedMs,
        int $limitMs,
        int $streakBefore
    ): array {
        if ($type === 'poll') {
            return [null, 0];
        }
        $chosen = array_values(array_unique($chosen));
        $hits = count(array_intersect($chosen, $correct));
        $wrong = count($chosen) - $hits;
        $full = $hits === count($correct) && $wrong === 0;
        $fraction = 0.0;
        if ($full) {
            $fraction = 1.0;
        } elseif (($settings['multi_correct_mode'] ?? 'all_or_nothing') === 'proportional' && count($correct) > 0) {
            $fraction = max(0.0, ($hits - $wrong) / count($correct));
        }
        if ($fraction <= 0.0) {
            return [false, 0];
        }

        $speed = 1.0;
        if ($limitMs > 0) {
            $r = max(0, min(100, (int)($settings['speed_weight'] ?? 0))) / 100;
            $t = max(0.0, min(1.0, $elapsedMs / $limitMs));
            $speed = 1.0 - $r * $t;
        }
        $points = $basePoints * $speed * $fraction;
        if ($full) {
            $points += (int)($settings['streak_bonus'] ?? 0)
                * min($streakBefore, max(1, (int)($settings['streak_max'] ?? 1)));
        }
        return [$full, (int)round($points)];
    }
}
