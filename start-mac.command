#!/bin/bash
# OMUN lokal starten: Doppelklick, dann öffnet sich der Browser.
cd "$(dirname "$0")"
if ! command -v php >/dev/null 2>&1; then
  echo "PHP wurde nicht gefunden. Bitte zuerst im Terminal ausführen: brew install php"
  read -r -p "Enter zum Schließen …"
  exit 1
fi
echo "Website:  http://localhost:8000"
echo "Admin:    http://localhost:8000/admin"
echo "Zum Beenden: Strg+C oder Fenster schließen."
(sleep 1; open http://localhost:8000) &
php -S localhost:8000 index.php
