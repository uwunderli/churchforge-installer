<?php
// cf-setup M1: Sprachwahl, Anmeldung mit Startcode, danach Vorprüfung. Installiert nichts.
declare(strict_types=1);

require_once __DIR__ . '/../src/Sprache.php';
require_once __DIR__ . '/../src/Pruefung.php';
require_once __DIR__ . '/../src/Antworten.php';
require_once __DIR__ . '/../src/Smtp.php';
require_once __DIR__ . '/../src/Assistent.php';

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

function csrfFeld(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
}

/** Sprachwahl: Sitzung, sonst gespeicherte Wahl; null = noch nie gewählt. */
function gewaehlteSprache(): ?string
{
    $c = (string) ($_SESSION['lang'] ?? '');
    if (!Sprache::gueltig($c)) {
        $c = trim((string) @file_get_contents(DATEN . '/sprache'));
    }
    return Sprache::gueltig($c) ? $c : null;
}

/** Flaggen als SVG-Symbole (einmal pro Seite), danach per <use> verwendet. */
function flaggenSymbole(): string
{
    return '<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>'
        . '<symbol id="fl-de" viewBox="0 0 5 3"><rect width="5" height="1" fill="#000"/><rect y="1" width="5" height="1" fill="#d00"/><rect y="2" width="5" height="1" fill="#ffce00"/></symbol>'
        . '<symbol id="fl-fr" viewBox="0 0 3 2"><rect width="1" height="2" fill="#0055a4"/><rect x="1" width="1" height="2" fill="#fff"/><rect x="2" width="1" height="2" fill="#ef4135"/></symbol>'
        . '<symbol id="fl-it" viewBox="0 0 3 2"><rect width="1" height="2" fill="#009246"/><rect x="1" width="1" height="2" fill="#fff"/><rect x="2" width="1" height="2" fill="#ce2b37"/></symbol>'
        . '<symbol id="fl-en" viewBox="0 0 60 30"><clipPath id="fl-s"><path d="M0,0v30h60v-30z"/></clipPath><clipPath id="fl-t"><path d="M30,15h30v15zv15h-30zh-30v-15zv-15h30z"/></clipPath>'
        . '<g clip-path="url(#fl-s)"><path d="M0,0v30h60v-30z" fill="#012169"/><path d="M0,0L60,30M60,0L0,30" stroke="#fff" stroke-width="6"/>'
        . '<path d="M0,0L60,30M60,0L0,30" clip-path="url(#fl-t)" stroke="#c8102e" stroke-width="4"/><path d="M30,0v30M0,15h60" stroke="#fff" stroke-width="10"/>'
        . '<path d="M30,0v30M0,15h60" stroke="#c8102e" stroke-width="6"/></g></symbol></defs></svg>';
}

function flagge(string $c): string
{
    return '<svg class="fl" width="24" height="16" viewBox="0 0 24 16" aria-hidden="true"><use href="#fl-' . h($c) . '" width="24" height="16"/></svg>';
}

/** Sprachauswahl als Aufklappliste mit Flaggen, ohne JavaScript. */
function sprachauswahl(): string
{
    $l = '<form method="post"><input type="hidden" name="aktion" value="sprache">' . csrfFeld();
    foreach (Sprache::ERLAUBT as $c => $n) {
        $l .= '<button type="submit" name="lang" value="' . h($c) . '"' . ($c === Sprache::code() ? ' class="aktiv"' : '') . '>' . flagge($c) . '<span>' . h($n) . '</span></button>';
    }
    $l .= '</form>';
    return flaggenSymbole() . '<div class="sprachbar"><details class="sw"><summary aria-label="' . h(t('sprache.waehlen')) . '">'
        . flagge(Sprache::code()) . '<span>' . h(strtoupper(Sprache::code())) . '</span><span class="pfeil">▾</span></summary><div class="liste">' . $l . '</div></details></div>';
}

