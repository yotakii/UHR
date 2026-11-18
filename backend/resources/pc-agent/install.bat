@echo off
chcp 65001 >nul
title UHR Setup - install agent
set "DEST=%LOCALAPPDATA%\UHR-Agent"
set "STARTUP=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name='powershell.exe'\" | Where-Object { $_.CommandLine -like '*UHR-Agent\uhr-agent.ps1*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }" >nul 2>&1
if not exist "%DEST%" mkdir "%DEST%"
copy /y "%~dp0uhr-agent.ps1" "%DEST%\uhr-agent.ps1" >nul
copy /y "%~dp0uhr-agent.ini" "%DEST%\uhr-agent.ini" >nul
copy /y "%~dp0uninstall.bat" "%DEST%\uninstall.bat" >nul
copy /y "%~dp0run-visible.bat" "%DEST%\run-visible.bat" >nul
> "%STARTUP%\UHR-Agent.vbs" echo CreateObject("WScript.Shell").Run "powershell -NoProfile -STA -WindowStyle Hidden -ExecutionPolicy Bypass -File ""%DEST%\uhr-agent.ps1""", 0, False
wscript "%STARTUP%\UHR-Agent.vbs"
echo.
echo  UHR-Agent installed for this Windows user - it runs in the background and starts at sign-in.
echo  تم التثبيت لمستخدم ويندوز الحالي: الوكيل يعمل بالخلفية ويبدأ تلقائياً عند الدخول. يمكنك حذف هذا المجلد.
echo.
echo  Log: %DEST%\agent.log
pause
