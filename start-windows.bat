@echo off
rem OMUN lokal starten: Doppelklick, dann oeffnet sich der Browser.
cd /d "%~dp0"
set PHP=php
where php >nul 2>nul || set PHP=C:\php\php.exe
if not exist "%PHP%" if "%PHP%"=="C:\php\php.exe" (
  echo PHP wurde nicht gefunden.
  echo Bitte PHP nach C:\php entpacken ^(siehe README.md, Abschnitt "Lokal testen"^).
  pause
  exit /b 1
)
echo Website:  http://localhost:8000
echo Admin:    http://localhost:8000/admin
echo Zum Beenden dieses Fenster schliessen.
start "" http://localhost:8000
"%PHP%" -S localhost:8000 index.php
pause
