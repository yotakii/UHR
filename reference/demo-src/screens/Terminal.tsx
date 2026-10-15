import { useEffect, useRef, useState } from 'react'
import { Nfc, Maximize, ArrowRight, ArrowLeft, Link2, Cpu, Smartphone, ChevronDown, CheckCircle2, AlertTriangle, XCircle, Search, UserRound } from 'lucide-react'
import { useStore, useL, useLang, todayISO, fmtDate, fmtTime, getState, update, ev } from '../lib/store'
import { nfcScan } from '../lib/actions'
import { toggleFullscreen } from '../lib/ui'
import { Logo, LangToggle } from '../ui/brand'
import { calcDay, minsLabel } from '../lib/rules'
import type { Employee } from '../lib/types'

/* ---------- card data helpers ---------- */
export const normUid = (raw: string) => raw.replace(/[^0-9a-zA-Z]/g, '').toUpperCase()
function analyse(raw: string) {
  const n = normUid(raw)
  const isDec = /^\d+$/.test(n)
  let hex = n
  if (isDec) { try { hex = BigInt(n).toString(16).toUpperCase(); if (hex.length % 2) hex = '0' + hex } catch { /* ignore */ } }
  const bytes = hex.match(/.{1,2}/g) ?? []
  return { raw, norm: n, format: isDec ? 'DEC' : 'HEX', hex: bytes.join(':'), reversed: [...bytes].reverse().join(':'), bytes: bytes.length, bits: bytes.length * 8 }
}
export function findByCard(raw: string): Employee | undefined {
  const a = analyse(raw); const keys = new Set([a.norm, normUid(a.hex), normUid(a.reversed)])
  return getState().employees.find(e => e.status !== 'terminated' && keys.has(normUid(e.nfcUid)))
}
export function bindCard(empId: string, raw: string) {
  update(s => {
    const n = normUid(raw)
    s.employees.forEach(e => { if (normUid(e.nfcUid) === n) e.nfcUid = '' })
    const e = s.employees.find(x => x.id === empId)!; e.nfcUid = n
    e.log.push(ev(`ربط بطاقة NFC جديدة (${n})`, `NFC card linked (${n})`, 'ok'))
  })
}

/* ---------- reader hook: keyboard-wedge USB readers + Web NFC ----------
   Uses the physical key code (e.code), so it works even when the OS keyboard is set to Arabic. */
const AR_DIGITS = '٠١٢٣٤٥٦٧٨٩'
function keyChar(e: KeyboardEvent): string | null {
  let m = /^(?:Digit|Numpad)(\d)$/.exec(e.code); if (m) return m[1]
  m = /^Key([A-Z])$/.exec(e.code); if (m) return m[1]
  if (e.key.length === 1) { const i = AR_DIGITS.indexOf(e.key); if (i >= 0) return String(i); if (/[0-9a-zA-Z:]/.test(e.key)) return e.key }
  return null
}
export function useCardReader(onCard: (raw: string, via: 'usb' | 'phone') => void, enabled = true, onSignal?: (buf: string) => void) {
  const buf = useRef(''); const start = useRef(0); const last = useRef(0); const timer = useRef<number>(0)
  const cb = useRef(onCard); cb.current = onCard; const sig = useRef(onSignal); sig.current = onSignal
  useEffect(() => {
    if (!enabled) return
    const isReader = () => buf.current.length >= 4 && (last.current - start.current) / Math.max(1, buf.current.length - 1) < 75
    const flush = () => { const v = buf.current; buf.current = ''; if (v) cb.current(v, 'usb') }
    const down = (e: KeyboardEvent) => {
      const now = performance.now()
      if (e.key === 'Enter' || e.code === 'NumpadEnter' || e.key === 'Tab') {
        if (isReader()) { e.preventDefault(); e.stopPropagation(); clearTimeout(timer.current); flush() } else buf.current = ''
        return
      }
      const ch = keyChar(e); if (!ch) return
      if (!buf.current || now - last.current > 120) { buf.current = ''; start.current = now }
      else if (now - last.current < 60) { const t = e.target as HTMLElement; if (!(t instanceof HTMLInputElement && t.dataset.manual)) e.preventDefault() }
      buf.current += ch; last.current = now
      if (buf.current.length >= 3) sig.current?.(buf.current)
      clearTimeout(timer.current); timer.current = window.setTimeout(() => { if (buf.current.length >= 6 && isReader()) flush(); else buf.current = '' }, 220)
    }
    window.addEventListener('keydown', down, true)
    return () => { window.removeEventListener('keydown', down, true); clearTimeout(timer.current) }
  }, [enabled])
}
type NDEF = { scan: () => Promise<void>; addEventListener: (t: string, f: (e: { serialNumber: string }) => void) => void }
export const hasWebNfc = () => typeof window !== 'undefined' && 'NDEFReader' in window

