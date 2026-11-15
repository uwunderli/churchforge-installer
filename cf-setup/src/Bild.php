<?php
// Logo/Hintergrund: Upload oder URL, geprüft und als eigene Kopie in /daten/branding/ abgelegt (kein Hotlinking).
// Rasterbilder werden mit GD neu kodiert (entfernt Metadaten und Fremdinhalt); SVG mit aktiven Inhalten wird abgelehnt.
declare(strict_types=1);

final class Bild
{
    public const DIR = '/daten/branding';
    private const MAXPIXEL = 6000;

    /**
     * @param array<string,mixed>|null $datei Eintrag aus $_FILES oder null
     * @param list<string> $erlaubt z. B. ['png','jpg','webp','svg']
     * @return array{0:?string,1:?string} [Dateiname relativ zu DIR | null, Fehlertext | null]; null/null = nichts angegeben
     */
    public static function nimm(string $art, ?array $datei, string $url, int $maxBytes, array $erlaubt, string $label): array
    {
        $roh = null;
        if ($datei !== null && ($datei['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($datei['error'] ?? 1) === UPLOAD_ERR_INI_SIZE || ($datei['error'] ?? 1) === UPLOAD_ERR_FORM_SIZE) {
                return [null, t('err.bild_gross', ['f' => $label, 'mb' => intdiv($maxBytes, 1048576)])];
            }
            if (($datei['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $datei['tmp_name'])) {
                return [null, t('err.bild_url', ['f' => $label])];
            }
            if ((int) $datei['size'] > $maxBytes) {
                return [null, t('err.bild_gross', ['f' => $label, 'mb' => intdiv($maxBytes, 1048576)])];
            }
            $roh = (string) file_get_contents((string) $datei['tmp_name']);
        } elseif ($url !== '') {
            $roh = self::hole($url, $maxBytes);
            if ($roh === null) {
                return [null, t('err.bild_url', ['f' => $label])];
            }
            if ($roh === '') {
                return [null, t('err.bild_gross', ['f' => $label, 'mb' => intdiv($maxBytes, 1048576)])];
            }
        } else {
            return [null, null];
        }
        return self::speichere($art, $roh, $erlaubt, $label);
    }

    public static function entferne(string $art): void
    {
        foreach (['png', 'jpg', 'webp', 'svg'] as $e) {
            @unlink(self::DIR . '/' . $art . '.' . $e);
        }
    }

    /** Favicon aus dem Logo: Raster als 64x64 PNG, SVG bleibt SVG. @return ?string Dateiname */
    public static function favicon(?string $logo): ?string
    {
        self::entferne('favicon');
        if ($logo === null || !is_file(self::DIR . '/' . $logo)) {
            return null;
        }
        if (str_ends_with($logo, '.svg')) {
            copy(self::DIR . '/' . $logo, self::DIR . '/favicon.svg');
            @chmod(self::DIR . '/favicon.svg', 0644);
            return 'favicon.svg';
        }
        $src = @imagecreatefromstring((string) file_get_contents(self::DIR . '/' . $logo));
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $z = imagecreatetruecolor(64, 64);
        imagealphablending($z, false);
        imagesavealpha($z, true);
        imagefill($z, 0, 0, imagecolorallocatealpha($z, 0, 0, 0, 127));
        $f = min(64 / $w, 64 / $h);
        $nw = max(1, (int) round($w * $f));
        $nh = max(1, (int) round($h * $f));
        imagecopyresampled($z, $src, intdiv(64 - $nw, 2), intdiv(64 - $nh, 2), 0, 0, $nw, $nh, $w, $h);
        imagepng($z, self::DIR . '/favicon.png');
        @chmod(self::DIR . '/favicon.png', 0644);
        return 'favicon.png';
    }

    private static function speichere(string $art, string $roh, array $erlaubt, string $label): array
    {
        if (!is_dir(self::DIR) && !@mkdir(self::DIR, 0755, true)) {
            return [null, t('err.bild_url', ['f' => $label])];
        }
        $liste = implode(', ', array_map('strtoupper', $erlaubt));
        if (preg_match('/^\s*(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE[^>]*>\s*)?<svg[\s>]/is', $roh) === 1) {
            if (!in_array('svg', $erlaubt, true)) {
                return [null, t('err.bild_typ', ['f' => $label, 'erlaubt' => $liste])];
            }
            if (preg_match('/<script|<foreignObject|\son[a-z]+\s*=|javascript:|<iframe|<embed|<object|<!ENTITY|(xlink:)?href\s*=\s*["\']\s*(https?:|\/\/|data:text)/i', $roh) === 1) {
                return [null, t('err.bild_svg', ['f' => $label])];
            }
            self::entferne($art);
            $name = $art . '.svg';
            file_put_contents(self::DIR . '/' . $name, $roh);
            @chmod(self::DIR . '/' . $name, 0644);
            return [$name, null];
        }
        $info = @getimagesizefromstring($roh);
        $typen = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
        if ($info === false || !isset($typen[$info[2]]) || !in_array($typen[$info[2]], $erlaubt, true)) {
            return [null, t('err.bild_typ', ['f' => $label, 'erlaubt' => $liste])];
        }
        if ($info[0] > self::MAXPIXEL || $info[1] > self::MAXPIXEL) {
            return [null, t('err.bild_gd', ['f' => $label])];
        }
        $img = @imagecreatefromstring($roh);
        if ($img === false) {
            return [null, t('err.bild_gd', ['f' => $label])];
        }
        $ext = $typen[$info[2]];
        self::entferne($art);
        $name = $art . '.' . $ext;
        $pfad = self::DIR . '/' . $name;
        imagesavealpha($img, true);
        $ok = match ($ext) {
            'png' => imagepng($img, $pfad),
            'webp' => imagewebp($img, $pfad, 90),
            default => imagejpeg($img, $pfad, 90),
        };
        if (!$ok) {
            return [null, t('err.bild_gd', ['f' => $label])];
        }
        @chmod($pfad, 0644);
        return [$name, null];
    }

    /** Holt eine URL (http/https, höchstens 3 Weiterleitungen, nie Loopback/Link-Local). null = Fehler, '' = zu gross. */
    private static function hole(string $url, int $max): ?string
    {
        for ($i = 0; $i < 4; $i++) {
            $p = parse_url($url);
            if (!is_array($p) || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host'])) {
                return null;
            }
            $ips = @gethostbynamel($p['host']) ?: [];
            if (!$ips) {
                return null;
            }
            foreach ($ips as $ip) {
                if (preg_match('/^(127\.|0\.|169\.254\.)/', $ip) === 1) {
                    return null;
                }
            }
            $ctx = stream_context_create(['http' => ['timeout' => 10, 'follow_location' => 0, 'ignore_errors' => true, 'user_agent' => 'cf-setup'],
                'ssl' => ['verify_peer' => true]]);
            $h = @fopen($url, 'rb', false, $ctx);
            if ($h === false) {
                return null;
            }
            $kopf = (array) (stream_get_meta_data($h)['wrapper_data'] ?? []);
            $status = isset($kopf[0]) ? (int) substr((string) $kopf[0], 9, 3) : 0;
            if ($status >= 300 && $status < 400) {
                $ziel = '';
                foreach ($kopf as $z) {
                    if (stripos((string) $z, 'Location:') === 0) {
                        $ziel = trim(substr((string) $z, 9));
                    }
                }
                fclose($h);
                if ($ziel === '') {
                    return null;
                }
                $url = preg_match('#^https?://#i', $ziel) === 1 ? $ziel : ($p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/' . ltrim($ziel, '/'));
                continue;
            }
            $daten = stream_get_contents($h, $max + 1);
            fclose($h);
            if ($status !== 200 || $daten === false) {
                return null;
            }
            return strlen($daten) > $max ? '' : $daten;
        }
        return null;
    }
}
