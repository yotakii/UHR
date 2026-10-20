import { useEffect, useRef, useState } from 'react'
import { Nfc, Maximize, Cpu, Smartphone, ChevronDown, CheckCircle2, AlertTriangle, XCircle, UserRound, KeyRound, LogOut, Loader2 } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { useL, useLang, bi } from '../../lib/store'
import { api, ApiError } from '../../lib/api'
import { toggleFullscreen } from '../../lib/ui'
import { analyse, beep, hasWebNfc, startWebNfc, useCardReader } from '../../lib/cardReader'
import { minsLabel } from '../../lib/employees'
import type { Bi } from '../../lib/types'
import { Logo, LangToggle } from '../../ui/brand'

/**
 * شاشة جهاز الحضور (Terminal في الديمو) — تعمل فقط على جهاز مسجّل: مفتاح الجهاز من «أجهزة الحضور» يُحفظ في المتصفح (uhr-device).
 * القراءة: قارئ USB كلوحة مفاتيح، أو NFC الجوال/Sunmi (Web NFC)، أو UHR-NFC-Bridge يرسل للخادم مباشرة (تظهر حركاته في الشريط).
 * بلا محاكاة ولا ربط بطاقات من الشاشة — ربط البطاقة من ملف الموظف.
 */
const KEY = 'uhr-device'
const getKey = () => { try { return localStorage.getItem(KEY) ?? '' } catch { return '' } }
type Person = { id: number; code: string; name: Bi; title: Bi; dept: Bi | null }
type Feed = { id: number; name: Bi; type: 'in' | 'out'; time: string; via: string }[]
type Me = { device: { id: number; name: Bi; kind: string }; company: { slug: string; name: Bi }; feed: Feed; cooldown: number }
type Result = { kind: 'ok' | 'dup' | 'unknown' | 'inactive'; employee?: Person; type?: 'in' | 'out'; time?: string; late?: number; worked?: number; ot?: number; uid?: string | null; feed?: Feed }

const call = <T,>(key: string, path: string, json?: unknown) => api<T>(`/device${path}`, { method: json ? 'POST' : 'GET', json, headers: { 'X-Device-Key': key } })

export function Terminal() {
  const [key, setKey] = useState(getKey())
  return key ? <Kiosk deviceKey={key} onUnpair={() => { try { localStorage.removeItem(KEY) } catch { /* */ } setKey('') }} /> : <Pair onPaired={k => { try { localStorage.setItem(KEY, k) } catch { /* */ } setKey(k) }} />
}

function Pair({ onPaired }: { onPaired: (k: string) => void }) {
  const L = useL(); const lang = useLang()
  const [k, setK] = useState(''); const [busy, setBusy] = useState(false); const [err, setErr] = useState('')
  const go = async () => { setBusy(true); setErr(''); try { await call<Me>(k.trim(), '/me'); onPaired(k.trim()) } catch (x) { setErr((x as ApiError).message) } finally { setBusy(false) } }
  return <div dir={lang === 'ar' ? 'rtl' : 'ltr'} className="show-root fixed inset-0 grid place-items-center p-6">
    <div className="show-bg" aria-hidden><i /><i /><i /></div>
    <form onSubmit={e => { e.preventDefault(); go() }} className="relative z-10 w-full max-w-[460px] rounded-3xl border border-show-fg/15 bg-show-fg/[.04] p-7 text-center">
      <Logo pill h={26} />
      <h1 className="mt-5 text-[22px] font-bold">{L('تسجيل جهاز الحضور', 'Register this attendance device')}</h1>
      <p className="mt-2 text-show-fg/60 text-[14px]">{L('من حساب الموارد البشرية: الإعدادات ← أجهزة الحضور ← «إضافة جهاز»، ثم الصق المفتاح هنا مرة واحدة.', 'From the HR account: Settings → Attendance devices → “Add device”, then paste the key here once.')}</p>
      <div className="relative mt-5"><KeyRound size={16} className="absolute top-1/2 -translate-y-1/2 start-3 text-show-fg/40" />
        <input autoFocus value={k} onChange={e => setK(e.target.value)} placeholder={L('مفتاح الجهاز', 'Device key')} className="w-full h-11 rounded-xl bg-show-fg/5 border border-show-fg/15 ps-9 pe-3 outline-none focus:border-brand ltr font-mono text-[13px]" /></div>
      {err && <div role="alert" className="mt-3 text-[13px] text-danger">{err}</div>}
      <button disabled={k.trim().length < 32 || busy} className="mt-5 h-11 w-full rounded-xl bg-brand text-on-brand font-bold disabled:opacity-50 inline-flex items-center justify-center gap-2">{busy && <Loader2 size={16} className="animate-spin" />}{L('تفعيل الجهاز', 'Activate device')}</button>
      <div className="mt-4"><LangToggle onDark /></div>
    </form>
  </div>
}