function seite(string $inhalt, bool $mitSprache = true): void
{
    echo '<!doctype html><html lang="' . h(Sprache::code()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h(t('app.titel')) . '</title><style>'
        . 'body{font:16px/1.5 system-ui,sans-serif;margin:0;background:#f4f5f7;color:#1c2330}'
        . 'main{max-width:640px;margin:0 auto;padding:24px 16px}'
        . 'h1{font-size:1.5rem;margin:0 0 4px}.sub{color:#5b6472;margin:0 0 24px}'
        . '.box{background:#fff;border:1px solid #dde1e7;border-radius:10px;padding:20px;margin-bottom:16px}'
        . 'label{display:block;font-weight:600;margin-bottom:6px}'
        . 'input[type=text],input[type=password]{width:100%;box-sizing:border-box;padding:10px;border:1px solid #b9c0cb;border-radius:8px;font:inherit}'
        . 'button{margin-top:14px;padding:10px 18px;border:0;border-radius:8px;background:#2358c4;color:#fff;font:inherit;cursor:pointer}'
        . 'button.zweit{background:#e6e9ef;color:#1c2330}button.aktiv{outline:2px solid #2358c4}'
        . '.sp{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.sp button{margin-top:0}'
        . '.z{display:flex;gap:12px;padding:10px 0;border-top:1px solid #eceff3}.z:first-child{border-top:0}'
        . '.pt{flex:none;width:14px;height:14px;border-radius:50%;margin-top:6px}'
        . '.ok{background:#2e9e5b}.warn{background:#e0a100}.fehler{background:#d23a3a}'
        . '.t{font-weight:600}.fe{color:#d23a3a}.hint{color:#5b6472;font-size:.9rem}'
        . '.sprachbar{display:flex;justify-content:flex-end;margin-bottom:8px}.sw{position:relative}'
        . '.sw summary{list-style:none;cursor:pointer;display:inline-flex;gap:8px;align-items:center;padding:6px 10px;background:#fff;border:1px solid #b9c0cb;border-radius:8px;font-size:.9rem}'
        . '.sw summary::-webkit-details-marker{display:none}.sw .pfeil{color:#5b6472}'
        . '.sw .liste{position:absolute;right:0;top:100%;margin-top:4px;background:#fff;border:1px solid #dde1e7;border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,.12);z-index:5;min-width:170px;padding:4px}'
        . '.sw .liste button{display:flex;gap:10px;align-items:center;width:100%;margin:0;padding:8px 10px;background:transparent;color:#1c2330;border-radius:6px;text-align:left}'
        . '.sw .liste button:hover,.sw .liste button.aktiv{background:#e6e9ef}.fl{flex:none;border:1px solid #dde1e7;border-radius:2px}'
        . '</style></head><body><main>' . ($mitSprache ? sprachauswahl() : '') . $inhalt . '</main></body></html>';
}

// ---------- Sprache wählen ----------
if (($_POST['aktion'] ?? '') === 'sprache' && csrfOk()) {
    $c = (string) ($_POST['lang'] ?? '');
    if (Sprache::gueltig($c)) {
        $_SESSION['lang'] = $c;
        @file_put_contents(DATEN . '/sprache', $c . "\n");
        @chmod(DATEN . '/sprache', 0600);
    }
    header('Location: /');
    exit;
}

$sprache = gewaehlteSprache();
if ($sprache === null) {
    // Allererste Seite: Sprache wählen, noch vor dem Startcode. Vorauswahl aus dem Browser.
    Sprache::setze(Sprache::vomBrowser());
    $knoepfe = '<form method="post" class="sp" style="flex-direction:column;align-items:stretch"><input type="hidden" name="aktion" value="sprache">' . csrfFeld();
    foreach (Sprache::ERLAUBT as $c => $n) {
        $knoepfe .= '<button type="submit" name="lang" value="' . h($c) . '" class="' . ($c === Sprache::code() ? '' : 'zweit') . '" style="display:flex;gap:10px;align-items:center;justify-content:center">' . flagge($c) . '<span>' . h($n) . '</span></button>';
    }
    $knoepfe .= '</form>';
    seite(flaggenSymbole() . '<h1>ChurchForge Setup</h1><p class="sub">' . h(t('sprache.waehlen')) . ' · ' . h(t('sprache.hinweis')) . '</p><div class="box">' . $knoepfe . '</div>', false);
    exit;
}
Sprache::setze($sprache);

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
            $meldung = t('login.gesperrt');
        } else {
            $eingabe = trim((string) ($_POST['code'] ?? ''));
            $soll = trim((string) @file_get_contents(DATEN . '/.startcode.sha256'));
            if ($soll !== '' && $eingabe !== ''
                && (hash_equals($soll, hash('sha256', $eingabe)) || hash_equals($soll, hash('sha256', strtoupper($eingabe))))) {
                session_regenerate_id(true);
                $_SESSION['ok'] = true;
                protokoll('anmeldung');
                header('Location: /');
                exit;
            }
            @file_put_contents(DATEN . '/.startcode.fehlversuche', (string) ($n + 1));
            protokoll('anmeldung_fehlgeschlagen');
            sleep(1);
            $meldung = t('login.falsch');
        }
    }
    $f = $meldung !== '' ? '<p class="fe">' . h($meldung) . '</p>' : '';
    seite('<h1>ChurchForge Setup</h1><p class="sub">' . h(t('login.willkommen')) . '</p>'
        . '<div class="box"><form method="post"><input type="hidden" name="aktion" value="anmelden">' . csrfFeld()
        . '<label for="code">' . h(t('login.startcode')) . '</label><input id="code" name="code" type="text" autocomplete="off" autofocus placeholder="XXXX-XXXX">'
        . $f . '<p class="hint">' . h(t('login.hinweis')) . '</p>'
        . '<button type="submit">' . h(t('weiter')) . '</button></form></div>');
    exit;
}

// ---------- Assistent ----------
$a = Antworten::lade();
$schritt = (string) ($_SESSION['schritt'] ?? 'art');
if (!Assistent::gueltig($schritt)) {
    $schritt = 'art';
}
$fehler = [];
$info = '';
if (($_POST['aktion'] ?? '') === 'schritt' && csrfOk() && (string) ($_POST['schritt'] ?? '') === $schritt) {
    $nav = (string) ($_POST['nav'] ?? 'weiter');
    if ($nav === 'zurueck') {
        $schritt = Assistent::vorher($schritt);
    } elseif ($nav === 'erneut') {
        // gleiche Seite noch einmal zeigen
    } else {
        [$fehler, $info] = Assistent::verarbeite($schritt, $_POST, $a, $nav === 'test');
        if (!$fehler) {
            Antworten::speichere($a);
            protokoll('schritt', ['s' => $schritt]);
            if ($nav === 'weiter') {
                $schritt = Assistent::nachher($schritt);
            }
        }
    }
    $_SESSION['schritt'] = $schritt;
}

$html = Assistent::seite($schritt, $a, $fehler, $info)
    . '<form method="post" style="margin-top:12px"><input type="hidden" name="aktion" value="abmelden">' . csrfFeld() . '<button class="zweit" type="submit">' . h(t('abmelden')) . '</button></form>';
seite($html);
