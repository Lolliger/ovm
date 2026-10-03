# OMUN – Website

Website der **Oskar von Miller Model United Nations** (omun.eu).
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

1. Im Strato-Kundenmenü **SSL** für omun.eu aktivieren (ist in den Paketen enthalten).
2. Per **SFTP** (z. B. mit FileZilla, Zugangsdaten im Kundenmenü unter *SFTP/SSH*) den kompletten
   Inhalt dieses Repositorys in das Verzeichnis hochladen, auf das die Domain zeigt.
   Wichtig: auch die versteckten Dateien `.htaccess` mit hochladen.
3. Sicherstellen, dass die Ordner `data/` und `uploads/` beschreibbar sind (normalerweise
   automatisch der Fall, sonst Rechte auf `755` setzen).
4. **Sofort** `https://omun.eu/admin` aufrufen und das Admin-Passwort festlegen.
   Solange noch kein Passwort gesetzt ist, kann das jeder tun, der die Seite aufruft.
5. Im Admin unter *Impressum & Datenschutz* die Platzhalter in `[eckigen Klammern]` ersetzen und
   die Texte von der Schule prüfen lassen.
6. Unter *Anmeldung* eine E-Mail-Adresse für Benachrichtigungen eintragen.

### Updates einspielen

Im Admin unter **Update** die Update-Zip hochladen und mit dem Admin-Passwort bestätigen. Der Server
ersetzt die Programmdateien selbst; `data/` und `uploads/` (Inhalte, Passwort, Anmeldungen, Bilder)
werden nie verändert. Vorher wird die laufende Version als Zip in `data/code-backups/` gesichert
(letzte 5, im Admin herunterladbar – zum Zurückspringen einfach wieder als Update einspielen).

**Niemals den ganzen Webspace-Ordner löschen** – sonst sind Inhalte, Passwort und Anmeldungen weg.
Falls der Admin nicht erreichbar ist: per Dateimanager alle Dateien **außer** `data/` und `uploads/`
ersetzen.

## Admin-Bereich

Erreichbar nur über `omun.eu/admin` (nirgends verlinkt). Dort lässt sich bearbeiten:

- Allgemeines & Design (Name, Kontakt, Farben, Logo, Hinweisleiste)
- Startseite, Konferenz (Termine, Ort, Zeitplan), Anmeldeformular (öffnen/schließen)
- Gremien inkl. Study Guides, Team, News, Galerie, FAQ, Downloads, Sponsoren, Archiv
- beliebige weitere Seiten (z. B. „Host Families“), wahlweise im Menü oder Footer
- Impressum & Datenschutz
- Dateien & Bilder (Upload, Übersicht, wo verwendet)
- Anmeldungen ansehen, Status/Land/Gremium zuteilen, Position Papers herunterladen, als CSV/Excel exportieren, löschen
- Teilnehmer-Bereich: Bestätigungs-Mail, Portal-Texte, Abgabefrist für Position Papers
- Passwort ändern, Backup herunterladen/wiederherstellen

Passwort vergessen oder alle 2FA-Geräte verloren? Per SFTP die Datei `data/auth.json` löschen und
danach unter `/admin` ein neues Passwort setzen (Zwei-Faktor-Login ist danach aus und muss neu eingerichtet werden).

## Teilnehmer-Bereich (conference.omun.eu)

Login unter **omun.eu/login** (Button „Login“ im Header), danach geht es auf **conference.omun.eu**.
Die Adresse ist im Admin unter *Teilnehmer-Bereich* einstellbar. Voraussetzung bei Strato: die Subdomain
`conference.omun.eu` anlegen, auf **denselben Ordner** wie omun.eu zeigen lassen und SSL aktivieren –
nur so teilen sich beide Daten und Login. Feld leer lassen = alles unter omun.eu/portal (Standard, solange Strato
für die Subdomain kein SSL-Zertifikat ausliefert; `http://conference.omun.eu` leitet dann auf omun.eu/portal weiter).

Nach der Anmeldung bekommen Teilnehmende eine Bestätigungs-Mail mit **Zugangsdaten**:
Benutzername = ihre E-Mail-Adresse, Passwort = automatisch erzeugt (z. B. `Kavo-Rimu-Teza-Lopa-47`), die Mail verlinkt auf omun.eu/login.
Wer sich mit derselben Adresse erneut anmeldet, behält sein Passwort. Im Portal sehen sie Status und
Zuteilung (Land, Gremium inkl. Study Guide), können ihr Position Paper hochladen (PDF/Word, max. 10 MB,
bis zur Frist ersetzbar) und ihr Passwort ändern. „Passwort vergessen?“ schickt einen einmaligen
Login-Link (30 Minuten gültig). Im Admin gibt es pro Anmeldung „Neue Zugangsdaten senden“.

