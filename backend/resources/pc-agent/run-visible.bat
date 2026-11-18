@echo off
chcp 65001 >nul
title UHR-Agent (diagnosis)
powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name='powershell.exe'\" | Where-Object { $_.CommandLine -like '*UHR-Agent\uhr-agent.ps1*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }" >nul 2>&1
powershell -NoProfile -STA -ExecutionPolicy Bypass -File "%~dp0uhr-agent.ps1"
pause
