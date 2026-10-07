# Update-Zip für omun.eu – Anleitung für Entwickler (und andere Claudes)

Die Website läuft bei **Strato** (PHP, ohne Datenbank). Neue Programmversionen werden **nicht per FTP**
hochgeladen, sondern als Zip über den Admin eingespielt: **omun.eu/admin → Update**. Die Zip wird dort hochgeladen
und mit dem Admin-Passwort bestätigt, dann ersetzt der Server die Dateien selbst.

Kurz gesagt: **Die Update-Zip ist der komplette Projektordner aus Git, ohne `data/` und `uploads/`.**

## 0. Einfachster Weg: über GitHub

Seit Version 2026.10.07.8 holt sich der Server Updates selbst von GitHub (`lolliger/ovm`, Branch siehe Admin → Update).
Eine Zip ist dann nicht mehr nötig:

1. Änderungen committen, **`VERSION` erhöhen**, auf den Branch pushen (vorher `git pull`, es arbeiten mehrere daran).
2. Einspielen, entweder
   - durch einen Menschen: **Admin → Update → „Auf GitHub nach Updates suchen“ → einspielen**, oder
   - durch Claude über den MCP-Connector „OMUN Website“ (falls verbunden): `check_github`, dann
     `install_update` mit `expected_version` = der gerade gepushten Versionsnummer.
3. Bei Problemen: `restore_backup` bzw. Admin → Update → Sicherung einspielen.

Nur fertige, getestete Stände pushen: Was auf dem Branch liegt, kann jederzeit live gehen.

---

## 1. So erstellst du die Zip

```bash
# 1. Änderungen machen, testen (php -S localhost:8000 index.php) und committen
# 2. Versionsnummer erhöhen – Format JJJJ.MM.TT.N, z. B.:
echo 2026.10.04.1 > VERSION
git commit -am "Version 2026.10.04.1"
# 3. Zip aus dem aktuellen Commit bauen
git archive --format=zip -o omun-update.zip HEAD
```

`git archive` nimmt genau die Dateien aus Git, also auch die versteckten `.htaccess`, und lässt Ungetracktes weg.
Live-Daten sind per `.gitignore` sowieso nicht im Repository.

Die Zip dann der Person geben, die den Admin bedient. Sie lädt sie unter **Admin → Update** hoch.
Danach steht dort die neue Versionsnummer.

## 2. Aufbau der Zip

```
omun-update.zip
├── VERSION              ← neue Versionsnummer (wird im Admin angezeigt)
├── index.php            ← PFLICHT (Router)
├── .htaccess            ← wichtig: Rewrite-Regeln + Schutz von data/lib/templates
├── README.md, ARCHITECTURE.md, UPDATES.md, start-*.{bat,command}
├── admin/               ← Admin-Bereich (index.php, admin.css, admin.js, qrcode.js)
├── assets/              ← css/, js/, fonts/, img/
├── lib/                 ← PHP-Logik; lib/bootstrap.php ist PFLICHT
│   └── .htaccess
├── templates/           ← Seitenvorlagen
│   └── .htaccess
├── data/                ← (darf drin sein, wird aber IGNORIERT)
└── uploads/             ← (darf drin sein, wird aber IGNORIERT)
```

Eine Beispiel-Zip mit genau diesem Aufbau liegt bei: `omun-update-beispiel.zip`. Das ist ein echtes, einspielbares
Update der aktuellen Version.

## 3. Regeln, die der Server prüft (`lib/updater.php`)

| Regel | Was passiert |
| --- | --- |
| `index.php` **und** `lib/bootstrap.php` müssen drin sein | sonst: „Das ist kein Update für diese Website“ |
| Dateien direkt im Zip-Wurzelverzeichnis **oder** in einem Unterordner (`omun/index.php` …) | beides geht; der Ordner mit `lib/bootstrap.php` + `index.php` gilt als Wurzel |
| `data/…` und `uploads/…` | werden **übersprungen**: Inhalte, Passwörter, Anmeldungen, Resolutionen und Bilder bleiben unangetastet |
| `.git/`, `__MACOSX/`, `.DS_Store` | werden ignoriert |
| Pfade mit `..`, Backslash oder absolutem Pfad | Update wird **abgelehnt** (Sicherheit) |
| Ablauf | alles in einen Temp-Ordner entpacken → laufende Version als Zip sichern (`data/code-backups/`, letzte 5) → Dateien ersetzen → OPcache leeren |

### Wichtig

- **Es werden keine Dateien gelöscht.** Das Update ersetzt und ergänzt nur. Entfernst oder benennst du eine Datei
  um, bleibt die alte auf dem Server liegen. Das ist meist harmlos; sonst muss sie per Strato-Dateimanager gelöscht werden.
- **Teil-Updates** (nur geänderte Dateien) funktionieren technisch, solange `index.php` und `lib/bootstrap.php` dabei
  sind. Empfohlen ist aber immer die komplette Zip, damit nichts vergessen wird.
- **Neue Felder in den Inhalten** gehören nach `lib/defaults.json` und `lib/schema.php`. Fehlende Schlüssel werden
  beim Lesen automatisch aus den Defaults ergänzt (`content()` in `lib/bootstrap.php`, erste und zweite Ebene).
  Bestehende Inhalte auf dem Server werden durch ein Update nie überschrieben.
- **Datenformat-Änderungen** (z. B. in `data/registrations.json` oder `data/resolutions/*.json`) müssen alte Daten
  weiter lesen können. Es gibt keine Migrationen, also beim Lesen mit `?? Standardwert` arbeiten.
- **Neue Dateien, die nicht öffentlich sein dürfen** (Doku, Skripte), in `.htaccess` sperren. Bei
  `README.md`, `ARCHITECTURE.md` und `UPDATES.md` ist das schon passiert.
- **Keine Passwörter oder Zugangsdaten** ins Repository oder die Zip legen.

## 4. Wenn etwas schiefgeht

- **Admin → Update → Sicherungen**: Dort die Sicherung von vor dem Update herunterladen und **als Update wieder
  einspielen**. Damit ist die alte Version zurück.
- Ist der Admin nicht erreichbar (z. B. PHP-Fehler): im Strato-Dateimanager alle Dateien **außer `data/` und
  `uploads/`** durch den Inhalt der Sicherungs-Zip ersetzen.
- **Niemals den ganzen Webspace-Ordner löschen**, sonst sind Inhalte, Passwörter und Anmeldungen weg.

## 5. Checkliste vor dem Ausliefern

- [ ] Lokal getestet (`php -S localhost:8000 index.php`, Admin unter `/admin`)
- [ ] `php -l` ohne Fehler für geänderte PHP-Dateien
- [ ] `VERSION` erhöht
- [ ] Committet und gepusht
- [ ] `git archive --format=zip -o omun-update.zip HEAD`
- [ ] Prüfen: `unzip -l omun-update.zip | grep -E "index.php|lib/bootstrap.php|VERSION|.htaccess"`
