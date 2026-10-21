@echo off
chcp 65001 >nul
taskkill /f /t /fi "WINDOWTITLE eq UHR NFC Bridge*" >nul 2>&1
del /f /q "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\UHR-NFC-Bridge.vbs" >nul 2>&1
powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name='powershell.exe'\" | Where-Object { ($_.CommandLine -like '*UHR-NFC-Bridge\bridge.ps1*' -or $_.CommandLine -like '*UHR-NFC-Bridge.bat*') } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }" >nul 2>&1
echo  UHR NFC Bridge removed. / أُزيل الجسر.
pause
