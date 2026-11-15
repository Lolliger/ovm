#!/bin/bash
# Kennungen-Generator starten: Doppelklick, dann öffnet sich der Browser.
# Die Liste (Kennung + Name) liegt auf dem Schreibtisch: OMUN-Kennungen.csv
cd "$(dirname "$0")"
PHP="$(command -v php || true)"
[ -z "$PHP" ] && [ -x /opt/homebrew/bin/php ] && PHP=/opt/homebrew/bin/php
[ -z "$PHP" ] && [ -x /usr/local/bin/php ] && PHP=/usr/local/bin/php
if [ -z "$PHP" ]; then
  echo "PHP wurde nicht gefunden. Bitte zuerst im Terminal ausführen: brew install php"
  read -r -p "Enter zum Schließen …"
  exit 1
fi
echo "Kennungen-Generator: http://127.0.0.1:8765"
echo "Liste:               ${OMUN_KENNUNGEN_FILE:-$HOME/Desktop/OMUN-Kennungen.csv}"
echo "Zum Beenden: dieses Fenster schließen."
(sleep 1; open http://127.0.0.1:8765) &
"$PHP" -S 127.0.0.1:8765 kennungen-server.php