/* ---------- sound ---------- */
function beep(ok: boolean) {
  try { const C = new AudioContext(); const o = C.createOscillator(); const g = C.createGain(); o.connect(g); g.connect(C.destination)
    o.frequency.value = ok ? 1320 : 330; g.gain.setValueAtTime(.18, C.currentTime); g.gain.exponentialRampToValueAtTime(.001, C.currentTime + (ok ? .18 : .45)); o.start(); o.stop(C.currentTime + (ok ? .2 : .5))
    if (ok) { const o2 = C.createOscillator(); const g2 = C.createGain(); o2.connect(g2); g2.connect(C.destination); o2.frequency.value = 1760; g2.gain.setValueAtTime(.14, C.currentTime + .12); g2.gain.exponentialRampToValueAtTime(.001, C.currentTime + .32); o2.start(C.currentTime + .12); o2.stop(C.currentTime + .34) }
  } catch { /* no audio */ }
}

type Result =
  | { kind: 'ok'; emp: Employee; type: 'in' | 'out'; time: string; late: number; ot: number; worked: number; inAt?: string; card: ReturnType<typeof analyse> }
  | { kind: 'dup'; emp: Employee; card: ReturnType<typeof analyse> }
  | { kind: 'unknown'; card: ReturnType<typeof analyse> }

