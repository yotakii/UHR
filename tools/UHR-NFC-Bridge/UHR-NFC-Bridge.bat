@echo off
title UHR NFC Bridge
set "BRIDGE_PATH=%~f0"
powershell -NoProfile -ExecutionPolicy Bypass -Command "$s=[IO.File]::ReadAllText('%~f0'); $m='#PS'+'-START'; $i=$s.LastIndexOf($m); Invoke-Expression $s.Substring($i)"
pause
exit /b
#PS-START
Add-Type -AssemblyName System.Windows.Forms
Add-Type -TypeDefinition @"
using System;
using System.Runtime.InteropServices;
public static class PCSC {
  [DllImport("winscard.dll")] public static extern int SCardEstablishContext(uint scope, IntPtr r1, IntPtr r2, out IntPtr ctx);
  [DllImport("winscard.dll", EntryPoint="SCardListReadersW", CharSet=CharSet.Unicode)] public static extern int SCardListReaders(IntPtr ctx, string groups, char[] readers, ref uint len);
  [DllImport("winscard.dll", EntryPoint="SCardConnectW", CharSet=CharSet.Unicode)] public static extern int SCardConnect(IntPtr ctx, string reader, uint share, uint proto, out IntPtr card, out uint active);
  [DllImport("winscard.dll")] public static extern int SCardDisconnect(IntPtr card, uint disp);
  [DllImport("winscard.dll")] public static extern int SCardTransmit(IntPtr card, IntPtr sendPci, byte[] send, int sendLen, IntPtr recvPci, byte[] recv, ref int recvLen);
  [DllImport("kernel32.dll", CharSet=CharSet.Ansi)] public static extern IntPtr LoadLibrary(string name);
  [DllImport("kernel32.dll", CharSet=CharSet.Ansi)] public static extern IntPtr GetProcAddress(IntPtr h, string name);
  public static IntPtr Pci(uint proto) { IntPtr h = LoadLibrary("winscard.dll"); return GetProcAddress(h, proto == 1 ? "g_rgSCardT0Pci" : "g_rgSCardT1Pci"); }
}
"@

function Get-Readers($ctx) {
  [uint32]$len = 0
  $null = [PCSC]::SCardListReaders($ctx, [NullString]::Value, $null, [ref]$len)
  if ($len -le 1) { return @() }
  $buf = New-Object char[] $len
  $rc = [PCSC]::SCardListReaders($ctx, [NullString]::Value, $buf, [ref]$len)
  if ($rc -ne 0) { return @() }
  return (-join $buf).Split([char]0) | Where-Object { $_ -ne '' }
}

# ---------- الإعداد: uhr-bridge.ini بجانب هذا الملف ----------
$dir = Split-Path -Parent $env:BRIDGE_PATH
if (-not $dir) { $dir = (Get-Location).Path }
$ini = Join-Path $dir 'uhr-bridge.ini'
$cfg = @{ MODE = 'api'; URL = ''; KEY = '' }
if (Test-Path $ini) { Get-Content $ini -Encoding UTF8 | ForEach-Object { if ($_ -match '^\s*([A-Z]+)\s*=\s*(.*?)\s*$') { $cfg[$matches[1]] = $matches[2] } } }
$api = ($cfg.MODE -eq 'api') -and $cfg.URL -and $cfg.KEY
if ($cfg.MODE -eq 'api' -and -not $api) { Write-Host "uhr-bridge.ini: set URL and KEY (from HR > Attendance devices). Falling back to keyboard mode." -ForegroundColor Yellow }

$ctx = [IntPtr]::Zero
$rc = [PCSC]::SCardEstablishContext(2, [IntPtr]::Zero, [IntPtr]::Zero, [ref]$ctx)
if ($rc -ne 0) { Write-Host "Smart Card service is not available (code $rc). Start the 'Smart Card' Windows service." -ForegroundColor Red; Read-Host "Press Enter to exit"; exit 1 }

Write-Host "UHR NFC bridge" -ForegroundColor Cyan
if ($api) { Write-Host ("Mode: API  ->  " + $cfg.URL) -ForegroundColor Cyan } else { Write-Host "Mode: keyboard (keep the UHR /kiosk screen focused)" -ForegroundColor Cyan }
$reader = $null
while (-not $reader) {
  $list = @(Get-Readers $ctx)
  if ($list.Count -gt 0) {
    $reader = ($list | Where-Object { $_ -match 'PICC|Contactless|CL' } | Select-Object -First 1)
    if (-not $reader) { $reader = $list[0] }
  } else { Write-Host "No reader found - plug in the USB reader..." -ForegroundColor Yellow; Start-Sleep -Seconds 2 }
}
Write-Host "Reader: $reader" -ForegroundColor Green

$apdu = [byte[]](0xFF, 0xCA, 0x00, 0x00, 0x00)   # GET DATA: card UID
$last = $null
$endpoint = $cfg.URL.TrimEnd('/') + '/api/device/scan'
while ($true) {
  $card = [IntPtr]::Zero; [uint32]$proto = 0
  $rc = [PCSC]::SCardConnect($ctx, $reader, 2, 3, [ref]$card, [ref]$proto)
  if ($rc -eq 0) {
    $recv = New-Object byte[] 64; [int]$rlen = 64
    $rc2 = [PCSC]::SCardTransmit($card, [PCSC]::Pci($proto), $apdu, $apdu.Length, [IntPtr]::Zero, $recv, [ref]$rlen)
    $null = [PCSC]::SCardDisconnect($card, 0)
    if ($rc2 -eq 0 -and $rlen -ge 2 -and $recv[$rlen - 2] -eq 0x90) {
      $uid = $recv[0..($rlen - 3)]
      $hex = ($uid | ForEach-Object { $_.ToString('X2') }) -join ''
      if ($hex -ne $last) {
        $last = $hex
        if ($api) {
          try {
            $body = @{ uid = $hex; via = 'bridge' } | ConvertTo-Json
            $r = Invoke-RestMethod -Method Post -Uri $endpoint -Body $body -ContentType 'application/json; charset=utf-8' -Headers @{ 'X-Device-Key' = $cfg.KEY; 'Accept' = 'application/json' } -TimeoutSec 8
            $who = if ($r.employee) { $r.employee.code + ' ' + $r.employee.name.en } else { '' }
            Write-Host ("{0}  UID {1}  ->  {2} {3} {4}" -f (Get-Date -Format 'HH:mm:ss'), $hex, $r.kind, $r.type, $who) -ForegroundColor Green
          } catch { Write-Host ("{0}  UID {1}  ->  ERROR {2}" -f (Get-Date -Format 'HH:mm:ss'), $hex, $_.Exception.Message) -ForegroundColor Red; [console]::beep(330, 400) }
        } else {
          [decimal]$dec = 0; foreach ($b in $uid) { $dec = $dec * 256 + $b }
          $digits = $dec.ToString('0')
          Write-Host ("{0}  UID {1}  ->  {2}" -f (Get-Date -Format 'HH:mm:ss'), $hex, $digits) -ForegroundColor Green
          [System.Windows.Forms.SendKeys]::SendWait($digits + '{ENTER}')
        }
      }
    }
  } else {
    $last = $null   # card removed: the next tap of the same card counts again
  }
  Start-Sleep -Milliseconds 250
}
