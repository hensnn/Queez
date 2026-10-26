<?php
declare(strict_types=1);

/** Uploady obrázků (v data/uploads, mimo webroot; servíruje je api/media.php). */
final class Media
{
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public static function dir(): string
    {
        $d = (string)cfg('data_dir', ROOT . '/data') . '/uploads';
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return $d;
    }

    public static function validName(string $n): bool
    {
        return (bool)preg_match('/^[a-f0-9]{24}\.(jpg|png|webp)$/', $n);
    }

    /** Zpracuje $_FILES['file'] a vrátí uložený název souboru. */
    public static function saveUpload(array $f): string
    {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Soubor se nepodařilo nahrát (možná je moc velký).');
        }
        $max = (int)cfg('upload_max_mb', 3) * 1024 * 1024;
        if ($f['size'] > $max) {
            throw new InvalidArgumentException('Obrázek je větší než ' . (int)cfg('upload_max_mb', 3) . ' MB.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset(self::TYPES[$mime]) || @getimagesize($f['tmp_name']) === false) {
            throw new InvalidArgumentException('Povolené formáty jsou JPG, PNG a WebP.');
        }
        $name = bin2hex(random_bytes(12)) . '.' . self::TYPES[$mime];
        if (!move_uploaded_file($f['tmp_name'], self::dir() . '/' . $name)) {
            throw new RuntimeException('Nelze uložit soubor do data/uploads (zkontrolujte práva).');
        }
        return $name;
    }

    public static function path(string $name): ?string
    {
        if (!self::validName($name)) {
            return null;
        }
        $p = self::dir() . '/' . $name;
        return is_file($p) ? $p : null;
    }
}
