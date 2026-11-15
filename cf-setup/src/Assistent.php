<?php
// Schritte des Assistenten (M1b): Installationsart, Vorprüfung, Abfragen, Zusammenfassung. Installiert nichts.
declare(strict_types=1);

final class Assistent
{
    public const REIHE = ['art', 'pruefung', 'paket', 'organisation', 'adressen', 'erreichbarkeit', 'admin', 'smtp', 'zusammenfassung'];
    private const PAKETE = ['kirche', 'feuerwehr', 'privat'];
    private const ZONEN = ['Europe/Zurich', 'Europe/Berlin', 'Europe/Vienna', 'Europe/Paris', 'Europe/Rome', 'Europe/Vaduz', 'UTC'];
    private const DOMAIN = '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i';

    public static function gueltig(string $s): bool
    {
        return in_array($s, self::REIHE, true);
    }

    public static function vorher(string $s): string
    {
        $i = (int) array_search($s, self::REIHE, true);
        return self::REIHE[max(0, $i - 1)];
    }

    public static function nachher(string $s): string
    {
        $i = (int) array_search($s, self::REIHE, true);
        return self::REIHE[min(count(self::REIHE) - 1, $i + 1)];
    }

    // ---------- Verarbeiten: gibt [fehlerliste, info] zurück; speichert nur bei fehlerfreien Eingaben ----------

