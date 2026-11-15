<?php
// Minimaler SMTP-Client nur für die Testmail. Gibt nie das Passwort aus; Fehlermeldungen sind Serverantworten.
declare(strict_types=1);

final class Smtp
{
    /** @var resource|null */
    private $s = null;

    /**
     * @param array{host:string,port:int,sicher:string,user:string,pw:string,absender:string} $c
     * @return array{0:bool,1:string}
     */
    public static function test(array $c, string $an, string $betreff, string $text): array
    {
        $x = new self();
        try {
            $x->verbinde($c);
            $x->sende($c['absender'], $an, $betreff, $text);
            return [true, ''];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        } finally {
            $x->schliesse();
        }
    }

    private function verbinde(array $c): void
    {
        $ziel = ($c['sicher'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'];
        $this->s = @stream_socket_client($ziel, $en, $es, 10);
        if (!is_resource($this->s)) {
            throw new RuntimeException("Verbindung nicht möglich ($es)");
        }
        stream_set_timeout($this->s, 15);
        $this->erwarte('220');
        $this->befehl('EHLO cf-setup', '250');
        if ($c['sicher'] === 'starttls') {
            $this->befehl('STARTTLS', '220');
            if (!@stream_socket_enable_crypto($this->s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('TLS konnte nicht gestartet werden');
            }
            $this->befehl('EHLO cf-setup', '250');
        }
        if ($c['user'] !== '') {
            $this->befehl('AUTH LOGIN', '334');
            $this->befehl(base64_encode($c['user']), '334');
            $this->befehl(base64_encode($c['pw']), '235');
        }
    }

    private function sende(string $von, string $an, string $betreff, string $text): void
    {
        $this->befehl('MAIL FROM:<' . $von . '>', '250');
        $this->befehl('RCPT TO:<' . $an . '>', '250');
        $this->befehl('DATA', '354');
        $kopf = "From: <$von>\r\nTo: <$an>\r\nSubject: =?UTF-8?B?" . base64_encode($betreff) . "?=\r\n"
            . "Date: " . gmdate('r') . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        fwrite($this->s, $kopf . chunk_split(base64_encode($text)) . "\r\n.\r\n");
        $this->erwarte('250');
        $this->befehl('QUIT', '221', false);
    }

    private function befehl(string $b, string $erwartet, bool $pruefen = true): void
    {
        fwrite($this->s, $b . "\r\n");
        if ($pruefen) {
            $this->erwarte($erwartet);
        }
    }

    private function erwarte(string $code): void
    {
        $z = '';
        do {
            $z = fgets($this->s, 1024);
            if ($z === false) {
                throw new RuntimeException('Keine Antwort vom Server');
            }
        } while (isset($z[3]) && $z[3] === '-');
        if (!str_starts_with($z, $code)) {
            throw new RuntimeException(trim(substr($z, 0, 200)));
        }
    }

    private function schliesse(): void
    {
        if (is_resource($this->s)) {
            @fclose($this->s);
        }
    }
}
