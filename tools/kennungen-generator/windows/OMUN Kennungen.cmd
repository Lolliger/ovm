@echo off
rem OMUN Kennungen starten: Doppelklick. Der Browser oeffnet sich; zum Beenden dieses Fenster schliessen.
title OMUN Kennungen
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0OMUN-Kennungen.ps1"
if errorlevel 1 pause
