<!doctype html>
<html lang="{{ $lang }}" dir="{{ $lang === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $t('الوظائف الشاغرة', 'Open positions') }} — {{ $c['name'][$lang] }}</title>
<style>
  :root { --brand: {{ $color }}; --bg: #ffffff; --card: #ffffff; --ink: #14202b; --sub: #5b6873; --line: #e3e8ec; --chip: #f2f5f7; }
  [data-theme="dark"] { --bg: #0f1720; --card: #16212c; --ink: #e8eef3; --sub: #9aa8b4; --line: #26333f; --chip: #1d2a36; }
  * { box-sizing: border-box; }
  html, body { margin: 0; background: var(--bg); color: var(--ink); font: 14px/1.6 "IBM Plex Sans Arabic", "Segoe UI", Tahoma, system-ui, sans-serif; }
  .wrap { padding: 12px; }
  .head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
  .head img { height: 34px; width: auto; }
  .head b { font-size: 16px; }
  .head span { color: var(--sub); font-size: 12.5px; }
  .bar { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
  .bar input, .bar select { flex: 1 1 180px; min-width: 0; height: 38px; padding: 0 10px; border: 1px solid var(--line); border-radius: 10px; background: var(--card); color: var(--ink); font: inherit; }
  .list { display: grid; gap: 10px; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
  a.job { display: block; text-decoration: none; color: inherit; background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 14px; transition: border-color .15s, transform .15s; }
  a.job:hover, a.job:focus-visible { border-color: var(--brand); transform: translateY(-1px); outline: none; }
  .title { font-weight: 700; font-size: 15px; }
  .meta { color: var(--sub); font-size: 12.5px; margin-top: 2px; }
  .chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
  .chip { background: var(--chip); border-radius: 999px; padding: 2px 10px; font-size: 12px; }
  .go { margin-top: 12px; color: var(--brand); font-weight: 700; font-size: 13px; }
  .empty { border: 1px dashed var(--line); border-radius: 14px; padding: 28px; text-align: center; color: var(--sub); }
  .foot { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-top: 12px; font-size: 12.5px; color: var(--sub); }
  .foot a { color: var(--brand); font-weight: 700; text-decoration: none; }
  [hidden] { display: none !important; }
</style>
</head>
<body data-theme="{{ $theme }}">
<div class="wrap" id="root">
  @if ($logo)
  <div class="head"><img src="{{ $c['logo'] }}" alt="{{ $c['name'][$lang] }}"><div><b>{{ $t('انضم إلى فريقنا', 'Join our team') }}</b><br><span>{{ $c['name'][$lang] }}</span></div></div>
  @endif
  @if ($filters)
  <div class="bar">
    <input id="q" type="search" placeholder="{{ $t('ابحث عن وظيفة…', 'Search jobs…') }}" aria-label="{{ $t('بحث', 'Search') }}">
    <select id="dept" aria-label="{{ $t('القسم', 'Department') }}"><option value="">{{ $t('كل الأقسام', 'All departments') }}</option>
      @foreach ($jobs->pluck('dept')->unique('code') as $d)<option value="{{ $d['code'] }}">{{ $d[$lang] }}</option>@endforeach
    </select>
  </div>
  @endif
  @if ($jobs->isEmpty())
    <div class="empty">{{ $t('لا توجد وظائف شاغرة حالياً. تابعنا قريباً.', 'No open positions right now. Check back soon.') }}</div>
  @else
  <div class="list" id="list">
    @foreach ($jobs as $j)
    <a class="job" href="{{ $base }}/jobs/{{ $slug }}/{{ $j['code'] }}?src=embed" target="_blank" rel="noopener" data-dept="{{ $j['dept']['code'] }}" data-text="{{ mb_strtolower($j['title']['ar'].' '.$j['title']['en'].' '.$j['dept']['ar'].' '.$j['city']['ar'].' '.$j['dept']['en'].' '.$j['city']['en']) }}">
      <div class="title">{{ $j['title'][$lang] ?: $j['title']['ar'] }}</div>
      <div class="meta">{{ $j['dept'][$lang] }} · {{ $j['city'][$lang] }}</div>
      <div class="chips"><span class="chip">{{ $j['type'][$lang] }}</span><span class="chip">{{ $j['workplace'][$lang] }}</span>
        @if ($j['min_years'])<span class="chip">{{ $t($j['min_years'].'+ سنوات خبرة', $j['min_years'].'+ years') }}</span>@endif
        @if ($j['closes_at'])<span class="chip">{{ $t('حتى', 'Until') }} {{ $j['closes_at'] }}</span>@endif</div>
      <div class="go">{{ $t('التفاصيل والتقديم ←', 'Details & apply →') }}</div>
    </a>
    @endforeach
  </div>
  <div class="empty" id="none" hidden>{{ $t('لا توجد وظائف مطابقة.', 'No matching positions.') }}</div>
  @endif
  <div class="foot"><span>{{ $t($total.' وظيفة شاغرة', $total.' open positions') }}</span>@if ($more)<a href="{{ $base }}/c/{{ $slug }}" target="_blank" rel="noopener">{{ $t('كل الوظائف', 'All positions') }}</a>@endif</div>
</div>
<script>
(function () {
  var root = document.getElementById('root');
  function size() { if (window.parent !== window) window.parent.postMessage({ type: 'uhr-embed-height', height: Math.ceil(root.getBoundingClientRect().height) + 4 }, '*'); }
  var q = document.getElementById('q'), d = document.getElementById('dept'), none = document.getElementById('none');
  function filter() {
    var t = (q && q.value || '').trim().toLowerCase(), dv = d ? d.value : '', n = 0;
    document.querySelectorAll('a.job').forEach(function (a) { var ok = (!t || a.dataset.text.indexOf(t) >= 0) && (!dv || a.dataset.dept === dv); a.hidden = !ok; if (ok) n++; });
    if (none) none.hidden = n > 0; size();
  }
  if (q) q.addEventListener('input', filter); if (d) d.addEventListener('change', filter);
  window.addEventListener('load', size); window.addEventListener('resize', size);
  if (window.ResizeObserver) new ResizeObserver(size).observe(root); size();
})();
</script>
</body>
</html>
