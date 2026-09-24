# OMUN – Website

Website der **Oskar von Miller Model United Nations** (omun.de).
Schlankes PHP ohne Datenbank und ohne Framework. Alle Inhalte liegen als JSON-Datei auf dem Server
und werden über **/admin** gepflegt.

## Aufbau

| Pfad | Inhalt |
| --- | --- |
| `index.php` | Router für alle öffentlichen Seiten |
| `templates/` | HTML-Vorlagen der Seiten |
| `assets/` | CSS, JavaScript, Schriften (lokal gehostet, DSGVO-freundlich), Favicon |
| `admin/` | Verwaltungsbereich |
| `lib/schema.php` | Welche Inhalte es gibt und welche Felder im Admin erscheinen |
| `lib/defaults.json` | Startinhalte beim allerersten Aufruf |
| `data/` | **Live-Daten**: Inhalte, Passwort-Hash, Anmeldungen (per `.htaccess` gesperrt) |
| `uploads/` | Hochgeladene Bilder und Dateien |

## Auf Strato hochladen

Voraussetzung: ein Strato-Hosting-Paket mit **PHP 8.1 oder neuer** (im Strato-Kundenmenü unter
*Einstellungen → PHP-Version* einstellen).

1. Im Strato-Kundenmenü **SSL** für omun.de aktivieren (ist in den Paketen enthalten).
2. Per **SFTP** (z. B. mit FileZilla, Zugangsdaten im Kundenmenü unter *SFTP/SSH*) den kompletten
   Inhalt dieses Repositorys in das Verzeichnis hochladen, auf das die Domain zeigt.
   Wichtig: auch die versteckten Dateien `.htaccess` mit hochladen.
3. Sicherstellen, dass die Ordner `data/` und `uploads/` beschreibbar sind (normalerweise
   automatisch der Fall, sonst Rechte auf `755` setzen).
4. **Sofort** `https://omun.de/admin` aufrufen und das Admin-Passwort festlegen.
   Solange noch kein Passwort gesetzt ist, kann das jeder tun, der die Seite aufruft.
5. Im Admin unter *Impressum & Datenschutz* die Platzhalter in `[eckigen Klammern]` ersetzen und
   die Texte von der Schule prüfen lassen.
6. Unter *Anmeldung* eine E-Mail-Adresse für Benachrichtigungen eintragen.

### Updates einspielen

Bei späteren Code-Updates einfach alle Dateien **außer** `data/` und `uploads/` neu hochladen.
Diese beiden Ordner enthalten die Live-Inhalte und dürfen nicht überschrieben werden.

## Admin-Bereich

Erreichbar nur über `omun.de/admin` (nirgends verlinkt). Dort lässt sich bearbeiten:

- Allgemeines & Design (Name, Kontakt, Farben, Logo, Hinweisleiste)
- Startseite, Konferenz (Termine, Ort, Zeitplan), Anmeldeformular (öffnen/schließen)
- Gremien inkl. Study Guides, Team, News, Galerie, FAQ, Downloads, Sponsoren, Archiv
- beliebige weitere Seiten (z. B. „Host Families“), wahlweise im Menü oder Footer
- Impressum & Datenschutz
- Dateien & Bilder (Upload, Übersicht, wo verwendet)
- Anmeldungen ansehen, als CSV/Excel exportieren, löschen
- Passwort ändern, Backup herunterladen/wiederherstellen

Passwort vergessen oder alle 2FA-Geräte verloren? Per SFTP die Datei `data/auth.json` löschen und
danach unter `/admin` ein neues Passwort setzen (Zwei-Faktor-Login ist danach aus und muss neu eingerichtet werden).

## Sicherheit

- **Login:** Passwort nur als bcrypt-Hash gespeichert; max. 8 Versuche pro 15 Minuten und IP-Adresse
- **Zwei-Faktor-Login (TOTP):** unter *Sicherheit & Backup*; beliebig viele Authenticator-Apps
  (jedes Gerät einzeln entfernbar), dazu 8 einmalige Notfall-Codes; ein Code kann nicht zweimal benutzt werden
- **Sitzung:** HttpOnly/SameSite-Cookie, Abmeldung nach 3 h Inaktivität; Passwortwechsel meldet alle Geräte ab
- **CSRF-Schutz** für jede Aktion im Admin, keine Einbettung in fremde Seiten, `noindex`
- **Versionen:** vor jeder Änderung wird der vorherige Stand gesichert (letzte 50), Wiederherstellen mit einem Klick
- **Uploads:** nur Bilder, PDF und Office-Dateien; im Upload-Ordner wird kein Code ausgeführt
- **Ausgabe:** alle Inhalte werden HTML-escaped, Markdown erlaubt kein eigenes HTML
- **Anmeldeformular:** Honeypot, Mindest-Ausfüllzeit, max. 10 Anmeldungen pro Stunde und IP;
  Anmeldungen werden automatisch X Tage nach Konferenzende gelöscht (einstellbar, Standard 90)
- **Nach dem Hochladen prüfen:** `https://omun.de/data/auth.json` muss **403 Forbidden** liefern.

## Lokal testen

```bash
php -S localhost:8000 index.php
```

Dann http://localhost:8000 und http://localhost:8000/admin/ öffnen.
