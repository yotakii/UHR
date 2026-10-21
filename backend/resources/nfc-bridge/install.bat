@echo off
chcp 65001 >nul
title UHR NFC Bridge - install
set "DEST=%LOCALAPPDATA%\UHR-NFC-Bridge"
set "STARTUP=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
taskkill /f /fi "WINDOWTITLE eq UHR NFC Bridge*" >nul 2>&1
powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name='powershell.exe'\" | Where-Object { $_.CommandLine -like '*UHR-NFC-Bridge\bridge.ps1*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }" >nul 2>&1
if not exist "%DEST%" mkdir "%DEST%"
copy /y "%~dp0bridge.ps1" "%DEST%\bridge.ps1" >nul
copy /y "%~dp0uhr-bridge.ini" "%DEST%\uhr-bridge.ini" >nul
copy /y "%~dp0uninstall.bat" "%DEST%\uninstall.bat" >nul
copy /y "%~dp0run-visible.bat" "%DEST%\run-visible.bat" >nul
> "%STARTUP%\UHR-NFC-Bridge.vbs" echo CreateObject("WScript.Shell").Run "powershell -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File ""%DEST%\bridge.ps1""", 0, False
wscript "%STARTUP%\UHR-NFC-Bridge.vbs"
echo.
echo  UHR NFC Bridge installed - it now runs in the background and starts with Windows.
echo  تم التثبيت: الجسر يعمل بالخلفية ويبدأ تلقائياً مع ويندوز. يمكنك حذف هذا المجلد.
echo.
echo  Log: %DEST%\bridge.log
pause