export function Terminal({ onBack }: { onBack?: () => void }) {
  const L = useL(); const lang = useLang(); const ar = lang === 'ar'
  const emps = useStore(s => s.employees.filter(e => e.status !== 'terminated'))
  const depts = useStore(s => s.depts)
  const scans = useStore(s => s.scans.filter(x => x.ts.startsWith(todayISO())).slice(0, 7))
  const [now, setNow] = useState(new Date())
  const [res, setRes] = useState<Result | null>(null); const [stamp, setStamp] = useState(0)
  const [diag, setDiag] = useState(false); const [sim, setSim] = useState(false); const [q, setQ] = useState('')
  const [enroll, setEnroll] = useState(false); const [eq, setEq] = useState('')
  const [phone, setPhone] = useState<'off' | 'on' | 'err'>('off')
  const [signal, setSignal] = useState(''); const [manual, setManual] = useState('')
  const [keylog, setKeylog] = useState<{ k: string; c: string; g: number }[]>([]); const lastK = useRef(0)
  useEffect(() => { if (!diag) return; const f = (e: KeyboardEvent) => { const n = performance.now(); const g = lastK.current ? Math.round(n - lastK.current) : 0; lastK.current = n; setKeylog(x => [{ k: e.key, c: e.code, g }, ...x].slice(0, 14)) }; window.addEventListener('keydown', f, true); return () => window.removeEventListener('keydown', f, true) }, [diag])
  useEffect(() => { if (!signal) return; const t = setTimeout(() => setSignal(''), 1500); return () => clearTimeout(t) }, [signal])
  const lastBy = useRef<Record<string, number>>({})
  const device = { ar: 'بوابة المقر — دمشق', en: 'HQ gate — Damascus' }
  useEffect(() => { const t = setInterval(() => setNow(new Date()), 1000); return () => clearInterval(t) }, [])
  useEffect(() => { if (!res || enroll) return; const t = setTimeout(() => setRes(null), res.kind === 'unknown' ? 15000 : 7000); return () => clearTimeout(t) }, [res, stamp, enroll])

  const handle = (raw: string) => {
    const card = analyse(raw); const emp = findByCard(raw)
    setStamp(Date.now()); setEnroll(false)
    if (!emp) { beep(false); setRes({ kind: 'unknown', card }); return }
    if (Date.now() - (lastBy.current[emp.id] ?? 0) < 10000) { beep(false); setRes({ kind: 'dup', emp, card }); return }
    lastBy.current[emp.id] = Date.now()
    const r = nfcScan(emp.id, new Date(), device)
    const d = getState().days.find(x => x.empId === emp.id && x.date === todayISO())
    const c = calcDay(todayISO(), d?.inAt, d?.outAt)
    beep(true); setRes({ kind: 'ok', emp, type: r.type, time: fmtTime(r.time), late: c.late, ot: c.ot, worked: c.worked, inAt: d?.inAt, card })
  }
  useCardReader(raw => handle(raw), true, b => setSignal(b))
  const startPhone = async () => {
    try { const R = (window as unknown as { NDEFReader: new () => NDEF }).NDEFReader; const r = new R(); await r.scan(); setPhone('on'); r.addEventListener('reading', e => handle(e.serialNumber)) } catch { setPhone('err') }
  }
  const Back = ar ? ArrowRight : ArrowLeft
  const dn = (e: Employee) => (ar ? e.name : e.nameEn)
  const dept = (e: Employee) => depts.find(d => d.id === e.dept)?.[lang]
  const tone = res?.kind === 'ok' ? (res.type === 'in' && res.late ? 'warn' : 'ok') : res ? 'bad' : 'idle'
  const ring = tone === 'ok' ? 'var(--primary)' : tone === 'warn' ? 'var(--warn)' : tone === 'bad' ? 'var(--danger)' : 'color-mix(in oklab, var(--showcase-fg) 25%, transparent)'

  return <div dir={ar ? 'rtl' : 'ltr'} className="show-root fixed inset-0 z-[80] overflow-hidden flex flex-col">
    <div className="show-bg" aria-hidden><i /><i /><i /></div><div className="show-grid" aria-hidden />
    {/* top bar */}
    <header className="relative z-10 flex items-center justify-between gap-4 px-6 sm:px-10 pt-6">
      <div className="flex items-center gap-4"><Logo pill h={26} /><div className="hidden md:block"><div className="font-display font-bold">{L('جهاز الحضور', 'Attendance terminal')}</div><div className="text-[12px] text-show-fg/55">{device[lang]}</div></div></div>
      <div className="flex items-center gap-2">
        <span className={`flex items-center gap-2 rounded-full border px-3 h-8 text-[12px] transition-colors ${signal ? 'border-brand bg-brand/15 text-brand' : 'border-show-fg/15'}`}><span className="size-2 rounded-full bg-brand live-dot" /><Cpu size={14} />{signal ? <span>{L('استقبال من القارئ…', 'Receiving…')} <span className="ltr font-mono">{signal}</span></span> : L('القارئ جاهز', 'Reader ready')}</span>
        {hasWebNfc() && <button onClick={startPhone} className={`flex items-center gap-1.5 rounded-full border px-3 h-8 text-[12px] ${phone === 'on' ? 'border-brand text-brand' : 'border-show-fg/15'}`}><Smartphone size={14} />{phone === 'on' ? L('NFC الجوال فعّال', 'Phone NFC on') : phone === 'err' ? L('تعذّر تفعيل NFC', 'NFC unavailable') : L('تفعيل NFC الجوال', 'Use phone NFC')}</button>}
        <LangToggle onDark />
        <button onClick={toggleFullscreen} className="size-9 grid place-items-center rounded-lg hover:bg-show-fg/10" aria-label="fullscreen"><Maximize size={17} /></button>
        {onBack && <button onClick={onBack} className="h-9 px-3 rounded-lg hover:bg-show-fg/10 flex items-center gap-1.5 text-[13px]"><Back size={15} />{L('خروج', 'Exit')}</button>}
      </div>
    </header>

    <main className="relative z-10 flex-1 grid lg:grid-cols-[1fr_360px] gap-6 px-6 sm:px-10 py-6 min-h-0">
      {/* stage */}
      <section className="relative flex flex-col items-center justify-center text-center rounded-3xl border border-show-fg/10 bg-show-fg/[.03] overflow-hidden">
        {!res && <div className="flex flex-col items-center fadeup">
          <div className="font-display font-black num ltr text-[clamp(64px,9vw,128px)] leading-none tracking-tight">{fmtTime(now.toISOString())}<span className="text-show-fg/35 text-[.4em] ms-2">{String(now.getSeconds()).padStart(2, '0')}</span></div>
          <div className="mt-2 text-show-fg/60 text-[17px]">{now.toLocaleDateString(ar ? 'ar-SY-u-nu-latn' : 'en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</div>
          <div className="relative mt-12 size-48 grid place-items-center">
            <span className="absolute inset-0 rounded-full border-2" style={{ borderColor: ring }} />
            <span className="absolute inset-0 rounded-full scan-pulse" style={{ animationIterationCount: 'infinite', animationDuration: '2.2s' }} />
            <span className="absolute inset-5 rounded-full border border-show-fg/10" />
            <Nfc size={72} className="text-brand" />
          </div>
          <div className="mt-8 text-[20px] font-semibold">{L('قرّب بطاقتك من القارئ', 'Hold your card near the reader')}</div>
          <div className="mt-1 text-show-fg/50 text-[14px]">{L('يُسجَّل الدخول أو الخروج تلقائياً', 'Check-in or check-out is detected automatically')}</div>
        </div>}

        {res?.kind === 'ok' && <div key={stamp} className="w-full max-w-[720px] px-6 show-in">
          <div className="mx-auto relative size-40 grid place-items-center">
            <span className="absolute inset-0 rounded-full scan-pulse" style={{ boxShadow: `0 0 0 0 ${ring}` }} />
            <span className="absolute inset-0 rounded-full border-[3px]" style={{ borderColor: ring }} />
            <span className="size-32 rounded-full grid place-items-center font-display font-black text-[56px]" style={{ background: `color-mix(in oklab, ${ring} 18%, transparent)`, color: ring }}><UserRound size={64} strokeWidth={1.6} /></span>
          </div>
          <div className="mt-5 inline-flex items-center gap-2 rounded-full px-4 h-9 font-bold text-[15px]" style={{ background: `color-mix(in oklab, ${ring} 18%, transparent)`, color: ring }}>
            {res.type === 'in' ? <CheckCircle2 size={18} /> : <CheckCircle2 size={18} />}{res.type === 'in' ? L('تم تسجيل الدخول', 'Checked in') : L('تم تسجيل الخروج', 'Checked out')}</div>
          <h2 className="mt-4 text-[clamp(30px,4vw,52px)] font-black">{dn(res.emp)}</h2>
          <div className="text-show-fg/60 text-[17px]">{res.emp.title[lang]} · {dept(res.emp)}</div>
          <div className="mt-7 grid grid-cols-3 gap-3 text-start">
            {[[L('الوقت', 'Time'), <span className="ltr num">{res.time}</span>],
              [res.type === 'in' ? L('الحالة', 'Status') : L('ساعات العمل', 'Worked'), res.type === 'in' ? (res.late ? <span className="text-brass">{L('متأخر', 'Late')} {minsLabel(res.late, lang)}</span> : <span className="text-brand">{L('في الموعد', 'On time')}</span>) : minsLabel(res.worked, lang)],
              [res.type === 'in' ? L('الرقم الوظيفي', 'Employee ID') : L('عمل إضافي', 'Overtime'), res.type === 'in' ? <span className="ltr">{res.emp.code}</span> : (res.ot ? <span className="text-brand">{minsLabel(res.ot, lang)}</span> : '—')]].map(([k, v], i) =>
              <div key={i} className="rounded-2xl border border-show-fg/10 bg-show-fg/[.04] p-4 fadeup" style={{ animationDelay: `${.25 + i * .12}s` }}><div className="text-[12px] text-show-fg/50">{k}</div><div className="mt-1 text-[22px] font-bold font-display">{v}</div></div>)}
          </div>
          {res.type === 'in' && res.late > 0 && <div className="mt-4 text-[14px] text-show-fg/65">{L('يمكنك تقديم تبرير التأخير من حسابك في النظام.', 'You can submit a late justification from your account.')}</div>}
        </div>}

        {res?.kind === 'dup' && <div key={stamp} className="show-in px-6"><AlertTriangle size={64} className="mx-auto text-brass" /><h2 className="mt-4 text-[38px] font-black">{dn(res.emp)}</h2><p className="mt-2 text-show-fg/65 text-[18px]">{L('سُجّلت حركتك قبل ثوانٍ — انتظر 10 ثوانٍ قبل التمرير مجدداً.', 'You were just recorded — wait 10 seconds before tapping again.')}</p></div>}

        {res?.kind === 'unknown' && <div key={stamp} className="show-in px-6 w-full max-w-[560px]">
          <XCircle size={64} className="mx-auto text-danger" /><h2 className="mt-4 text-[36px] font-black">{L('بطاقة غير مسجّلة', 'Unregistered card')}</h2>
          <p className="mt-2 text-show-fg/65">{L('هذه البطاقة غير مرتبطة بأي موظف. اربطها الآن لتعمل من المرة القادمة.', 'This card is not linked to anyone. Link it now and it will work next time.')}</p>
          <div className="mt-3 ltr num text-show-fg/50 text-[13px]">UID {res.card.hex}</div>
          {!enroll ? <button onClick={() => setEnroll(true)} className="mt-6 h-11 px-6 rounded-xl bg-brand text-on-brand font-bold inline-flex items-center gap-2"><Link2 size={17} />{L('ربط البطاقة بموظف', 'Link card to an employee')}</button>
            : <div className="mt-5 text-start rounded-2xl border border-show-fg/15 bg-show p-3">
              <div className="relative"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-show-fg/40" /><input autoFocus value={eq} onChange={e => setEq(e.target.value)} placeholder={L('ابحث بالاسم أو الرقم الوظيفي', 'Search name or ID')} className="w-full h-10 rounded-lg bg-show-fg/5 border border-show-fg/15 ps-9 pe-3 outline-none focus:border-brand" /></div>
              <ul className="mt-2 max-h-56 overflow-y-auto">{emps.filter(e => !eq || e.name.includes(eq) || e.nameEn.toLowerCase().includes(eq.toLowerCase()) || e.code.includes(eq.toUpperCase())).slice(0, 30).map(e => <li key={e.id}><button onClick={() => { bindCard(e.id, res.card.norm); setEnroll(false); setRes(null); setTimeout(() => handle(res.card.norm), 50) }} className="w-full flex items-center justify-between gap-3 rounded-lg px-3 py-2 hover:bg-show-fg/10 text-start"><span className="font-semibold">{dn(e)}</span><span className="text-[12px] text-show-fg/50 ltr">{e.code}</span></button></li>)}</ul>
            </div>}
        </div>}

        {/* diagnostics */}
        <div className="absolute bottom-4 inset-x-4 flex justify-center">
          <button onClick={() => setDiag(!diag)} className="flex items-center gap-1.5 text-[12px] text-show-fg/45 hover:text-show-fg/80"><Cpu size={13} />{L('بيانات القراءة', 'Read data')}<ChevronDown size={13} className={diag ? 'rotate-180' : ''} /></button>
        </div>
        {diag && <div className="absolute top-4 end-4 w-[250px] rounded-xl border border-show-fg/15 bg-show/95 p-3 text-start ltr font-mono text-[11.5px] fadeup">
          <div className="text-show-fg/55 mb-1.5 font-sans">{L('اختبار القارئ: كل إشارة تصل من لوحة المفاتيح/القارئ', 'Reader test: every keyboard/reader signal')}</div>
          {keylog.length === 0 ? <div className="text-show-fg/40">— {L('لم تصل أي إشارة', 'no signal yet')}</div> : keylog.map((x, i) => <div key={i} className="flex justify-between"><span>{x.k}</span><span className="text-show-fg/50">{x.c}</span><span className="text-brand">+{x.g}ms</span></div>)}
        </div>}
        {diag && res && 'card' in res && <div className="absolute bottom-12 inset-x-6 mx-auto max-w-[620px] rounded-xl border border-show-fg/15 bg-show/95 p-4 text-start ltr fadeup">
          <div className="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-2 text-[12.5px] font-mono">
            {[['Raw input', res.card.raw], ['Format', res.card.format], ['Length', `${res.card.bytes} bytes / ${res.card.bits} bit`], ['UID (hex)', res.card.hex], ['UID reversed', res.card.reversed], ['Matched', res.kind === 'unknown' ? '—' : res.emp.code]].map(([k, v]) => <div key={k}><div className="text-show-fg/45 text-[11px]">{k}</div><div className="truncate">{v}</div></div>)}
          </div></div>}
      </section>

      {/* side feed */}
      <aside className="flex flex-col rounded-3xl border border-show-fg/10 bg-show-fg/[.03] min-h-0">
        <div className="px-5 pt-5 pb-3 flex items-center justify-between"><span className="font-display font-bold">{L('حركات اليوم', 'Today’s activity')}</span><span className="text-[12px] text-show-fg/50">{fmtDate(todayISO(), lang)}</span></div>
        <ul className="flex-1 overflow-y-auto px-3 space-y-1.5">{scans.length === 0 && <li className="px-2 py-6 text-center text-[13px] text-show-fg/45">{L('لا توجد حركات من هذا الجهاز بعد', 'No taps on this terminal yet')}</li>}
          {scans.map((x, i) => { const e = emps.find(y => y.id === x.empId); if (!e) return null
            return <li key={x.id} className={`flex items-center gap-3 rounded-xl px-3 py-2.5 ${i === 0 ? 'bg-show-fg/[.07] fadeup' : ''}`}><span className="size-9 rounded-full grid place-items-center bg-brand/15 text-brand"><UserRound size={18} /></span>
              <span className="flex-1 min-w-0"><span className="block text-[14px] font-semibold truncate">{dn(e)}</span><span className="block text-[11.5px] text-show-fg/50">{x.type === 'in' ? L('دخول', 'In') : L('خروج', 'Out')}</span></span><span className="ltr num text-[13px] text-show-fg/70">{x.ts.slice(11, 16)}</span></li> })}</ul>
        <form className="px-3 pt-3 border-t border-show-fg/10 flex gap-2" onSubmit={e => { e.preventDefault(); if (manual.trim()) { handle(manual.trim()); setManual('') } }}>
          <input data-manual="1" value={manual} onChange={e => setManual(e.target.value)} placeholder={L('رقم البطاقة يدوياً', 'Card number (manual)')} className="flex-1 min-w-0 h-9 rounded-lg bg-show-fg/5 border border-show-fg/15 px-3 text-[13px] outline-none focus:border-brand ltr" />
          <button className="h-9 px-3 rounded-lg bg-brand text-on-brand text-[12.5px] font-bold">{L('تسجيل', 'Submit')}</button>
        </form>
        <div className="p-3">
          <button onClick={() => setSim(!sim)} className="w-full flex items-center justify-between text-[12.5px] text-show-fg/55 hover:text-show-fg px-2 h-8">{L('محاكاة بدون قارئ', 'Simulate without a reader')}<ChevronDown size={14} className={sim ? 'rotate-180' : ''} /></button>
          {sim && <div className="mt-2"><input value={q} onChange={e => setQ(e.target.value)} placeholder={L('بحث', 'Search')} className="w-full h-9 rounded-lg bg-show-fg/5 border border-show-fg/15 px-3 text-[13px] outline-none" />
            <ul className="mt-1.5 max-h-44 overflow-y-auto">{emps.filter(e => !q || e.name.includes(q) || e.nameEn.toLowerCase().includes(q.toLowerCase())).slice(0, 20).map(e => <li key={e.id}><button onClick={() => e.nfcUid ? handle(e.nfcUid) : null} className="w-full flex justify-between px-2 py-1.5 rounded-md hover:bg-show-fg/10 text-[13px]"><span>{dn(e)}</span><span className="ltr text-show-fg/40 text-[11px]">{e.nfcUid ? normUid(e.nfcUid).slice(0, 8) : L('بلا بطاقة', 'no card')}</span></button></li>)}</ul></div>}
        </div>
      </aside>
    </main>
  </div>
}

export function LinkCardButton({ emp }: { emp: Employee }) {
  const L = useL(); const [open, setOpen] = useState(false); const [got, setGot] = useState<string | null>(null)
  const [sig, setSig] = useState(''); const [done, setDone] = useState(false)
  useCardReader(raw => { if (open) setGot(normUid(raw)) }, open, b => setSig(b))
  return <>
    <button onClick={() => { setGot(null); setSig(''); setDone(false); setOpen(true) }} className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-brand hover:underline"><Nfc size={14} />{emp.nfcUid ? L('تغيير البطاقة', 'Change card') : L('ربط بطاقة', 'Link card')}</button>
    {open && <div className="fixed inset-0 z-[70] bg-black/60 grid place-items-center p-4" onMouseDown={() => setOpen(false)}>
      <div className="w-full max-w-[420px] rounded-2xl border border-line bg-surface p-6 text-center fadeup" onMouseDown={e => e.stopPropagation()}>
        <div className="relative mx-auto size-28 grid place-items-center"><span className="absolute inset-0 rounded-full border-2 border-brand/60" /><span className="absolute inset-0 rounded-full scan-pulse" style={{ animationIterationCount: 'infinite' }} /><Nfc size={44} className="text-brand" /></div>
        {done ? <><CheckCircle2 size={40} className="mx-auto mt-4 text-brand" /><div className="mt-2 font-display font-bold text-[18px]">{L('تم ربط البطاقة بنجاح', 'Card linked')}</div><div className="mt-1 ltr num">{emp.nfcUid}</div><div className="text-[13px] text-ink-3 mt-1">{L('مرّرها الآن على جهاز الحضور لتسجيل الحركة.', 'Tap it on the attendance terminal to record.')}</div><button onClick={() => setOpen(false)} className="mt-5 h-10 px-6 rounded-lg bg-brand text-on-brand font-bold">{L('تم', 'Done')}</button></>
          : !got ? <><div className="mt-5 font-display font-bold text-[18px]">{L('مرّر البطاقة الآن', 'Tap the card now')}</div><div className="text-[13px] text-ink-3 mt-1">{L(`ستُربط البطاقة بـ ${emp.name}`, `The card will be linked to ${emp.nameEn}`)}</div>
            <div className={`mt-3 text-[12px] ltr font-mono ${sig ? 'text-brand' : 'text-ink-3'}`}>{sig ? `… ${sig}` : L('بانتظار إشارة من القارئ', 'Waiting for reader')}</div>
            <form className="mt-4 flex gap-2" onSubmit={e => { e.preventDefault(); const v = (e.currentTarget.elements.namedItem('m') as HTMLInputElement).value; if (v.trim()) setGot(normUid(v)) }}><input name="m" data-manual="1" placeholder={L('أو أدخل رقم البطاقة', 'or type the card number')} className="field ltr flex-1" /><button className="h-[38px] px-3 rounded-lg border border-line text-[13px]">{L('إدخال', 'Enter')}</button></form></>
          : <><div className="mt-5 font-display font-bold text-[18px]">{L('تمت قراءة البطاقة', 'Card read')}</div><div className="mt-2 ltr num text-[15px] font-semibold">{got}</div>
            <div className="mt-5 flex gap-2 justify-center"><button onClick={() => setGot(null)} className="h-10 px-4 rounded-lg border border-line">{L('إعادة', 'Retry')}</button><button onClick={() => { bindCard(emp.id, got); setDone(true) }} className="h-10 px-5 rounded-lg bg-brand text-on-brand font-bold">{L('ربط البطاقة', 'Link card')}</button></div></>}
      </div></div>}
  </>
}
