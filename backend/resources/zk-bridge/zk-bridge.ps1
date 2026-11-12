# UHR ZK Bridge — يقرأ حركات بصّامة ZKTeco من شبكة الشركة ويرسلها لنظام UHR، ويرسل للبصّامة أسماء الموظفين وأرقامهم وبطاقاتهم.
# يحتاج أداة الاتصال الرسمية zkemkeeper (تُثبَّت مع برنامج الحضور Attendance Management). يعمل بـ PowerShell 32-بت مخفياً مع ويندوز.
$ErrorActionPreference = 'Continue'
$dir = Split-Path -Parent $MyInvocation.MyCommand.Path
$log = Join-Path $dir 'zk-bridge.log'
function Log($m) { $line = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '  ' + $m; Write-Host $line; try { Add-Content -Path $log -Value $line -Encoding UTF8 } catch {} }
if ((Test-Path $log) -and (Get-Item $log).Length -gt 2MB) { Remove-Item $log -Force }

$mutex = New-Object System.Threading.Mutex($false, 'Global\UHR-ZK-Bridge')
if (-not $mutex.WaitOne(0)) { Write-Host 'UHR ZK bridge is already running.'; exit 0 }

$cfg = @{ URL = ''; KEY = '' }
$ini = Join-Path $dir 'uhr-zk.ini'
if (Test-Path $ini) { Get-Content $ini -Encoding UTF8 | ForEach-Object { if ($_ -match '^\s*([A-Z]+)\s*=\s*(.*?)\s*$') { $cfg[$matches[1]] = $matches[2] } } }
if (-not $cfg.URL -or -not $cfg.KEY) { Log 'uhr-zk.ini is missing URL or KEY — download the package again from the Devices page.'; exit 1 }
$base = $cfg.URL.TrimEnd('/')
$headers = @{ 'X-Device-Key' = $cfg.KEY; 'Accept' = 'application/json' }
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$stateFile = Join-Path $dir 'zk-state.json'
$state = @{ users_hash = ''; sent = @{}; last = '' }
if (Test-Path $stateFile) { try { $j = Get-Content $stateFile -Raw | ConvertFrom-Json; $state.users_hash = [string]$j.users_hash; $state.last = [string]$j.last; if ($j.sent) { $j.sent.PSObject.Properties | ForEach-Object { $state.sent[$_.Name] = [string]$_.Value } } } catch {} }
function Save-State { try { $state | ConvertTo-Json -Depth 4 | Set-Content -Path $stateFile -Encoding UTF8 } catch {} }

if ([Environment]::Is64BitProcess) { Log 'Warning: running 64-bit PowerShell — zkemkeeper usually needs the 32-bit one (install.bat uses it).' }
Log ("UHR ZK bridge started — " + $base)

function Api($method, $path, $body) {
  $p = @{ Method = $method; Uri = ($base + $path); Headers = $headers; TimeoutSec = 60 }
  if ($body) { $p.Body = [System.Text.Encoding]::UTF8.GetBytes(($body | ConvertTo-Json -Depth 6 -Compress)); $p.ContentType = 'application/json; charset=utf-8' }
  return Invoke-RestMethod @p
}
function Report($ok, $err, $info) { try { $null = Api 'Post' '/api/device/zk/status' @{ ok = $ok; error = $err; info = $info } } catch {} }

function New-Zk {
  foreach ($id in 'zkemkeeper.ZKEM.1', 'zkemkeeper.CZKEM', 'zkemkeeper.ZKEM') { try { return New-Object -ComObject $id } catch {} }
  return $null
}

