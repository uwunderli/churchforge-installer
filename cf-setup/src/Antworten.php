<?php
// Speicher für die Antworten des Assistenten.
// antworten.json: alles ausser Passwörtern. geheim.env: Passwörter (KEY='wert'), beide 0600, nie im Log, nie wieder angezeigt.
declare(strict_types=1);

final class Antworten
{
    private const JSON = '/daten/antworten.json';
    private const GEHEIM = '/daten/geheim.env';

    /** @return array<string,mixed> */
    public static function lade(): array
    {
        $j = @file_get_contents(self::JSON);
        $a = $j === false ? null : json_decode($j, true);
        return is_array($a) ? $a : [];
    }

    /** @param array<string,mixed> $a */
    public static function speichere(array $a): void
    {
        self::schreibe(self::JSON, json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }

    public static function geheim(string $k): string
    {
        $t = @file_get_contents(self::GEHEIM);
        if ($t !== false && preg_match('/^' . preg_quote($k, '/') . "='(.*)'$/m", $t, $m) === 1) {
            return str_replace("'\\''", "'", $m[1]);
        }
        return '';
    }

    public static function geheimGesetzt(string $k): bool
    {
        return self::geheim($k) !== '';
    }

    public static function setzeGeheim(string $k, string $v): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $k) !== 1 || str_contains($v, "\n") || str_contains($v, "\r") || str_contains($v, "\0")) {
            throw new InvalidArgumentException('geheimer Wert nicht speicherbar');
        }
        $t = (string) @file_get_contents(self::GEHEIM);
        $zeile = $k . "='" . str_replace("'", "'\\''", $v) . "'";
        $neu = preg_replace('/^' . preg_quote($k, '/') . "='.*'$/m", addcslashes($zeile, '\\$'), $t, -1, $n);
        if ($n === 0 || $neu === null) {
            $neu = rtrim($t) . ($t === '' ? '' : "\n") . $zeile;
        }
        self::schreibe(self::GEHEIM, rtrim($neu) . "\n");
    }

    private static function schreibe(string $pfad, string $inhalt): void
    {
        $alt = umask(0077);
        $tmp = $pfad . ".tmp";
        if (file_put_contents($tmp, $inhalt, LOCK_EX) === false) {
            throw new RuntimeException('Datenordner nicht beschreibbar');
        }
        umask($alt);
        chmod($tmp, 0600);
        rename($tmp, $pfad);
    }
}
