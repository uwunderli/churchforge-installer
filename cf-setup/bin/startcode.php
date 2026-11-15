<?php
// Erzeugt bei jedem Start einen neuen Startcode. Gespeichert wird nur der Hash (0600); der Code selbst
// erscheint einmal im Container-Log (docker logs cf-setup). Gültig bis zum nächsten Start.
declare(strict_types=1);

$daten = '/daten';
if (!is_dir($daten) && !mkdir($daten, 0700, true)) {
    fwrite(STDERR, "cf-setup: Datenordner $daten fehlt und lässt sich nicht anlegen.\n");
    exit(1);
}
// Wahlweise legt die Person den Startcode selbst fest (Umgebungsvariable CF_STARTCODE, mindestens 8 Zeichen).
// Dann steht er nie im Log und bleibt auch bei vollem Log (z. B. in Arcane) auffindbar.
$fest = getenv('CF_STARTCODE');
$eigener = is_string($fest) && strlen(trim($fest)) >= 8;
if ($eigener) {
    $code = trim($fest);
} else {
    $h = strtoupper(bin2hex(random_bytes(4)));
    $code = substr($h, 0, 4) . '-' . substr($h, 4, 4);
}
$datei = $daten . '/.startcode.sha256';
file_put_contents($datei, hash('sha256', $code) . "\n");
chmod($datei, 0600);
@unlink($daten . '/.startcode.fehlversuche');
echo "\n==============================================\n";
echo " ChurchForge Setup bereit.\n";
echo $eigener
    ? " Startcode: der Wert von CF_STARTCODE aus deiner Start-Datei.\n"
    : " Startcode (einmalig bis zum nächsten Start): $code\n";
echo "==============================================\n\n";
