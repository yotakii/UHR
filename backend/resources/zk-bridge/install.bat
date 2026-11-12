@echo off
chcp 65001 >nul
title UHR Setup - install ZK bridge
set "DEST=%LOCALAPPDATA%\UHR-ZK-Bridge"
set "STARTUP=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
set "PS=%SystemRoot%\SysWOW64\WindowsPowerShell\v1.0\powershell.exe"
if not exist "%PS%" set "PS=powershell"
powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name='powershell.exe'\" | Where-Object { $_.CommandLine -like '*UHR-ZK-Bridge\zk-bridge.ps1*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }" >nul 2>&1
if not exist "%DEST%" mkdir "%DEST%"
copy /y "%~dp0zk-bridge.ps1" "%DEST%\zk-bridge.ps1" >nul
copy /y "%~dp0uhr-zk.ini" "%DEST%\uhr-zk.ini" >nul
copy /y "%~dp0uninstall.bat" "%DEST%\uninstall.bat" >nul
copy /y "%~dp0run-visible.bat" "%DEST%\run-visible.bat" >nul
if exist "%DEST%\zk-state.json" del /f /q "%DEST%\zk-state.json" >nul
> "%STARTUP%\UHR-ZK-Bridge.vbs" echo CreateObject("WScript.Shell").Run """%PS%"" -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File ""%DEST%\zk-bridge.ps1""", 0, False
wscript "%STARTUP%\UHR-ZK-Bridge.vbs"
echo.
echo  UHR ZK Bridge installed - it now runs in the background and starts with Windows.
echo  تم التثبيت: الجسر يعمل بالخلفية ويبدأ تلقائياً مع ويندوز. يمكنك حذف هذا المجلد.
echo.
echo  Log: %DEST%\zk-bridge.log
pause
