<?php
// Mehrsprachigkeit: lang/<code>.php liefert ['schlüssel' => 'Text mit {platzhalter}'].
// Fehlt ein Schlüssel in einer Sprache, gilt der deutsche Text; fehlt auch der, der Schlüssel selbst.
declare(strict_types=1);

final class Sprache
{
    public const ERLAUBT = ['de' => 'Deutsch', 'fr' => 'Français', 'it' => 'Italiano', 'en' => 'English'];
    private static string $code = 'de';
    /** @var array<string,string> */
    private static array $m = [];
    /** @var array<string,string> */
    private static array $de = [];

    public static function gueltig(string $c): bool
    {
        return isset(self::ERLAUBT[$c]);
    }

    public static function setze(string $c): void
    {
        self::$code = self::gueltig($c) ? $c : 'de';
        self::$de = require __DIR__ . '/../lang/de.php';
        self::$m = self::$code === 'de' ? self::$de : (require __DIR__ . '/../lang/' . self::$code . '.php');
    }

    public static function code(): string
    {
        return self::$code;
    }

    /** @param array<string,string|int|float> $p */
    public static function t(string $k, array $p = []): string
    {
        $s = self::$m[$k] ?? self::$de[$k] ?? $k;
        foreach ($p as $n => $w) {
            $s = str_replace('{' . $n . '}', (string) $w, $s);
        }
        return $s;
    }

    /** Vorschlag aus dem Browser (Accept-Language), nur als Vorauswahl. */
    public static function vomBrowser(): string
    {
        $h = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        foreach (array_keys(self::ERLAUBT) as $c) {
            if (preg_match('/(^|,)\s*' . $c . '\b/', $h) === 1) {
                return $c;
            }
        }
        return 'de';
    }
}

function t(string $k, array $p = []): string
{
    return Sprache::t($k, $p);
}
