# UHR-Agent — وكيل جهاز الموظف (D-15): عند تسجيل خروجك بالبطاقة يرسل نظام UHR أمراً لهذا الجهاز
# (قفل الجلسة أو تسجيل الخروج من Windows أو إيقاف التشغيل حسب إعداد الشركة) مع نافذة تحذير لحفظ العمل.
# يعمل مخفياً داخل جلسة المستخدم بعد install.bat، ويسأل الخادم كل بضع ثوانٍ، والسجل في agent.log بجانبه.
$ErrorActionPreference = 'Continue'
$version = '1.0.0'
$dir = Split-Path -Parent $MyInvocation.MyCommand.Path
$log = Join-Path $dir 'agent.log'
function Log($m) { $line = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '  ' + $m; Write-Host $line; try { Add-Content -Path $log -Value $line -Encoding UTF8 } catch {} }
if ((Test-Path $log) -and (Get-Item $log).Length -gt 1MB) { Remove-Item $log -Force }

# مثيل واحد لكل جلسة مستخدم
$mutex = New-Object System.Threading.Mutex($false, 'Local\UHR-Agent')
if (-not $mutex.WaitOne(0)) { Write-Host 'UHR-Agent is already running.'; exit 0 }

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
Add-Type -TypeDefinition @"
using System;
using System.Runtime.InteropServices;
public static class Win {
  [DllImport("user32.dll", SetLastError=true)] public static extern bool LockWorkStation();
  [DllImport("user32.dll", SetLastError=true)] public static extern bool ExitWindowsEx(uint flags, uint reason);
}
"@

$cfg = @{ URL = ''; KEY = '' }
$ini = Join-Path $dir 'uhr-agent.ini'
if (Test-Path $ini) { Get-Content $ini -Encoding UTF8 | ForEach-Object { if ($_ -match '^\s*([A-Z]+)\s*=\s*(.*?)\s*$') { $cfg[$matches[1]] = $matches[2] } } }
if (-not $cfg.URL -or -not $cfg.KEY) { Log 'uhr-agent.ini is missing URL or KEY — download the package again from UHR.'; exit 1 }
$base = $cfg.URL.TrimEnd('/')
$headers = @{ 'X-Agent-Key' = $cfg.KEY; 'Accept' = 'application/json'; 'Accept-Language' = 'ar' }
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$whoami = $env:USERDOMAIN + '\' + $env:USERNAME
Log ("UHR-Agent $version started for $whoami — $base")

function Report($id, $status, $err) {
  $body = @{ status = $status; error = $err } | ConvertTo-Json -Compress
  for ($i = 0; $i -lt 3; $i++) {
    try { $null = Invoke-RestMethod -Method Post -Uri ("$base/api/agent/commands/$id") -Headers $headers -ContentType 'application/json; charset=utf-8' -Body ([Text.Encoding]::UTF8.GetBytes($body)) -TimeoutSec 8; return $true }
    catch { Start-Sleep -Seconds 2 }
  }
  Log ("could not report command $id ($status)"); return $false
}

# نافذة التحذير: عدّاد تنازلي، «نفّذ الآن»، و«تأجيل» مرة واحدة إن سمحت الشركة. تعيد: run | postpone
function Warn($action, $seconds, $postpone) {
  if ($seconds -le 0) { return 'run' }
  $what = @{ lock = 'قفل الجهاز'; logoff = 'تسجيل الخروج من Windows'; shutdown = 'إيقاف تشغيل الجهاز' }[$action]
  $whatEn = @{ lock = 'lock this computer'; logoff = 'sign out of Windows'; shutdown = 'shut down this computer' }[$action]
  $f = New-Object Windows.Forms.Form
  $f.Text = 'UHR'; $f.TopMost = $true; $f.StartPosition = 'CenterScreen'; $f.FormBorderStyle = 'FixedDialog'; $f.ControlBox = $false
  $f.Width = 520; $f.Height = 250; $f.RightToLeft = 'Yes'; $f.RightToLeftLayout = $true; $f.Font = New-Object Drawing.Font('Segoe UI', 11)
  $l = New-Object Windows.Forms.Label; $l.AutoSize = $false; $l.Width = 480; $l.Height = 110; $l.Left = 16; $l.Top = 14
  $f.Controls.Add($l)
  $now = New-Object Windows.Forms.Button; $now.Text = 'حفظت عملي — نفّذ الآن'; $now.Width = 220; $now.Height = 36; $now.Left = 270; $now.Top = 140
  $now.Add_Click({ $f.Tag = 'run'; $f.Close() }); $f.Controls.Add($now)
  if ($postpone -gt 0) {
    $later = New-Object Windows.Forms.Button; $later.Text = "تأجيل $postpone دقيقة"; $later.Width = 180; $later.Height = 36; $later.Left = 20; $later.Top = 140
    $later.Add_Click({ $f.Tag = 'postpone'; $f.Close() }); $f.Controls.Add($later)
  }
  $script:left = [int]$seconds
  $paint = { $l.Text = "سجّلتَ خروجك من الدوام. سيتم $what خلال $($script:left) ثانية — احفظ عملك الآن.`r`n`r`nYou have checked out. UHR will $whatEn in $($script:left) seconds — please save your work." }
  & $paint
  $t = New-Object Windows.Forms.Timer; $t.Interval = 1000
  $t.Add_Tick({ $script:left--; & $paint; if ($script:left -le 0) { $t.Stop(); $f.Tag = 'run'; $f.Close() } })
  $f.Add_Shown({ $t.Start(); $f.Activate() })
  $null = $f.ShowDialog()
  $t.Stop(); $t.Dispose()
  if ($f.Tag -eq 'postpone') { return 'postpone' } else { return 'run' }
}