function Kiosk({ deviceKey, onUnpair }: { deviceKey: string; onUnpair: () => void }) {
  const L = useL(); const lang = useLang(); const ar = lang === 'ar'
  const me = useQuery({ queryKey: ['device', 'me'], queryFn: () => call<Me>(deviceKey, '/me'), refetchInterval: 3000, retry: false })
  const [now, setNow] = useState(new Date())
  const [res, setRes] = useState<Result | null>(null); const [stamp, setStamp] = useState(0)
  const [diag, setDiag] = useState(false); const [phone, setPhone] = useState<'off' | 'on' | 'err'>('off'); const [signal, setSignal] = useState('')
  const [card, setCard] = useState<ReturnType<typeof analyse> | null>(null)
  const [keylog, setKeylog] = useState<{ k: string; c: string; g: number }[]>([]); const lastK = useRef(0)
  const seen = useRef<number | null>(null); const busy = useRef(false)
  useEffect(() => { const t = setInterval(() => setNow(new Date()), 1000); return () => clearInterval(t) }, [])
  useEffect(() => { if (!signal) return; const t = setTimeout(() => setSignal(''), 1500); return () => clearTimeout(t) }, [signal])
  useEffect(() => { if (!res) return; const t = setTimeout(() => setRes(null), res.kind === 'unknown' ? 12000 : 7000); return () => clearTimeout(t) }, [res, stamp])
  useEffect(() => { if (!diag) return; const f = (e: KeyboardEvent) => { const n = performance.now(); const g = lastK.current ? Math.round(n - lastK.current) : 0; lastK.current = n; setKeylog(x => [{ k: e.key, c: e.code, g }, ...x].slice(0, 14)) }; window.addEventListener('keydown', f, true); return () => window.removeEventListener('keydown', f, true) }, [diag])
  // حركات جسر القارئ (تصل للخادم مباشرة): تُعرض عند ظهورها في الشريط
  const head = me.data?.feed[0]
  useEffect(() => {
    if (!head) return
    if (seen.current !== null && head.id !== seen.current && head.via === 'bridge' && !busy.current) { setRes({ kind: 'ok', employee: { id: 0, code: '', name: head.name, title: { ar: '', en: '' }, dept: null }, type: head.type, time: head.time }); setStamp(Date.now()); beep(true) }
    seen.current = head.id
  }, [head?.id])   // eslint-disable-line react-hooks/exhaustive-deps

  const handle = async (raw: string, via: 'usb' | 'phone') => {
    setCard(analyse(raw)); busy.current = true
    try {
      const r = await call<Result>(deviceKey, '/scan', { uid: raw, via })
      setRes(r); setStamp(Date.now()); beep(r.kind === 'ok')
      if (r.feed?.[0]) seen.current = r.feed[0].id
      me.refetch()
    } catch (x) { setRes({ kind: 'unknown', uid: (x as ApiError).message }); beep(false) } finally { busy.current = false }
  }
  useCardReader((raw, via) => handle(raw, via), true, b => setSignal(b))
  const startPhone = async () => { try { await startWebNfc(uid => handle(uid, 'phone')); setPhone('on') } catch { setPhone('err') } }

  if (me.error instanceof ApiError && me.error.status === 401) return <div dir={ar ? 'rtl' : 'ltr'} className="show-root fixed inset-0 grid place-items-center p-6 text-center">
    <div><XCircle size={56} className="mx-auto text-danger" /><h1 className="mt-4 text-[26px] font-bold">{L('الجهاز غير مسجّل أو موقوف', 'Device not registered or disabled')}</h1>
      <p className="mt-2 text-show-fg/60">{me.error.message}</p><button onClick={onUnpair} className="mt-6 h-11 px-6 rounded-xl bg-brand text-on-brand font-bold">{L('إدخال مفتاح جديد', 'Enter a new key')}</button></div></div>

  const device = me.data?.device
  const tone = res?.kind === 'ok' ? (res.type === 'in' && res.late ? 'warn' : 'ok') : res ? 'bad' : 'idle'
  const ring = tone === 'ok' ? 'var(--primary)' : tone === 'warn' ? 'var(--warn)' : tone === 'bad' ? 'var(--danger)' : 'color-mix(in oklab, var(--showcase-fg) 25%, transparent)'
  const hm = (d: Date) => `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`

  return <div dir={ar ? 'rtl' : 'ltr'} className="show-root fixed inset-0 z-[80] overflow-hidden flex flex-col">
    <div className="show-bg" aria-hidden><i /><i /><i /></div><div className="show-grid" aria-hidden />
    <header className="relative z-10 flex flex-wrap items-center justify-between gap-3 px-4 sm:px-10 pt-5">
      <div className="flex items-center gap-4"><Logo pill h={26} /><div className="hidden md:block"><div className="font-display font-bold">{L('جهاز الحضور', 'Attendance terminal')}</div><div className="text-[12px] text-show-fg/55">{device ? bi(device.name, lang) : '…'}{me.data && <> · {bi(me.data.company.name, lang)}</>}</div></div></div>
      <div className="flex flex-wrap items-center gap-2">
        <span className={`flex items-center gap-2 rounded-full border px-3 h-8 text-[12px] transition-colors ${signal ? 'border-brand bg-brand/15 text-brand' : 'border-show-fg/15'}`}><span className="size-2 rounded-full bg-brand live-dot" /><Cpu size={14} />{signal ? <span>{L('استقبال من القارئ…', 'Receiving…')} <span className="ltr font-mono">{signal}</span></span> : L('القارئ جاهز', 'Reader ready')}</span>
        {hasWebNfc() && <button onClick={startPhone} className={`flex items-center gap-1.5 rounded-full border px-3 h-8 text-[12px] ${phone === 'on' ? 'border-brand text-brand' : 'border-show-fg/15'}`}><Smartphone size={14} />{phone === 'on' ? L('NFC الجهاز فعّال', 'Device NFC on') : phone === 'err' ? L('تعذّر تفعيل NFC', 'NFC unavailable') : L('تفعيل NFC الجهاز', 'Use device NFC')}</button>}
        <LangToggle onDark />
        <button onClick={toggleFullscreen} className="size-9 grid place-items-center rounded-lg hover:bg-show-fg/10" aria-label="fullscreen"><Maximize size={17} /></button>
      </div>
    </header>

    <main className="relative z-10 flex-1 grid lg:grid-cols-[1fr_360px] gap-4 sm:gap-6 px-4 sm:px-10 py-5 min-h-0 overflow-y-auto lg:overflow-hidden">
      <section className="relative flex flex-col items-center justify-center text-center rounded-3xl border border-show-fg/10 bg-show-fg/[.03] overflow-hidden min-h-[440px]">
        {!res && <div className="flex flex-col items-center fadeup px-4">
          <div className="font-display font-black num ltr text-[clamp(56px,9vw,128px)] leading-none tracking-tight">{hm(now)}<span className="text-show-fg/35 text-[.4em] ms-2">{String(now.getSeconds()).padStart(2, '0')}</span></div>
          <div className="mt-2 text-show-fg/60 text-[17px]">{now.toLocaleDateString(ar ? 'ar-SY-u-nu-latn' : 'en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</div>
          <div className="relative mt-10 size-40 sm:size-48 grid place-items-center">
            <span className="absolute inset-0 rounded-full border-2" style={{ borderColor: ring }} />
            <span className="absolute inset-0 rounded-full scan-pulse" style={{ animationIterationCount: 'infinite', animationDuration: '2.2s' }} />
            <span className="absolute inset-5 rounded-full border border-show-fg/10" />
            <Nfc size={72} className="text-brand" />
          </div>
          <div className="mt-8 text-[20px] font-semibold">{L('قرّب بطاقتك من القارئ', 'Hold your card near the reader')}</div>
          <div className="mt-1 text-show-fg/50 text-[14px]">{L('يُسجَّل الدخول أو الخروج تلقائياً', 'Check-in or check-out is detected automatically')}</div>
        </div>}

        {res?.kind === 'ok' && res.employee && <div key={stamp} className="w-full max-w-[720px] px-6 show-in">
          <div className="mx-auto relative size-36 sm:size-40 grid place-items-center">
            <span className="absolute inset-0 rounded-full scan-pulse" style={{ boxShadow: `0 0 0 0 ${ring}` }} />
            <span className="absolute inset-0 rounded-full border-[3px]" style={{ borderColor: ring }} />
            <span className="size-28 sm:size-32 rounded-full grid place-items-center" style={{ background: `color-mix(in oklab, ${ring} 18%, transparent)`, color: ring }}><UserRound size={64} strokeWidth={1.6} /></span>
          </div>
          <div className="mt-5 inline-flex items-center gap-2 rounded-full px-4 h-9 font-bold text-[15px]" style={{ background: `color-mix(in oklab, ${ring} 18%, transparent)`, color: ring }}>
            <CheckCircle2 size={18} />{res.type === 'in' ? L('تم تسجيل الدخول', 'Checked in') : L('تم تسجيل الخروج', 'Checked out')}</div>
          <h2 className="mt-4 text-[clamp(28px,4vw,52px)] font-black">{bi(res.employee.name, lang)}</h2>
          {res.employee.code && <div className="text-show-fg/60 text-[17px]">{bi(res.employee.title, lang)}{res.employee.dept && <> · {bi(res.employee.dept, lang)}</>}</div>}
          {res.late !== undefined && <div className="mt-7 grid grid-cols-3 gap-2 sm:gap-3 text-start">
            {[[L('الوقت', 'Time'), <span className="ltr num">{res.time}</span>],
              [res.type === 'in' ? L('الحالة', 'Status') : L('ساعات العمل', 'Worked'), res.type === 'in' ? (res.late ? <span className="text-brass">{L('متأخر', 'Late')} {minsLabel(res.late, lang)}</span> : <span className="text-brand">{L('في الموعد', 'On time')}</span>) : minsLabel(res.worked ?? 0, lang)],
              [res.type === 'in' ? L('الرقم الوظيفي', 'Employee ID') : L('عمل إضافي', 'Overtime'), res.type === 'in' ? <span className="ltr">{res.employee.code}</span> : (res.ot ? <span className="text-brand">{minsLabel(res.ot, lang)}</span> : '—')]].map(([k, v], i) =>
              <div key={i} className="rounded-2xl border border-show-fg/10 bg-show-fg/[.04] p-3 sm:p-4 fadeup min-w-0" style={{ animationDelay: `${.25 + i * .12}s` }}><div className="text-[12px] text-show-fg/50">{k}</div><div className="mt-1 text-[17px] sm:text-[22px] font-bold font-display">{v}</div></div>)}
          </div>}
          {res.type === 'in' && !!res.late && <div className="mt-4 text-[14px] text-show-fg/65">{L('يمكنك تقديم تبرير التأخير من حسابك في النظام.', 'You can submit a late justification from your account.')}</div>}
        </div>}

        {res?.kind === 'dup' && res.employee && <div key={stamp} className="show-in px-6"><AlertTriangle size={64} className="mx-auto text-brass" /><h2 className="mt-4 text-[34px] font-black">{bi(res.employee.name, lang)}</h2>
          <p className="mt-2 text-show-fg/65 text-[18px]">{L(`سُجّلت حركتك قبل ثوانٍ — انتظر ${me.data?.cooldown ?? 10} ثوانٍ قبل التمرير مجدداً.`, `You were just recorded — wait ${me.data?.cooldown ?? 10} seconds before tapping again.`)}</p></div>}
        {(res?.kind === 'unknown' || res?.kind === 'inactive') && <div key={stamp} className="show-in px-6 w-full max-w-[560px]">
          <XCircle size={64} className="mx-auto text-danger" /><h2 className="mt-4 text-[32px] font-black">{res.kind === 'inactive' ? L('البطاقة لموظف انتهت خدمته', 'Card belongs to a former employee') : L('بطاقة غير مسجّلة', 'Unregistered card')}</h2>
          <p className="mt-2 text-show-fg/65">{L('راجع الموارد البشرية لربط البطاقة بملفك.', 'Ask HR to link the card to your file.')}</p>
          {res.uid && <div className="mt-3 ltr num text-show-fg/50 text-[13px]">UID {res.uid}</div>}
        </div>}

        <div className="absolute bottom-4 inset-x-4 flex justify-center">
          <button onClick={() => setDiag(!diag)} className="flex items-center gap-1.5 text-[12px] text-show-fg/45 hover:text-show-fg/80"><Cpu size={13} />{L('بيانات القراءة', 'Read data')}<ChevronDown size={13} className={diag ? 'rotate-180' : ''} /></button>
        </div>
        {diag && <div className="absolute top-4 end-4 w-[250px] rounded-xl border border-show-fg/15 bg-show/95 p-3 text-start ltr font-mono text-[11.5px] fadeup">
          <div className="text-show-fg/55 mb-1.5 font-sans">{L('اختبار القارئ: كل إشارة تصل من لوحة المفاتيح/القارئ', 'Reader test: every keyboard/reader signal')}</div>
          {keylog.length === 0 ? <div className="text-show-fg/40">— {L('لم تصل أي إشارة', 'no signal yet')}</div> : keylog.map((x, i) => <div key={i} className="flex justify-between"><span>{x.k}</span><span className="text-show-fg/50">{x.c}</span><span className="text-brand">+{x.g}ms</span></div>)}
          <button onClick={onUnpair} className="mt-3 w-full flex items-center justify-center gap-1.5 rounded-lg border border-show-fg/15 h-8 font-sans text-show-fg/70 hover:text-show-fg"><LogOut size={13} />{L('إلغاء تسجيل الجهاز', 'Unregister device')}</button>
        </div>}
        {diag && card && <div className="absolute bottom-12 inset-x-6 mx-auto max-w-[620px] rounded-xl border border-show-fg/15 bg-show/95 p-4 text-start ltr fadeup">
          <div className="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-2 text-[12.5px] font-mono">
            {[['Raw input', card.raw], ['Format', card.format], ['Length', `${card.bytes} bytes / ${card.bits} bit`], ['UID (hex)', card.hex], ['UID reversed', card.reversed], ['Matched', res?.employee?.code || '—']].map(([k, v]) => <div key={k} className="min-w-0"><div className="text-show-fg/45 text-[11px]">{k}</div><div className="truncate">{v}</div></div>)}
          </div></div>}
      </section>

      <aside className="flex flex-col rounded-3xl border border-show-fg/10 bg-show-fg/[.03] min-h-[240px]">
        <div className="px-5 pt-5 pb-3 flex items-center justify-between"><span className="font-display font-bold">{L('حركات اليوم', 'Today’s activity')}</span><span className="text-[12px] text-show-fg/50 num">{now.toLocaleDateString(ar ? 'ar-SY-u-nu-latn' : 'en-GB', { day: 'numeric', month: 'short' })}</span></div>
        <ul className="flex-1 overflow-y-auto px-3 pb-3 space-y-1.5">{!me.data?.feed.length && <li className="px-2 py-6 text-center text-[13px] text-show-fg/45">{L('لا توجد حركات من هذا الجهاز بعد', 'No taps on this terminal yet')}</li>}
          {me.data?.feed.map((x, i) => <li key={x.id} className={`flex items-center gap-3 rounded-xl px-3 py-2.5 ${i === 0 ? 'bg-show-fg/[.07] fadeup' : ''}`}><span className="size-9 rounded-full grid place-items-center bg-brand/15 text-brand shrink-0"><UserRound size={18} /></span>
            <span className="flex-1 min-w-0"><span className="block text-[14px] font-semibold truncate">{bi(x.name, lang)}</span><span className="block text-[11.5px] text-show-fg/50">{x.type === 'in' ? L('دخول', 'In') : L('خروج', 'Out')}</span></span><span className="ltr num text-[13px] text-show-fg/70">{x.time}</span></li>)}</ul>
      </aside>
    </main>
  </div>
}
