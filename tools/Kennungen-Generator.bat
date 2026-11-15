@echo off
rem Kennungen-Generator starten: Doppelklick, dann oeffnet sich der Browser.
rem Die Liste (Kennung + Name) liegt auf dem Desktop: OMUN-Kennungen.csv
cd /d "%~dp0"
set PHP=php
where php >nul 2>nul || set PHP=C:\php\php.exe
echo Kennungen-Generator: http://127.0.0.1:8765
echo Zum Beenden dieses Fenster schliessen.
start "" http://127.0.0.1:8765
"%PHP%" -S 127.0.0.1:8765 kennungen-server.php
pause
