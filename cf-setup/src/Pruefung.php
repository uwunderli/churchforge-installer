<?php
// Vorprüfung (nur lesen). Jede Prüfung liefert: status ok|warn|fehler, titel, text (Klartext, was zu tun ist).
declare(strict_types=1);

final class Pruefung
{
    /** @return list<array{status:string,titel:string,text:string}> */
    public static function alle(string $adresse = ''): array
    {
        $r = [];
        $r[] = self::docker();
        $r[] = self::compose();
        $r[] = self::datenordner();
        $r[] = self::platz();
        $r[] = self::arbeitsspeicher();
        $r[] = self::architektur();
        $r[] = self::ports();
        if ($adresse !== '') {
            $r[] = self::adresse($adresse);
        }
        return $r;
    }

    private static function res(string $status, string $titel, string $text): array
    {
        return ['status' => $status, 'titel' => $titel, 'text' => $text];
    }

    /** @return array{0:int,1:string} Exitcode und Ausgabe (nur Stdout) */
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
        if ($c === 0 && $o !== '') {
            return self::res('ok', 'Docker', "Docker läuft (Version $o).");
        }
        return self::res('fehler', 'Docker', 'Der Setup-Container erreicht Docker nicht. Prüfe in der Start-Datei, dass /var/run/docker.sock eingebunden ist, und starte neu.');
    }

    private static function compose(): array
    {
        [$c, $o] = self::lauf(['docker', 'compose', 'version', '--short']);
        if ($c === 0 && $o !== '') {
            return self::res('ok', 'Docker Compose', "Compose ist vorhanden (Version $o).");
        }
        return self::res('fehler', 'Docker Compose', 'Docker Compose fehlt im Setup-Container. Bitte das Setup-Image neu laden.');
    }

    private static function datenordner(): array
    {
        $d = '/daten';
        $t = $d . '/.schreibprobe';
        if (is_dir($d) && @file_put_contents($t, 'x') !== false) {
            @unlink($t);
            return self::res('ok', 'Datenordner', 'Der Datenordner ist beschreibbar.');
        }
        return self::res('fehler', 'Datenordner', 'Der Datenordner /daten ist nicht beschreibbar. Prüfe in der Start-Datei die Zeile mit ./cf-daten:/daten und die Rechte des Ordners.');
    }

    private static function platz(): array
    {
        $frei = @disk_free_space('/daten');
        if ($frei === false) {
            return self::res('warn', 'Speicherplatz', 'Der freie Platz liess sich nicht ermitteln.');
        }
        $gb = $frei / 1073741824;
        $t = sprintf('%.0f GB frei im Datenordner.', $gb);
        if ($gb < 10) {
            return self::res('fehler', 'Speicherplatz', "$t Mindestens 10 GB nötig, empfohlen 50 GB oder mehr.");
        }
        if ($gb < 50) {
            return self::res('warn', 'Speicherplatz', "$t Das reicht zum Start, empfohlen sind 50 GB oder mehr.");
        }
        return self::res('ok', 'Speicherplatz', $t);
    }

    private static function arbeitsspeicher(): array
    {
        $m = @file_get_contents('/proc/meminfo');
        if ($m === false || !preg_match('/^MemTotal:\s+(\d+) kB/m', $m, $x)) {
            return self::res('warn', 'Arbeitsspeicher', 'Der Arbeitsspeicher liess sich nicht ermitteln.');
        }
        $gb = (int) $x[1] / 1048576;
        $t = sprintf('%.1f GB Arbeitsspeicher.', $gb);
        if ($gb < 3) {
            return self::res('fehler', 'Arbeitsspeicher', "$t Mindestens 4 GB nötig.");
        }
        if ($gb < 6) {
            return self::res('warn', 'Arbeitsspeicher', "$t Das reicht für den Start, empfohlen sind 8 GB.");
        }
        return self::res('ok', 'Arbeitsspeicher', $t);
    }

    private static function architektur(): array
    {
        $a = php_uname('m');
        if (in_array($a, ['x86_64', 'aarch64'], true)) {
            return self::res('ok', 'Prozessor', "Architektur $a wird unterstützt.");
        }
        return self::res('warn', 'Prozessor', "Architektur $a ist nicht getestet.");
    }

    private static function ports(): array
    {
        [$c, $o] = self::lauf(['docker', 'ps', '--format', '{{.Names}}|{{.Ports}}']);
        if ($c !== 0) {
            return self::res('warn', 'Ports 80 und 443', 'Die belegten Ports liessen sich nicht lesen (Docker nicht erreichbar).');
        }
        $belegt = [];
        foreach (explode("\n", $o) as $zeile) {
            if ($zeile === '' || !str_contains($zeile, '|')) {
                continue;
            }
            [$name, $ports] = explode('|', $zeile, 2);
            foreach ([80, 443] as $p) {
                if (preg_match('/:' . $p . '->/', $ports) === 1) {
                    $belegt[] = "Port $p durch Container «$name»";
                }
            }
        }
        if ($belegt) {
            return self::res('warn', 'Ports 80 und 443', implode('; ', $belegt) . '. Läuft dort schon ein Proxy, wähle später die Betriebsart «Hinter bestehendem Proxy». Programme ausserhalb von Docker erkennt diese Prüfung nicht.');
        }
        return self::res('ok', 'Ports 80 und 443', 'Kein Docker-Container belegt diese Ports. Programme ausserhalb von Docker erkennt diese Prüfung nicht.');
    }

    private static function adresse(string $a): array
    {
        if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $a) !== 1) {
            return self::res('fehler', 'Adresse', 'Die Adresse sieht nicht wie ein Domainname aus (Beispiel: kirche.example.ch).');
        }
        $ip = gethostbyname($a . '.');
        if ($ip === $a . '.') {
            return self::res('warn', 'Adresse', "«$a» wird im Netz nicht aufgelöst. Lege den DNS-Eintrag an, bevor du fortfährst.");
        }
        return self::res('ok', 'Adresse', "«$a» zeigt auf $ip.");
    }
}
