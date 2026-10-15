import { useCallback, useEffect, useState } from 'react'
import { Database, HardDrive, Monitor, RefreshCw, Server, Zap, CheckCircle2, XCircle, Loader2 } from 'lucide-react'
import { api, type Health, type ServiceCheck } from '../lib/api'
import { Logo, LangToggle, ThemeToggle } from '../ui/brand'

type Row = { key: string; title: string; sub: string; icon: typeof Server; check: ServiceCheck | null; meta?: string }

// شاشة فحص البنية — المهمة 00 (صفحة مطوّرين، تُستبدل بشاشة الدخول في المهمة 03)
export default function SystemStatus() {
  const [health, setHealth] = useState<Health | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [apiMs, setApiMs] = useState(0)
  const [checkedAt, setCheckedAt] = useState<Date | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    const t0 = performance.now()
    try {
      setHealth(await api<Health>('/health')); setError(null)
    } catch (e) {
      setHealth(null); setError(e instanceof Error ? e.message : String(e))
    } finally {
      setApiMs(Math.round(performance.now() - t0)); setCheckedAt(new Date()); setLoading(false)
    }
  }, [])

  useEffect(() => {
    load()
    const t = setInterval(load, 10_000)
    return () => clearInterval(t)
  }, [load])

  const c = health?.checks
  const down = (msg = 'غير متاح'): ServiceCheck | null => (error ? { ok: false, ms: 0, error: msg } : null)
  const rows: Row[] = [
    { key: 'web', title: 'الواجهة', sub: 'React 19 · Vite', icon: Monitor, check: { ok: true, ms: 0 }, meta: 'تعمل' },
    { key: 'api', title: 'خادم التطبيق', sub: health ? `Laravel ${health.app.laravel} · PHP ${health.app.php}` : 'Laravel', icon: Server,
      check: health ? { ok: true, ms: apiMs } : down(error ?? undefined) },
    { key: 'db', title: 'قاعدة البيانات', sub: c?.database.version ?? 'PostgreSQL', icon: Database, check: c?.database ?? down(),
      meta: c?.database.ok ? `${c.database.migrations ?? 0} ترحيل منفّذ` : undefined },
    { key: 'redis', title: 'الذاكرة المؤقتة والطوابير', sub: c?.redis.version ? `Redis ${c.redis.version}` : 'Redis', icon: Zap, check: c?.redis ?? down() },
    { key: 'storage', title: 'تخزين الملفات', sub: 'storage/app', icon: HardDrive, check: c?.storage ?? down() },
  ]
  const allOk = rows.every(r => r.check?.ok)
  const first = loading && !checkedAt

  return (
    <div className="app-glow min-h-full">
      <header className="flex h-[68px] items-center justify-between border-b border-line px-4 sm:px-8">
        <Logo />
        <div className="flex items-center gap-2"><a href="#kit" className="text-[13px] text-brand font-semibold">مكتبة المكونات</a><LangToggle /><ThemeToggle /></div>
      </header>

      <main className="mx-auto max-w-3xl px-4 py-10">
        <div className="fadeup mb-8 flex items-start justify-between gap-4">
          <div>
            <p className="text-xs font-semibold tracking-widest text-brand">UNIQUENESS GROUP</p>
            <h1 className="mt-1 text-2xl sm:text-3xl">فحص جاهزية النظام</h1>
            <p className="mt-1 text-ink-3">بيئة التطوير المحلية — المهمة 00: البنية التحتية</p>
          </div>
          <button onClick={load} disabled={loading} aria-label="إعادة الفحص"
            className="flex shrink-0 items-center gap-2 rounded-lg bg-brand px-4 py-2 font-semibold text-on-brand transition active:scale-95 disabled:opacity-60">
            <RefreshCw size={16} className={loading ? 'animate-spin' : ''} /> إعادة الفحص
          </button>
        </div>

        <section className={`fadeup mb-6 rounded-2xl border p-5 ${first ? 'border-line bg-surface' : allOk ? 'border-brand/40 bg-brand-soft' : 'border-danger/40 bg-danger-soft'}`}>
          <div className="flex items-center gap-3">
            {first ? <Loader2 className="animate-spin text-ink-3" /> : allOk ? <CheckCircle2 className="text-brand" /> : <XCircle className="text-danger" />}
            <div>
              <h2 className="text-lg">{first ? 'جاري الفحص…' : allOk ? 'كل الخدمات تعمل ومتصلة' : 'هناك خدمة لا تستجيب'}</h2>
              {checkedAt && <p className="num text-xs text-ink-3">آخر فحص: {checkedAt.toLocaleTimeString('ar-SY-u-nu-latn')} · تحديث تلقائي كل 10 ثوانٍ</p>}
            </div>
          </div>
        </section>

        <ul className="space-y-3">
          {rows.map((r, i) => (
            <li key={r.key} style={{ animationDelay: `${i * 60}ms` }} className="fadeup hoverlift flex items-center gap-4 rounded-2xl border border-line bg-surface p-4">
              <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand"><r.icon size={20} /></span>
              <div className="min-w-0 flex-1">
                <div className="font-semibold">{r.title}</div>
                <div className="ltr truncate text-xs text-ink-3">{r.sub}</div>
                {r.check && !r.check.ok && r.check.error && <div className="mt-1 break-words text-xs text-danger" dir="auto">{r.check.error}</div>}
              </div>
              <div className="text-end">
                {!r.check ? <Loader2 size={18} className="animate-spin text-ink-3" /> : (
                  <>
                    <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${r.check.ok ? 'bg-brand-soft text-brand' : 'bg-danger-soft text-danger'}`}>
                      {r.check.ok ? 'متصل' : 'متوقف'}
                    </span>
                    <div className="num mt-1 text-xs text-ink-3">{r.meta ?? (r.check.ok ? `${r.check.ms} ms` : '')}</div>
                  </>
                )}
              </div>
            </li>
          ))}
        </ul>

        {health && (
          <footer className="fadeup mt-6 grid grid-cols-2 gap-3 text-xs text-ink-3 sm:grid-cols-4">
            <Info k="البيئة" v={health.app.env} />
            <Info k="المنطقة الزمنية" v={health.app.timezone} />
            <Info k="وقت الخادم" v={new Date(health.app.time).toLocaleString('ar-SY-u-nu-latn')} />
            <Info k="زمن الاستجابة" v={`${apiMs} ms`} />
          </footer>
        )}
      </main>
    </div>
  )
}

function Info({ k, v }: { k: string; v: string }) {
  return (
    <div className="rounded-xl border border-line bg-surface px-3 py-2">
      <div>{k}</div>
      <div className="num mt-0.5 truncate font-semibold text-ink-2" dir="auto">{v}</div>
    </div>
  )
}
