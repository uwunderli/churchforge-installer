# churchforge-installer

Installer für ChurchForge (Installer-Skript, Compose-Vorlagen, cf-setup). Öffentliches Repo.

`main` ist die Liefer-Linie, Tags sind Releases.

**Nie hier hinein:** Zugangsdaten, Schlüssel, Tokens, interne Adressen, Berichte.

## Stand M1 (0.1.0-m1): Hülle
Anmeldung mit Startcode und Vorprüfung; installiert noch nichts.

1. In `compose.yaml` bei Bedarf den Port links ändern (`8080`).
2. `docker compose up -d --build`
3. Startcode lesen: `docker logs cf-setup`
4. Im Browser `http://<IP>:8080` öffnen, Startcode eingeben, «Prüfen».

Der Container braucht `docker.sock`; den Port nur im lokalen Netz erreichbar machen.
Startcode gilt bis zum nächsten Start, nach 10 Fehlversuchen gesperrt bis zum Neustart.
