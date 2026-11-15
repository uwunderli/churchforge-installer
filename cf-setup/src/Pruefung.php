<?php
// Vorprüfung (nur lesen). Jede Prüfung liefert: status ok|warn|fehler, titel, text (übersetzt, Klartext).
declare(strict_types=1);

final class Pruefung
{
    /** @return list<array{status:string,titel:string,text:string}> */
    public static function alle(string $adresse = ''): array
    {
        $r = [self::docker(), self::compose(), self::datenordner(), self::platz(), self::arbeitsspeicher(), self::raspi(), self::architektur(), self::ports()];
        if ($adresse !== '') {
            $r[] = self::adresse($adresse);
        }
        return $r;
    }

    private static function res(string $status, string $titelKey, string $text): array
    {
        return ['status' => $status, 'titel' => t($titelKey), 'text' => $text];
    }

    /** @return array{0:int,1:string} Exitcode und Stdout */
    private static function lauf(array $cmd): array
    {
        $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return [127, ''];
        }
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), trim((string) $out)];
    }

    private static function docker(): array
    {
        [$c, $o] = self::lauf(['docker', 'version', '--format', '{{.Server.Version}}']);
        return ($c === 0 && $o !== '')
            ? self::res('ok', 'docker.t', t('docker.ok', ['v' => $o]))
            : self::res('fehler', 'docker.t', t('docker.fehler'));
    }

    private static function compose(): array
    {
        [$c, $o] = self::lauf(['docker', 'compose', 'version', '--short']);
        return ($c === 0 && $o !== '')
            ? self::res('ok', 'compose.t', t('compose.ok', ['v' => $o]))
            : self::res('fehler', 'compose.t', t('compose.fehler'));
    }

    private static function datenordner(): array
    {
        $d = '/daten';
        $t = $d . '/.schreibprobe';
        if (is_dir($d) && @file_put_contents($t, 'x') !== false) {
            @unlink($t);
            return self::res('ok', 'daten.t', t('daten.ok'));
        }
        return self::res('fehler', 'daten.t', t('daten.fehler'));
    }

    private static function platz(): array
    {
        $frei = @disk_free_space('/daten');
        if ($frei === false) {
            return self::res('warn', 'platz.t', t('platz.unbekannt'));
        }
        $gb = $frei / 1073741824;
        $t = t('platz.frei', ['gb' => sprintf('%.0f', $gb)]);
        if ($gb < 10) {
            return self::res('fehler', 'platz.t', $t . ' ' . t('platz.fehler'));
        }
        if ($gb < 50) {
            return self::res('warn', 'platz.t', $t . ' ' . t('platz.warn'));
        }
        return self::res('ok', 'platz.t', $t);
    }

    private static function arbeitsspeicher(): array
    {
        $m = @file_get_contents('/proc/meminfo');
        if ($m === false || !preg_match('/^MemTotal:\s+(\d+) kB/m', $m, $x)) {
            return self::res('warn', 'ram.t', t('ram.unbekannt'));
        }
        $gb = (int) $x[1] / 1048576;
        $t = t('ram.wert', ['gb' => sprintf('%.1f', $gb)]);
        if ($gb < 3) {
            return self::res('fehler', 'ram.t', $t . ' ' . t('ram.fehler'));
        }
        if ($gb < 6) {
            return self::res('warn', 'ram.t', $t . ' ' . t('ram.warn'));
        }
        return self::res('ok', 'ram.t', $t);
    }

    private static function raspi(): array
    {
        $m = (string) @file_get_contents("/proc/device-tree/model");
        return str_contains($m, "Raspberry Pi")
            ? self::res("fehler", "raspi.t", t("raspi.fehler"))
            : self::res("ok", "raspi.t", t("raspi.ok"));
    }

    private static function architektur(): array
    {
        $a = php_uname('m');
        return in_array($a, ['x86_64', 'aarch64'], true)
            ? self::res('ok', 'arch.t', t('arch.ok', ['a' => $a]))
            : self::res('warn', 'arch.t', t('arch.warn', ['a' => $a]));
    }

    private static function ports(): array
    {
        [$c, $o] = self::lauf(['docker', 'ps', '--format', '{{.Names}}|{{.Ports}}']);
        if ($c !== 0) {
            return self::res('warn', 'ports.t', t('ports.unbekannt'));
        }
        $belegt = [];
        foreach (explode("\n", $o) as $zeile) {
            if ($zeile === '' || !str_contains($zeile, '|')) {
                continue;
            }
            [$name, $ports] = explode('|', $zeile, 2);
            foreach ([80, 443] as $p) {
                if (preg_match('/:' . $p . '->/', $ports) === 1) {
                    $belegt[] = t('ports.item', ['p' => $p, 'name' => $name]);
                }
            }
        }
        return $belegt
            ? self::res('warn', 'ports.t', implode('; ', $belegt) . '. ' . t('ports.belegt'))
            : self::res('ok', 'ports.t', t('ports.frei'));
    }

    private static function adresse(string $a): array
    {
        if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $a) !== 1) {
            return self::res('fehler', 'adresse.t', t('adresse.ungueltig'));
        }
        $ip = gethostbyname($a . '.');
        return $ip === $a . '.'
            ? self::res('warn', 'adresse.t', t('adresse.nicht_aufgeloest', ['a' => $a]))
            : self::res('ok', 'adresse.t', t('adresse.ok', ['a' => $a, 'ip' => $ip]));
    }
}