    /** @param array<string,mixed> $p @param array<string,mixed> $a @return array{0:list<string>,1:string} */
    public static function verarbeite(string $schritt, array $p, array &$a, bool $test = false): array
    {
        $f = [];
        $info = '';
        $s = fn(string $k): string => trim((string) ($p[$k] ?? ''));
        switch ($schritt) {
            case 'art':
                if ($s('art') === 'grundsystem') {
                    $a['art'] = 'grundsystem';
                } else {
                    $f[] = t('art.bald');
                }
                break;
            case 'pruefung':
                if (array_count_values(array_column(Pruefung::alle(), 'status'))['fehler'] ?? 0) {
                    $f[] = t('pruefung.fehler');
                }
                break;
            case 'paket':
                if (in_array($s('paket'), self::PAKETE, true)) {
                    $a['paket'] = $s('paket');
                } else {
                    $f[] = t('err.pflicht', ['f' => t('paket.titel')]);
                }
                break;
            case 'organisation':
                $n = $s('organisation');
                if (mb_strlen($n) < 2 || mb_strlen($n) > 80) {
                    $f[] = t('err.laenge', ['f' => t('org.name'), 'min' => 2, 'max' => 80]);
                }
                if (!in_array($s('zeitzone'), self::ZONEN, true)) {
                    $f[] = t('err.pflicht', ['f' => t('org.zeitzone')]);
                }
                if (!$f) {
                    $a['organisation'] = $n;
                    $a['zeitzone'] = $s('zeitzone');
                }
                break;
            case 'adressen':
                $haupt = strtolower($s('haupt'));
                if (preg_match(self::DOMAIN, $haupt) !== 1) {
                    $f[] = t('err.domain', ['f' => t('adr.haupt')]);
                }
                $hosts = [];
                foreach (['hub' => 'adr.hub', 'auth' => 'adr.auth', 'files' => 'adr.files'] as $k => $l) {
                    $v = strtolower($s($k));
                    $v = $v === '' && $haupt !== '' ? $k . '.' . $haupt : $v;
                    if (preg_match(self::DOMAIN, $v) !== 1) {
                        $f[] = t('err.domain', ['f' => t($l)]);
                    }
                    $hosts[$k] = $v;
                }
                if (!$f) {
                    $a['haupt'] = $haupt;
                    $a['hosts'] = $hosts;
                    foreach ($hosts as $h) {
                        if (gethostbyname($h . '.') === $h . '.') {
                            $info .= t('adr.dns', ['h' => $h]) . ' ';
                        }
                    }
                }
                break;
            case 'erreichbarkeit':
                $proxy = $s('proxy') === 'ja';
                if ($s('proxy') !== 'ja' && $s('proxy') !== 'nein') {
                    $f[] = t('err.pflicht', ['f' => t('erreich.frage')]);
                } elseif ($proxy) {
                    $port = (int) $s('port');
                    if ($port < 1 || $port > 65535) {
                        $f[] = t('err.port', ['f' => t('erreich.port')]);
                    }
                } elseif (filter_var($s('zertmail'), FILTER_VALIDATE_EMAIL) === false) {
                    $f[] = t('err.mail', ['f' => t('erreich.mail')]);
                }
                if (!$f) {
                    $a['proxy_vorhanden'] = $proxy;
                    $a['http_port'] = $proxy ? (int) $s('port') : null;
                    $a['zertifikat_mail'] = $proxy ? null : $s('zertmail');
                }
                break;
            case 'admin':
                if (preg_match('/^[a-z0-9._-]{3,40}$/', $s('aname')) !== 1) {
                    $f[] = t('err.name');
                }
                if (filter_var($s('amail'), FILTER_VALIDATE_EMAIL) === false) {
                    $f[] = t('err.mail', ['f' => t('admin.mail')]);
                }
                $pw = (string) ($p['apw'] ?? '');
                $pw2 = (string) ($p['apw2'] ?? '');
                $behalten = $pw === '' && $pw2 === '' && Antworten::geheimGesetzt('ADMIN_PASSWORD');
                if (!$behalten) {
                    if (mb_strlen($pw) < 12) {
                        $f[] = t('err.pw_kurz');
                    } elseif ($pw !== $pw2) {
                        $f[] = t('err.pw_ungleich');
                    } elseif (preg_match('/[\r\n\0]/', $pw) === 1) {
                        $f[] = t('err.pw_zeichen');
                    }
                }
                if (!$f) {
                    $a['admin_name'] = $s('aname');
                    $a['admin_mail'] = $s('amail');
                    if (!$behalten) {
                        Antworten::setzeGeheim('ADMIN_PASSWORD', $pw);
                    }
                }
                break;
            case 'smtp':
                if ($s('smtp') === 'spaeter') {
                    $a['smtp'] = ['aktiv' => false];
                    break;
                }
                $port = (int) $s('sport');
                $sicher = $s('ssicher');
                $pw = (string) ($p['spw'] ?? '');
                if (preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/i', $s('shost')) !== 1) {
                    $f[] = t('err.domain', ['f' => t('smtp.server')]);
                }
                if ($port < 1 || $port > 65535) {
                    $f[] = t('err.port', ['f' => t('smtp.port')]);
                }
                if (!in_array($sicher, ['starttls', 'ssl', 'none'], true)) {
                    $f[] = t('err.pflicht', ['f' => t('smtp.sicher')]);
                }
                if (filter_var($s('sabsender'), FILTER_VALIDATE_EMAIL) === false) {
                    $f[] = t('err.mail', ['f' => t('smtp.absender')]);
                }
                if (preg_match('/[\r\n\0]/', $s('suser') . $pw) === 1) {
                    $f[] = t('err.pw_zeichen');
                }
                if (!$f) {
                    $a['smtp'] = ['aktiv' => true, 'host' => $s('shost'), 'port' => $port, 'sicher' => $sicher, 'user' => $s('suser'), 'absender' => $s('sabsender')];
                    if ($pw !== '') {
                        Antworten::setzeGeheim('SMTP_PASSWORD', $pw);
                    }
                    if ($test) {
                        Antworten::speichere($a);
                        $an = (string) ($a['admin_mail'] ?? '');
                        [$ok, $m] = Smtp::test(
                            ['host' => $s('shost'), 'port' => $port, 'sicher' => $sicher, 'user' => $s('suser'), 'pw' => Antworten::geheim('SMTP_PASSWORD'), 'absender' => $s('sabsender')],
                            $an,
                            t('smtp.test_betreff'),
                            t('smtp.test_text')
                        );
                        if ($ok) {
                            $info = t('smtp.test_ok', ['a' => $an]);
                        } else {
                            $f[] = t('smtp.test_fehler', ['m' => $m]);
                        }
                    }
                }
                break;
            case 'zusammenfassung':
                $a['fertig'] = gmdate('c');
                $a['sprache'] = Sprache::code();
                $info = t('sum.fertig');
                break;
        }
        return [$f, $info];
    }

    // ---------- Anzeige ----------

    private static function feld(string $n, string $label, string $v = '', string $typ = 'text', string $hint = '', string $ph = ''): string
    {
        return '<div style="margin-bottom:14px"><label for="' . h($n) . '">' . h($label) . '</label><input id="' . h($n) . '" name="' . h($n) . '" type="' . h($typ)
            . '" value="' . h($v) . '" placeholder="' . h($ph) . '" autocomplete="off">'
            . ($hint !== '' ? '<div class="hint">' . h($hint) . '</div>' : '') . '</div>';
    }

    private static function radio(string $n, string $wert, string $label, bool $an, string $hint = ''): string
    {
        return '<label style="font-weight:400;display:block;margin-bottom:8px"><input type="radio" name="' . h($n) . '" value="' . h($wert) . '"' . ($an ? ' checked' : '') . '> '
            . h($label) . ($hint !== '' ? '<div class="hint" style="margin-left:22px">' . h($hint) . '</div>' : '') . '</label>';
    }