$zk = $null; $connected = $false; $mno = 1
while ($true) {
  $poll = 30
  try {
    $c = Api 'Get' '/api/device/zk/config' $null
    $poll = [Math]::Max(10, [int]$c.poll); $mno = [int]$c.zk.machine_no
    if (-not $zk) { $zk = New-Zk }
    if (-not $zk) { Log 'zkemkeeper is not installed/registered on this PC. Install the ZKTeco Attendance Management program (or its SDK) and run install.bat again.'; Report $false 'zkemkeeper SDK not found on the bridge PC' $null; Start-Sleep -Seconds 120; continue }

    if (-not $connected) {
      if ($c.zk.comm_key -ne '' -and $c.zk.comm_key -ne $null) { $null = $zk.SetCommPassword([int]$c.zk.comm_key) }
      $connected = $zk.Connect_Net([string]$c.zk.ip, [int]$c.zk.port)
      if (-not $connected) { $e = 0; $null = $zk.GetLastError([ref]$e); Log ("Cannot reach device {0}:{1} (error {2})" -f $c.zk.ip, $c.zk.port, $e); Report $false ("Cannot reach the device at {0}:{1} (error {2})" -f $c.zk.ip, $c.zk.port, $e) $null; Start-Sleep -Seconds $poll; continue }
      $sn = ''; $null = $zk.GetSerialNumber($mno, [ref]$sn); $fw = ''; $null = $zk.GetFirmwareVersion($mno, [ref]$fw)
      Log ("Connected to device {0}:{1} SN {2} FW {3}" -f $c.zk.ip, $c.zk.port, $sn, $fw)
      Report $true $null @{ serial = $sn; firmware = $fw; ip = $c.zk.ip }
    }

    # 1) الموظفون: فقط من تغيّر اسمه أو بطاقته — تُحفظ كلمة مرور الجهاز وصلاحيات المشرفين كما هي
    if ($c.users_hash -ne $state.users_hash) {
      $null = $zk.EnableDevice($mno, $false); $n = 0
      foreach ($u in $c.users) {
        $sig = $u.name + '|' + $u.card
        if ($state.sent[$u.pin] -eq $sig) { continue }
        $nm = ''; $pw = ''; $priv = 0; $en = $true
        $exists = $zk.SSR_GetUserInfo($mno, [string]$u.pin, [ref]$nm, [ref]$pw, [ref]$priv, [ref]$en)
        if (-not $exists) { $pw = ''; $priv = 0; $en = $true }
        if ($u.card) { $null = $zk.SetStrCardNumber([string]$u.card) } elseif ($exists) { $cur = ''; $null = $zk.GetStrCardNumber([ref]$cur); $null = $zk.SetStrCardNumber($cur) } else { $null = $zk.SetStrCardNumber('') }
        if ($zk.SSR_SetUserInfo($mno, [string]$u.pin, [string]$u.name, $pw, $priv, $en)) { $state.sent[$u.pin] = $sig; $n++ }
        else { $e = 0; $null = $zk.GetLastError([ref]$e); Log ("User {0} not written (error {1})" -f $u.pin, $e) }
      }
      $null = $zk.RefreshData($mno); $null = $zk.EnableDevice($mno, $true)
      $state.users_hash = $c.users_hash; Save-State
      Log ("Users synced: {0} written" -f $n)
    }

    # 2) الحركات بعد «since» (يحددها الخادم من آخر حركة وصلته، مع هامش)
    $since = if ($c.since) { [DateTime]::ParseExact([string]$c.since, 'yyyy-MM-dd HH:mm:ss', $null) } else { [DateTime]::MinValue }
    # ما أُرسل في هذا الحاسوب سابقاً (مع هامش دقيقتين) — حتى لا يُعاد إرسال هامش الخادم في كل دورة
    if ($state.last) { $mine = [DateTime]::ParseExact($state.last, 'yyyy-MM-dd HH:mm:ss', $null).AddMinutes(-2); if ($mine -gt $since) { $since = $mine } }
    $max = $null
    $rows = New-Object System.Collections.ArrayList
    if ($zk.ReadGeneralLogData($mno)) {
      $pin = ''; $ver = 0; $io = 0; $y = 0; $mo = 0; $d = 0; $h = 0; $mi = 0; $s = 0; $wc = 0
      while ($zk.SSR_GetGeneralLogData($mno, [ref]$pin, [ref]$ver, [ref]$io, [ref]$y, [ref]$mo, [ref]$d, [ref]$h, [ref]$mi, [ref]$s, [ref]$wc)) {
        $t = New-Object DateTime($y, $mo, $d, $h, $mi, $s)
        if ($t -gt $since) { $null = $rows.Add(@{ pin = [string]$pin; time = $t.ToString('yyyy-MM-dd HH:mm:ss'); verify = $ver; state = $io }); if (-not $max -or $t -gt $max) { $max = $t } }
      }
    } else {
      $e = 0; $null = $zk.GetLastError([ref]$e)
      if ($e -ne 0 -and $e -ne -100 -and $e -ne 1) { Log ("Reading logs failed (error {0}) — reconnecting" -f $e); $connected = $false; try { $zk.Disconnect() } catch {}; Start-Sleep -Seconds 5; continue }
    }
    for ($i = 0; $i -lt $rows.Count; $i += 1000) {
      $chunk = $rows.GetRange($i, [Math]::Min(1000, $rows.Count - $i))
      $r = Api 'Post' '/api/device/zk/punches' @{ punches = @($chunk) }
      if ($r.new -gt 0) { Log ("Punches: {0} new, {1} applied, {2} duplicate, {3} unlinked" -f $r.new, $r.applied, $r.dup, $r.unmatched) }
    }
    if ($max) { $state.last = $max.ToString('yyyy-MM-dd HH:mm:ss'); Save-State }
    if ($rows.Count -eq 0) { Report $true $null $null }
  } catch {
    Log ('Error: ' + $_.Exception.Message)
    if ($_.Exception.Message -match 'device|COM|RPC') { $connected = $false }
  }
  Start-Sleep -Seconds $poll
}