function Execute($c) {
  $decision = Warn $c.action ([int]$c.warn) ([int]$c.postpone)
  if ($decision -eq 'postpone') {
    $null = Report $c.id 'postponed' $null
    Log ("command $($c.id) postponed $($c.postpone) min")
    Start-Sleep -Seconds ([int]$c.postpone * 60)
    $null = Warn $c.action ([int]$c.warn) 0
  }
  Log ("executing command $($c.id): $($c.action) ($($c.reason))")
  switch ($c.action) {
    'lock' {
      if ([Win]::LockWorkStation()) { $null = Report $c.id 'done' $null } else { $null = Report $c.id 'failed' ('LockWorkStation error ' + [Runtime.InteropServices.Marshal]::GetLastWin32Error()) }
    }
    'logoff' {
      # يُبلَّغ قبل التنفيذ لأن العملية تنتهي مع الجلسة؛ وإن رفض ويندوز يُبلَّغ الفشل بعدها
      $null = Report $c.id 'done' $null
      if (-not [Win]::ExitWindowsEx(0x10, 0)) { $null = Report $c.id 'failed' ('ExitWindowsEx error ' + [Runtime.InteropServices.Marshal]::GetLastWin32Error()) }
    }
    'shutdown' {
      $null = Report $c.id 'done' $null
      $p = Start-Process -FilePath 'shutdown.exe' -ArgumentList '/s', '/t', '0' -PassThru -Wait -WindowStyle Hidden
      if ($p.ExitCode -ne 0) { $null = Report $c.id 'failed' ('shutdown.exe exit ' + $p.ExitCode) }
    }
    default { $null = Report $c.id 'failed' ('unknown action ' + $c.action) }
  }
}

$handled = @{}
$wait = 10
$wasDown = $false
while ($true) {
  try {
    $body = @{ host = $env:COMPUTERNAME; user = $whoami; version = $version } | ConvertTo-Json -Compress
    $r = Invoke-RestMethod -Method Post -Uri "$base/api/agent/poll" -Headers $headers -ContentType 'application/json; charset=utf-8' -Body ([Text.Encoding]::UTF8.GetBytes($body)) -TimeoutSec 10
    if ($wasDown) { Log 'server reachable again'; $wasDown = $false }
    if ($r.poll) { $wait = [Math]::Max(5, [int]$r.poll) }
    foreach ($c in @($r.commands)) {
      if ($null -eq $c -or $handled.ContainsKey([string]$c.id)) { continue }
      $handled[[string]$c.id] = $true
      try { Execute $c } catch { Log ("command $($c.id) failed: " + $_.Exception.Message); $null = Report $c.id 'failed' $_.Exception.Message }
    }
  } catch {
    $code = $null; try { $code = [int]$_.Exception.Response.StatusCode } catch {}
    if ($code -eq 401) { Log 'agent key rejected (disabled or replaced) — download the package again from UHR.'; Start-Sleep -Seconds 300 }
    elseif (-not $wasDown) { Log ('server not reachable: ' + $_.Exception.Message); $wasDown = $true }
  }
  Start-Sleep -Seconds $wait
}
