<?php
// cf-setup M1: Anmeldung mit Startcode, danach Vorprüfung. Installiert nichts.
declare(strict_types=1);

require_once __DIR__ . '/../src/Pruefung.php';

const DATEN = '/daten';
const MAX_FEHLVERSUCHE = 10;

session_name('cfsetup');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'path' => '/']);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'");

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function protokoll(string $ereignis, array $d = []): void
{
    // Nie Startcode, Passwörter oder Schlüssel hier hinein.
    $zeile = json_encode(['t' => gmdate('c'), 'e' => $ereignis] + $d, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents(DATEN . '/protokoll.jsonl', $zeile, FILE_APPEND | LOCK_EX);
    @chmod(DATEN . '/protokoll.jsonl', 0600);
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrfOk(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string) $_POST['csrf']);
}

function seite(string $titel, string $inhalt): void
{
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($titel) . '</title><style>'
        . 'body{font:16px/1.5 system-ui,sans-serif;margin:0;background:#f4f5f7;color:#1c2330}'
        . 'main{max-width:640px;margin:0 auto;padding:24px 16px}'
        . 'h1{font-size:1.5rem;margin:0 0 4px}.sub{color:#5b6472;margin:0 0 24px}'
        . '.box{background:#fff;border:1px solid #dde1e7;border-radius:10px;padding:20px;margin-bottom:16px}'
        . 'label{display:block;font-weight:600;margin-bottom:6px}'
        . 'input[type=text],input[type=password]{width:100%;box-sizing:border-box;padding:10px;border:1px solid #b9c0cb;border-radius:8px;font:inherit}'
        . 'button{margin-top:14px;padding:10px 18px;border:0;border-radius:8px;background:#2358c4;color:#fff;font:inherit;cursor:pointer}'
        . 'button.zweit{background:#e6e9ef;color:#1c2330}'
        . '.z{display:flex;gap:12px;padding:10px 0;border-top:1px solid #eceff3}.z:first-child{border-top:0}'
        . '.pt{flex:none;width:14px;height:14px;border-radius:50%;margin-top:6px}'
        . '.ok{background:#2e9e5b}.warn{background:#e0a100}.fehler{background:#d23a3a}'
        . '.t{font-weight:600}.fe{color:#d23a3a}.hint{color:#5b6472;font-size:.9rem}'
        . '</style></head><body><main>' . $inhalt . '</main></body></html>';
}

function fehlversuche(): int
{
    return (int) @file_get_contents(DATEN . '/.startcode.fehlversuche');
}

// ---------- Abmelden ----------
if (($_POST['aktion'] ?? '') === 'abmelden' && csrfOk()) {
    session_destroy();
    header('Location: /');
    exit;
}

// ---------- Anmelden ----------
$meldung = '';
if (empty($_SESSION['ok'])) {
    if (($_POST['aktion'] ?? '') === 'anmelden' && csrfOk()) {
        $n = fehlversuche();
        if ($n >= MAX_FEHLVERSUCHE) {
            $meldung = 'Zu viele Fehlversuche. Starte den Container neu (docker restart cf-setup) und nimm den neuen Startcode aus dem Log.';
        } else {
            $eingabe = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $soll = trim((string) @file_get_contents(DATEN . '/.startcode.sha256'));
            if ($soll !== '' && hash_equals($soll, hash('sha256', $eingabe))) {
                session_regenerate_id(true);
                $_SESSION['ok'] = true;
                protokoll('anmeldung');
                header('Location: /');
                exit;
            }
            @file_put_contents(DATEN . '/.startcode.fehlversuche', (string) ($n + 1));
            protokoll('anmeldung_fehlgeschlagen');
            sleep(1);
            $meldung = 'Der Startcode stimmt nicht.';
        }
    }
    $f = $meldung !== '' ? '<p class="fe">' . h($meldung) . '</p>' : '';
    seite('ChurchForge Setup', '<h1>ChurchForge Setup</h1><p class="sub">Willkommen. Zuerst brauchst du den Startcode.</p>'
        . '<div class="box"><form method="post"><input type="hidden" name="aktion" value="anmelden"><input type="hidden" name="csrf" value="' . h(csrf()) . '">'
        . '<label for="code">Startcode</label><input id="code" name="code" type="text" autocomplete="off" autofocus placeholder="XXXX-XXXX">'
        . $f . '<p class="hint">Den Code findest du im Log des Containers: <code>docker logs cf-setup</code>. In Arcane öffnest du dafür die Protokolle von «cf-setup».</p>'
        . '<button type="submit">Weiter</button></form></div>');
    exit;
}

// ---------- Vorprüfung ----------
$adresse = '';
$resultate = null;
if (($_POST['aktion'] ?? '') === 'pruefen' && csrfOk()) {
    $adresse = trim((string) ($_POST['adresse'] ?? ''));
    $resultate = Pruefung::alle($adresse);
    $zaehler = array_count_values(array_column($resultate, 'status'));
    protokoll('vorpruefung', ['ok' => $zaehler['ok'] ?? 0, 'warn' => $zaehler['warn'] ?? 0, 'fehler' => $zaehler['fehler'] ?? 0]);
}

$html = '<h1>ChurchForge Setup</h1><p class="sub">Schritt 1: Ist dein Rechner bereit?</p>'
    . '<div class="box"><form method="post"><input type="hidden" name="aktion" value="pruefen"><input type="hidden" name="csrf" value="' . h(csrf()) . '">'
    . '<label for="adresse">Adresse, unter der das System erreichbar sein soll (optional)</label>'
    . '<input id="adresse" name="adresse" type="text" value="' . h($adresse) . '" placeholder="kirche.example.ch">'
    . '<button type="submit">Prüfen</button></form></div>';

if ($resultate !== null) {
    $html .= '<div class="box">';
    foreach ($resultate as $x) {
        $html .= '<div class="z"><span class="pt ' . h($x['status']) . '"></span><div><div class="t">' . h($x['titel']) . '</div><div>' . h($x['text']) . '</div></div></div>';
    }
    $html .= '</div>';
    $zaehler = array_count_values(array_column($resultate, 'status'));
    if (($zaehler['fehler'] ?? 0) > 0) {
        $html .= '<p class="fe">Es gibt Punkte, die du zuerst beheben musst. Danach prüfst du erneut.</p>';
    } else {
        $html .= '<div class="box">Dein Rechner ist bereit. Die Installation selbst folgt im nächsten Ausbauschritt (noch nicht in dieser Version).</div>';
    }
}

$html .= '<form method="post"><input type="hidden" name="aktion" value="abmelden"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><button class="zweit" type="submit">Abmelden</button></form>';
seite('ChurchForge Setup', $html);
