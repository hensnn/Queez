<?php
declare(strict_types=1);

/**
 * Serverové předčítání přes Gemini TTS (záloha pro zařízení bez českého hlasu v prohlížeči).
 * Hotové audio se cachuje v data/tts_cache/ (stejný text = stejný soubor, žádné další volání API).
 */
final class Tts
{
    private const MAX_TEXT = 600;

    /** Je serverové TTS zapnuté (config + nastavení) a je k dispozici klíč? */
    public static function available(array $settings): bool
    {
        return (bool)cfg('gemini_tts_enabled', false)
            && !empty($settings['tts_gemini_fallback'])
            && Gemini::apiKey() !== '';
    }

    private static function dir(): string
    {
        $d = (string)cfg('data_dir', ROOT . '/data') . '/tts_cache';
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return $d;
    }

    /** Vrátí cestu k WAV souboru s přečteným textem (z cache, nebo nově vygenerovaným). */
    public static function synth(string $text, array $settings): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            throw new InvalidArgumentException('Text k přečtení je prázdný nebo příliš dlouhý (max. ' . self::MAX_TEXT . ' znaků).');
        }
        $model = (string)($settings['tts_gemini_model'] ?? 'gemini-2.5-flash-preview-tts');
        $voice = (string)($settings['tts_gemini_voice'] ?? 'Kore');
        $hash = hash('sha256', $model . '|' . $voice . '|' . $text);
        $file = self::dir() . '/' . $hash . '.wav';
        if (is_file($file)) {
            return $file;
        }

        $resp = Gemini::request($model, [
            'contents' => [['role' => 'user', 'parts' => [['text' => 'Přečti česky, přirozeně a zřetelně: ' . $text]]]],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice]]],
            ],
        ]);
        $part = $resp['candidates'][0]['content']['parts'][0]['inlineData'] ?? null;
        $pcm = $part ? base64_decode((string)($part['data'] ?? ''), true) : false;
        if ($pcm === false || $pcm === '') {
            throw new InvalidArgumentException('Gemini nevrátil žádné audio.');
        }
        $rate = 24000;
        if (preg_match('/rate=(\d{4,6})/', (string)($part['mimeType'] ?? ''), $m)) {
            $rate = (int)$m[1];
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, self::wav($pcm, $rate), LOCK_EX);
        rename($tmp, $file);
        Db::exec('INSERT OR REPLACE INTO tts_cache(hash,file,created_at) VALUES(?,?,?)', [$hash, basename($file), time()]);
        self::cleanup();
        return $file;
    }

    /** PCM 16bit mono → WAV. */
    private static function wav(string $pcm, int $rate): string
    {
        $len = strlen($pcm);
        return 'RIFF' . pack('V', 36 + $len) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            . 'data' . pack('V', $len) . $pcm;
    }

    /** Občas smaže soubory starší než 30 dní. */
    private static function cleanup(): void
    {
        if (random_int(1, 40) !== 1) {
            return;
        }
        $old = time() - 30 * 86400;
        foreach (Db::all('SELECT hash, file FROM tts_cache WHERE created_at < ?', [$old]) as $r) {
            @unlink(self::dir() . '/' . basename($r['file']));
            Db::exec('DELETE FROM tts_cache WHERE hash=?', [$r['hash']]);
        }
    }
}
