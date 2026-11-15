# OMUN Kennungen

Das Programm erzeugt die persönlichen Kennungen, mit denen sich Teilnehmende auf der Website anmelden.

- **Namen bleiben auf dem eigenen Computer.** Kennung, Name und Datum stehen in `OMUN-Kennungen.csv` auf dem Schreibtisch (mit Excel/Numbers zu öffnen).
- **An die Website gehen nur die Kennungen** – sofort beim Erzeugen, wenn die Verbindung eingerichtet ist.
- Keine Kennung wird doppelt vergeben: vor jedem Erzeugen liest das Programm die Liste neu.

## Starten

| System | So geht's |
| --- | --- |
| **Mac** | `dist/OMUN-Kennungen-Mac.zip` entpacken und **OMUN Kennungen** öffnen. Beim ersten Mal: Rechtsklick → „Öffnen“ (das Programm ist nicht von Apple beglaubigt). Erscheint stattdessen „kann nicht geöffnet werden“: Systemeinstellungen → Datenschutz & Sicherheit → „Dennoch öffnen“. |
| **Windows** | Den ganzen Ordner `kennungen-generator` kopieren und `windows/OMUN Kennungen.cmd` doppelklicken. Der Browser öffnet sich; zum Beenden das schwarze Fenster schließen. Es muss nichts installiert werden. |

## Mit der Website verbinden (einmalig)

1. Auf der Website: **Admin → Kennungen → „Schlüssel erzeugen“** und den Schlüssel kopieren (er wird nur einmal angezeigt).
2. Im Programm unter **Verbindung zur Website** die Adresse (z. B. `https://omun.eu`) und den Schlüssel eintragen, „Speichern und Verbindung testen“.

Ohne Verbindung funktioniert das Programm trotzdem; die Kennungen lädt man dann als TXT-Datei im Admin hoch.

## Weitergeben (z. B. an das Team im nächsten Jahr)

- Mac-Zip bzw. den Ordner weitergeben – **nicht** die Datei `OMUN-Kennungen.csv` (sie enthält Namen) und nicht den Schlüssel.
- Die neue Person erzeugt im Admin einen eigenen Schlüssel; der alte gilt dann nicht mehr.
- Neues Jahr = neue Liste: die alte `OMUN-Kennungen.csv` vom Schreibtisch wegräumen (oder löschen, wenn sie nicht mehr gebraucht wird).

## Für Entwickler

| Datei | Zweck |
| --- | --- |
| `app/index.html` | Die Oberfläche (für Mac und Windows dieselbe) |
| `mac/main.swift`, `mac/List.swift`, `mac/build.sh` | Mac-App: Fenster mit der Oberfläche, Zugriff auf Liste/Einstellungen/Drucken. Bauen: `./mac/build.sh` (braucht `xcode-select --install`) → `dist/` |
| `windows/OMUN-Kennungen.ps1`, `windows/OMUN Kennungen.cmd` | Windows: kleiner lokaler Helfer (PowerShell, nur 127.0.0.1) + Start-Datei |
| Website: `lib/codes_api.php` | Gegenstück: `POST /api/codes` mit `Authorization: Bearer <Schlüssel>` |

Einstellungen (Adresse + Schlüssel): Mac `~/Library/Application Support/OMUN Kennungen/settings.json`, Windows `%APPDATA%\OMUN Kennungen\settings.json`.