    /** @param array<string,mixed> $a @param list<string> $fehler */
    public static function seite(string $schritt, array $a, array $fehler, string $info): string
    {
        $i = (int) array_search($schritt, self::REIHE, true);
        $kopf = '<h1>ChurchForge Setup</h1><p class="sub">' . h(t('schritt.n_von_m', ['n' => $i + 1, 'm' => count(self::REIHE)])) . '</p>';
        $meld = '';
        foreach ($fehler as $e) {
            $meld .= '<p class="fe">' . h($e) . '</p>';
        }
        if ($info !== '') {
            $meld .= '<p class="hint" style="color:#1c7a45">' . h($info) . '</p>';
        }
        $s = static fn(string $k, string $d = ''): string => (string) ($a[$k] ?? $d);
        $body = '';
        $weiterText = t('nav.weiter');
        $extra = '';

        switch ($schritt) {
            case 'art':
                $body = '<h2>' . h(t('art.titel')) . '</h2>'
                    . '<button type="submit" name="art" value="grundsystem" style="width:100%;text-align:left"><b>' . h(t('art.grund')) . '</b><br>' . h(t('art.grund_t')) . '</button>'
                    . '<div class="hint" style="margin-top:12px;padding:12px;border:1px dashed #b9c0cb;border-radius:8px;opacity:.7"><b>' . h(t('art.sub')) . '</b> · ' . h(t('art.bald')) . '<br>' . h(t('art.sub_t')) . '</div>';
                $weiterText = '';
                break;
            case 'pruefung':
                $body = '<h2>' . h(t('pruefung.titel')) . '</h2>';
                foreach (Pruefung::alle() as $x) {
                    $body .= '<div class="z"><span class="pt ' . h($x['status']) . '"></span><div><div class="t">' . h($x['titel']) . '</div><div>' . h($x['text']) . '</div></div></div>';
                }
                $body .= '<button type="submit" name="nav" value="erneut" class="zweit">' . h(t('pruefung.erneut')) . '</button>';
                break;
            case 'paket':
                $body = '<h2>' . h(t('paket.titel')) . '</h2>';
                foreach (self::PAKETE as $p) {
                    $body .= self::radio('paket', $p, t('paket.' . $p), $s('paket') === $p, t('paket.' . $p . '_t'));
                }
                $body .= '<div class="hint">' . h(t('paket.hinweis')) . '</div>';
                break;
            case 'organisation':
                $o = '';
                foreach (self::ZONEN as $z) {
                    $o .= '<option value="' . h($z) . '"' . (($s('zeitzone', 'Europe/Zurich') === $z) ? ' selected' : '') . '>' . h($z) . '</option>';
                }
                $body = '<h2>' . h(t('org.titel')) . '</h2>' . self::feld('organisation', t('org.name'), $s('organisation'))
                    . '<label for="zeitzone">' . h(t('org.zeitzone')) . '</label><select id="zeitzone" name="zeitzone" style="width:100%;padding:10px;border:1px solid #b9c0cb;border-radius:8px;font:inherit">' . $o . '</select>';
                break;
            case 'adressen':
                $h = (array) ($a['hosts'] ?? []);
                $hb = $s('haupt');
                $body = '<h2>' . h(t('adr.titel')) . '</h2>' . self::feld('haupt', t('adr.haupt'), $hb, 'text', t('adr.haupt_h'), 'kirche.example.ch')
                    . self::feld('hub', t('adr.hub'), (string) ($h['hub'] ?? ''), 'text', t('adr.leer', ['v' => 'hub.' . ($hb !== '' ? $hb : '…')]))
                    . self::feld('auth', t('adr.auth'), (string) ($h['auth'] ?? ''), 'text', t('adr.leer', ['v' => 'auth.' . ($hb !== '' ? $hb : '…')]))
                    . self::feld('files', t('adr.files'), (string) ($h['files'] ?? ''), 'text', t('adr.leer', ['v' => 'files.' . ($hb !== '' ? $hb : '…')]));
                break;
            case 'erreichbarkeit':
                $ja = (bool) ($a['proxy_vorhanden'] ?? false);
                $gesetzt = array_key_exists('proxy_vorhanden', $a);
                $body = '<h2>' . h(t('erreich.titel')) . '</h2><p>' . h(t('erreich.frage')) . '</p>'
                    . self::radio('proxy', 'nein', t('erreich.nein'), $gesetzt && !$ja)
                    . self::feld('zertmail', t('erreich.mail'), $s('zertifikat_mail'), 'text', t('erreich.mail_h'))
                    . self::radio('proxy', 'ja', t('erreich.ja'), $ja)
                    . self::feld('port', t('erreich.port'), (string) ($a['http_port'] ?? '8081'), 'text', t('erreich.port_h'));
                break;
            case 'admin':
                $body = '<h2>' . h(t('admin.titel')) . '</h2><p class="hint">' . h(t('admin.hinweis')) . '</p>'
                    . self::feld('aname', t('admin.name'), $s('admin_name'), 'text', t('admin.name_h'))
                    . self::feld('amail', t('admin.mail'), $s('admin_mail'), 'text')
                    . self::feld('apw', t('admin.pw'), '', 'password', Antworten::geheimGesetzt('ADMIN_PASSWORD') ? t('admin.gesetzt') : '')
                    . self::feld('apw2', t('admin.pw2'), '', 'password');
                break;
            case 'smtp':
                $m = (array) ($a['smtp'] ?? []);
                $aktiv = (bool) ($m['aktiv'] ?? true);
                $si = (string) ($m['sicher'] ?? 'starttls');
                $opt = '';
                foreach (['starttls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => t('smtp.keine')] as $k => $l) {
                    $opt .= '<option value="' . h($k) . '"' . ($si === $k ? ' selected' : '') . '>' . h($l) . '</option>';
                }
                $body = '<h2>' . h(t('smtp.titel')) . '</h2><p class="hint">' . h(t('smtp.hinweis')) . '</p>'
                    . self::radio('smtp', 'jetzt', t('smtp.jetzt'), $aktiv) . self::radio('smtp', 'spaeter', t('smtp.spaeter'), !$aktiv)
                    . self::feld('shost', t('smtp.server'), (string) ($m['host'] ?? ''), 'text', '', 'smtp.example.ch')
                    . self::feld('sport', t('smtp.port'), (string) ($m['port'] ?? '587'))
                    . '<label for="ssicher">' . h(t('smtp.sicher')) . '</label><select id="ssicher" name="ssicher" style="width:100%;padding:10px;border:1px solid #b9c0cb;border-radius:8px;font:inherit;margin-bottom:14px">' . $opt . '</select>'
                    . self::feld('suser', t('smtp.user'), (string) ($m['user'] ?? ''))
                    . self::feld('spw', t('smtp.pw'), '', 'password', Antworten::geheimGesetzt('SMTP_PASSWORD') ? t('admin.gesetzt') : '')
                    . self::feld('sabsender', t('smtp.absender'), (string) ($m['absender'] ?? ''), 'text', '', 'noreply@example.ch');
                $extra = '<button type="submit" name="nav" value="test" class="zweit">' . h(t('smtp.test')) . '</button> ';
                break;
            case 'zusammenfassung':
                $h = (array) ($a['hosts'] ?? []);
                $m = (array) ($a['smtp'] ?? []);
                $zeilen = [
                    t('paket.titel') => t('paket.' . $s('paket', 'kirche')),
                    t('org.name') => $s('organisation'),
                    t('org.zeitzone') => $s('zeitzone'),
                    t('adr.hub') => (string) ($h['hub'] ?? ''),
                    t('adr.auth') => (string) ($h['auth'] ?? ''),
                    t('adr.files') => (string) ($h['files'] ?? ''),
                    t('erreich.titel') => !empty($a['proxy_vorhanden']) ? t('sum.proxy_ja', ['p' => $s('http_port')]) : t('sum.proxy_nein', ['m' => $s('zertifikat_mail')]),
                    t('admin.name') => $s('admin_name') . ' (' . $s('admin_mail') . ')',
                    t('admin.pw') => Antworten::geheimGesetzt('ADMIN_PASSWORD') ? t('sum.gesetzt') : '–',
                    t('smtp.titel') => !empty($m['aktiv']) ? ($m['host'] ?? '') . ':' . ($m['port'] ?? '') . ' · ' . ($m['absender'] ?? '') : t('sum.nicht'),
                    t('platz.t') => t('sum.platz', ['gb' => sprintf('%.0f', (float) (@disk_free_space('/daten') ?: 0) / 1073741824)]),
                    t('sprache.waehlen') => Sprache::ERLAUBT[Sprache::code()],
                ];
                $body = '<h2>' . h(t('sum.titel')) . '</h2>';
                foreach ($zeilen as $k => $v) {
                    $body .= '<div class="z"><div style="flex:0 0 42%" class="t">' . h((string) $k) . '</div><div>' . h((string) $v) . '</div></div>';
                }
                if (!empty($a['fertig'])) {
                    $body .= '<p class="hint" style="color:#1c7a45">' . h(t('sum.fertig')) . '</p>';
                }
                $weiterText = t('sum.speichern');
                break;
        }

        $nav = '';
        if ($i > 0) {
            $nav .= '<button type="submit" name="nav" value="zurueck" class="zweit">' . h(t('nav.zurueck')) . '</button> ';
        }
        $nav .= $extra;
        if ($weiterText !== '') {
            $nav .= '<button type="submit" name="nav" value="weiter">' . h($weiterText) . '</button>';
        }
        return $kopf . '<div class="box"><form method="post"><input type="hidden" name="aktion" value="schritt"><input type="hidden" name="schritt" value="' . h($schritt) . '">'
            . csrfFeld() . $meld . $body . '<div style="margin-top:6px">' . $nav . '</div></form></div>';
    }
}