Passwörter werden nur als Hash gespeichert (`data/accounts.json`), Papers geschützt in `data/papers/`;
beides wird mit der Anmeldung gelöscht.

Absender-Adresse und -Name der Mails sind im Admin unter *Teilnehmer-Bereich* einstellbar (Standard
`noreply@omun.eu`; am besten eine Adresse mit echtem Strato-Postfach).

### Chairs, Conference Manager und Laptop-Konto

- **Chair:** Wer im Anmeldeformular „Chair“ wählt, sieht ein Hinweis-Popup (Text unter *Anmeldung* einstellbar) und ein
  kurzes Formular (ohne Schule, Klasse, Erfahrung und Wünsche, nur Gremium). Zugangsdaten kommen sofort per Mail;
  Chair-Rechte für das gewählte Gremium gibt es erst, wenn die Anmeldung im Admin unter *Anmeldungen →
  Warten auf Bestätigung* bestätigt wurde.
- **Conference Manager:** gleiches kurzes Formular ohne Gremium. Nach der Bestätigung können sie alle Resolutionen
  und Beamer-Ansichten sehen, aber nichts ändern.
- **Laptop-Konto:** Login mit Benutzername `laptops` (Start-Passwort wurde dem Team mitgeteilt). Sieht alle Gremien
  inkl. Beamer-Ansicht, ändert nichts. Passwort im Admin unter *Resolutionen* ändern (liegt in `data/staff-accounts.json`).

Beim lokalen Testen werden keine Mails verschickt, sondern in `data/mail-outbox/` abgelegt.

## Resolution Editor (conference.omun.eu/resolution)

Pro Gremium eine Resolution. Wer im Admin einem Gremium zugeteilt ist, sieht im Teilnehmer-Bereich dessen
Resolution; **Chairs** werden pro Anmeldung mit dem Häkchen „Chair“ festgelegt, Admins haben überall Chair-Rechte
(Übersicht im Admin unter *Resolutionen*).

1. **Draft:** Chairs wählen den Main Submitter; er (und die Chairs) schreiben das Dokument im MUN-Format
   (Preambular Clauses, nummerierte Operative Clauses mit a) / i)).
2. **Debate:** nur Chairs bearbeiten das Dokument direkt (alle sehen Änderungen live). Delegierte reichen
   Amendments ein (Klausel ändern / hinzufügen / streichen) – sichtbar nur für sie selbst (im Dokument markiert)
   und als Liste für die Chairs. Chairs bringen ein Amendment „on the floor“ (Beamer-Ansicht, immer mit Land),
   nehmen an oder lehnen ab. Zum Amendment auf dem Floor kann jeder ein Amendment 2. Grades (neue Formulierung)
   einreichen: angenommen → das ursprüngliche Amendment wird mit dieser Formulierung angenommen;
   abgelehnt → zurück zum ursprünglichen Amendment.
3. **Closed:** schreibgeschützt.

Beamer-Ansicht (`?view=screen`, nur Chairs/Admins): aktuelles Amendment bzw. Dokument links, Speakers List
rechts, aktualisiert sich alle 2 Sekunden. Druck/PDF über `?view=print`. Daten: `data/resolutions/<gremium>.json`.

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
- **Nach dem Hochladen prüfen:** `https://omun.eu/data/auth.json` muss **403 Forbidden** liefern.

## Lokal testen

Einmalig PHP installieren:

- **Mac:** Homebrew installieren (brew.sh), dann im Terminal `brew install php`
- **Windows:** auf windows.php.net/download die Version 8.x als „VS17 x64 Thread Safe“ Zip laden und nach
  `C:\php` entpacken. Dort `php.ini-development` kopieren, die Kopie in `php.ini` umbenennen und darin das `;` vor
  `extension_dir = "ext"`, `extension=mbstring`, `extension=fileinfo` und `extension=gd` entfernen.

Danach per Doppelklick starten: **`start-windows.bat`** bzw. **`start-mac.command`**
(Mac beim ersten Mal: Rechtsklick → Öffnen). Der Browser öffnet http://localhost:8000, der Admin liegt unter
http://localhost:8000/admin.

Oder im Terminal im Projektordner: `php -S localhost:8000 index.php`

Lokale Änderungen landen nur im lokalen `data/`- und `uploads/`-Ordner und haben keine Auswirkung auf die echte Website.
