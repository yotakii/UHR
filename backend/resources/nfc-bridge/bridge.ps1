# UHR NFC Bridge — يقرأ بطاقات قارئ ACS (PC/SC) ويرسلها لنظام UHR بمفتاح جهاز الحضور.
# يعمل مخفياً مع إقلاع ويندوز بعد install.bat، والسجل في bridge.log بجانبه.
$ErrorActionPreference = 'Continue'
$dir = Split-Path -Parent $MyInvocation.MyCommand.Path
$log = Join-Path $dir 'bridge.log'
function Log($m) { $line = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '  ' + $m; Write-Host $line; try { Add-Content -Path $log -Value $line -Encoding UTF8 } catch {} }
if ((Test-Path $log) -and (Get-Item $log).Length -gt 1MB) { Remove-Item $log -Force }

# مثيل واحد فقط
$mutex = New-Object System.Threading.Mutex($false, 'Global\UHR-NFC-Bridge')
if (-not $mutex.WaitOne(0)) { Write-Host 'UHR NFC bridge is already running.'; exit 0 }

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


$cfg = @{ MODE = 'api'; URL = ''; KEY = '' }
$ini = Join-Path $dir 'uhr-bridge.ini'
if (Test-Path $ini) { Get-Content $ini -Encoding UTF8 | ForEach-Object { if ($_ -match '^\s*([A-Z]+)\s*=\s*(.*?)\s*$') { $cfg[$matches[1]] = $matches[2] } } }
$api = ($cfg.MODE -eq 'api') -and $cfg.URL -and $cfg.KEY
$base = $cfg.URL.TrimEnd('/')
$headers = @{ 'X-Device-Key' = $cfg.KEY; 'Accept' = 'application/json' }
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
Log ("UHR NFC bridge started — mode " + $(if ($api) { 'API ' + $base } else { 'keyboard' }))

function Heartbeat {
  if (-not $api) { return }
  try { $null = Invoke-RestMethod -Method Get -Uri ($base + '/api/device/me') -Headers $headers -TimeoutSec 8 } catch { Log ('server not reachable: ' + $_.Exception.Message) }
}

$ctx = [IntPtr]::Zero
while ([PCSC]::SCardEstablishContext(2, [IntPtr]::Zero, [IntPtr]::Zero, [ref]$ctx) -ne 0) { Log "Smart Card service not available — retrying"; Start-Sleep -Seconds 10 }

$apdu = [byte[]](0xFF, 0xCA, 0x00, 0x00, 0x00)   # GET DATA: card UID
$reader = $null; $last = $null; $fails = 0; $beat = [DateTime]::MinValue
while ($true) {
  if ((Get-Date) - $beat -gt [TimeSpan]::FromSeconds(60)) { Heartbeat; $beat = Get-Date }
  if (-not $reader) {
    $list = @(Get-Readers $ctx)
    if ($list.Count -eq 0) { Start-Sleep -Seconds 3; continue }
    $reader = ($list | Where-Object { $_ -match 'PICC|Contactless|CL' } | Select-Object -First 1)
    if (-not $reader) { $reader = $list[0] }
    Log "Reader: $reader"
  }
  $card = [IntPtr]::Zero; [uint32]$proto = 0
  $rc = [PCSC]::SCardConnect($ctx, $reader, 2, 3, [ref]$card, [ref]$proto)
  if ($rc -eq 0) {
    $fails = 0
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
            $r = Invoke-RestMethod -Method Post -Uri ($base + '/api/device/scan') -Body $body -ContentType 'application/json; charset=utf-8' -Headers $headers -TimeoutSec 8
            $who = if ($r.employee) { $r.employee.code } else { '' }
            Log ("UID {0} -> {1} {2} {3}" -f $hex, $r.kind, $r.type, $who)
            if ($r.kind -ne 'ok') { [console]::beep(440, 250) }
          } catch { Log ("UID {0} -> ERROR {1}" -f $hex, $_.Exception.Message); [console]::beep(330, 500) }
        } else {
          [decimal]$dec = 0; foreach ($b in $uid) { $dec = $dec * 256 + $b }
          Log ("UID {0} -> keys {1}" -f $hex, $dec.ToString('0'))
          [System.Windows.Forms.SendKeys]::SendWait($dec.ToString('0') + '{ENTER}')
        }
      }
    }
  } else {
    $last = $null   # البطاقة أُبعدت: التمرير التالي لنفس البطاقة يُحسب
    if ($rc -ne -2146435060 -and $rc -ne -2146434967) { $fails++ }   # NO_SMARTCARD / REMOVED_CARD طبيعيان
    if ($fails -gt 20) { Log "Reader lost — searching again"; $reader = $null; $fails = 0 }
  }
  Start-Sleep -Milliseconds 250
}
