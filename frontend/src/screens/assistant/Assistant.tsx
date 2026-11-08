import { Fragment, useEffect, useRef, useState, type ReactNode } from 'react'
import { ArrowUpLeft, BookOpen, CheckCircle2, ChevronDown, FileText, Loader2, Send, ShieldCheck, Sparkles, Trash2, X } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { navigate } from '../../lib/router'
import { ApiError } from '../../lib/api'
import { queryClient } from '../../lib/session'
import { ask, closeAssistant, openAssistant, takeDraft, useActionDecision, useAssistant, useAssistantOpen, useClearAssistant, type Action, type AssistantState, type Card, type Msg, type Source, type Tone } from '../../lib/assistant'
import { Btn, Drawer, Field, Seg, toast } from '../../ui/kit'

const tones: Record<Tone, string> = { brand: 'text-brand', brass: 'text-brass', info: 'text-info', danger: 'text-danger', neutral: 'text-ink' }
const badge: Record<Tone, string> = { brand: 'bg-brand-soft text-brand', brass: 'bg-brass-soft text-brass', info: 'bg-info-soft text-info', danger: 'bg-danger-soft text-danger', neutral: 'bg-sub text-ink-2' }

/** زر «المساعد» في رأس الصفحة (البوابتان) */
export function AssistantButton() {
  const L = useL()
  return <button onClick={() => openAssistant()} className="h-9 px-2.5 inline-flex items-center gap-1.5 rounded-lg border border-brand/40 bg-brand-soft/60 text-brand hover:bg-brand-soft text-[13px] font-semibold" aria-label={L('المساعد', 'Assistant')}>
    <Sparkles size={16} /><span className="hidden md:inline">{L('المساعد', 'Assistant')}</span></button>
}

/** لوحة المحادثة — تُركَّب مرة واحدة في كل واجهة */
export function AssistantPanel() {
  const open = useAssistantOpen()
  return <Drawer open={open} onClose={closeAssistant} width={500}>{open && <Chat />}</Drawer>
}

function Chat() {
  const L = useL()
  const q = useAssistant(true)
  const clear = useClearAssistant()
  const [items, setItems] = useState<Msg[] | null>(null)
  const [stream, setStream] = useState<Record<number, string>>({})
  const [busy, setBusy] = useState(false)
  const [text, setText] = useState('')
  const end = useRef<HTMLDivElement>(null)
  const input = useRef<HTMLTextAreaElement>(null)
  useEffect(() => { if (q.data && items === null) setItems(q.data.history) }, [q.data, items])
  useEffect(() => { end.current?.scrollIntoView({ block: 'end' }) }, [items, stream, busy])
  useEffect(() => { const d = takeDraft(); if (d && q.data) send(d); else input.current?.focus() }, [q.data])   // eslint-disable-line react-hooks/exhaustive-deps

  const replace = (m: Msg) => setItems(s => (s ?? []).map(x => x.id === m.id ? m : x))
  async function send(t: string) {
    const v = t.trim()
    if (!v || busy) return
    setText(''); setBusy(true)
    const temp = -Date.now()
    setItems(s => [...(s ?? []), { id: temp, role: 'user', intent: null, text: v, at: new Date().toISOString(), cards: [], sources: [], suggestions: [], action: null, summary: null }])
    let rid = 0
    try {
      await ask(v, e => {
        if (e.t === 'reply') { rid = e.message.id; setItems(s => [...(s ?? []), e.message]) }
        else if (e.t === 'delta') setStream(s => ({ ...s, [rid]: (s[rid] ?? '') + e.s }))
        else if (e.t === 'done') { replace(e.message); setStream(s => { const n = { ...s }; delete n[e.message.id]; return n }) }
        else if (e.t === 'error') toast(e.message)
      })
      queryClient.setQueryData<AssistantState>(['assistant'], d => d && { ...d, limit: { ...d.limit, used: d.limit.used + 1 } })
    } catch (e) {
      setItems(s => (s ?? []).filter(x => x.id !== temp)); setText(v)
      toast(e instanceof ApiError ? e.message : String(e))
    } finally { setBusy(false); input.current?.focus() }
  }

  if (!q.data || items === null) return <div className="flex-1 grid place-items-center"><Loader2 className="animate-spin text-brand" /></div>
  const d = q.data
  const last = items[items.length - 1]
  return <>
    <header className="flex items-center gap-3 px-4 h-16 border-b border-line shrink-0">
      <span className="size-9 grid place-items-center rounded-xl bg-brand-soft text-brand"><Sparkles size={18} /></span>
      <div className="flex-1 min-w-0"><div className="font-display font-bold text-[16px]">{L('المساعد', 'Assistant')}</div>
        <div className="text-[12px] text-ink-3 truncate">{L('إجاباتي من بياناتك وسياسات الشركة — أي طلب يُرسل بعد تأكيدك فقط', 'Answers from your data and company policies — nothing is sent without your confirmation')}</div></div>
      {items.length > 0 && <Btn size="sm" kind="ghost" icon={<Trash2 size={15} />} onClick={() => { if (confirm(L('مسح المحادثة؟', 'Clear the conversation?'))) clear.mutate(undefined, { onSuccess: () => setItems([]) }) }} aria-label={L('مسح المحادثة', 'Clear the conversation')} />}
      <Btn size="sm" kind="ghost" icon={<X size={17} />} onClick={closeAssistant} aria-label={L('إغلاق', 'Close')} />
    </header>
    <div className="flex-1 overflow-y-auto px-4 py-4 space-y-4" aria-live="polite">
      {!d.enabled ? <p className="text-center text-ink-3 text-[14px] py-10">{L('المساعد غير مفعّل في شركتك.', 'The assistant is turned off for your company.')}</p>
        : items.length === 0 && <Welcome suggestions={d.suggestions} onPick={send} />}
      {items.map(m => m.role === 'user'
        ? <div key={m.id} className="flex justify-end"><div className="max-w-[85%] rounded-2xl rounded-se-md bg-brand text-on-brand px-3.5 py-2 text-[14px] whitespace-pre-wrap break-words">{m.text}</div></div>
        : <Answer key={m.id} m={m} live={stream[m.id]} onAsk={send} onUpdate={replace} isLast={m === last} />)}
      {busy && last?.role === 'user' && <div className="flex items-center gap-2 text-ink-3 text-[13px]"><Dots />{L('المساعد يجهّز الإجابة…', 'Preparing the answer…')}</div>}
      <div ref={end} />
    </div>
    {d.enabled && <footer className="border-t border-line p-3 shrink-0">
      <div className="flex items-end gap-2">
        <textarea ref={input} rows={1} value={text} maxLength={600} onChange={e => setText(e.target.value)} dir="auto"
          onKeyDown={e => { if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) { e.preventDefault(); send(text) } }}
          placeholder={L('اكتب سؤالك… (Enter للإرسال)', 'Type your question… (Enter to send)')} className="field !h-auto min-h-[44px] max-h-32 py-2.5 leading-6 resize-none flex-1" aria-label={L('سؤالك', 'Your question')} />
        <Btn kind="primary" className="h-11" icon={busy ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} className="rtl:-scale-x-100" />} disabled={busy || !text.trim()} onClick={() => send(text)} aria-label={L('إرسال', 'Send')} />
      </div>
      <div className="mt-1.5 text-[11.5px] text-ink-3 flex justify-between gap-2"><span>{L('Shift + Enter لسطر جديد', 'Shift + Enter for a new line')}</span><span className="num">{d.limit.used}/{d.limit.max} {L('اليوم', 'today')}</span></div>
    </footer>}
  </>
}

function Dots() { return <span className="inline-flex gap-1" aria-hidden>{[0, 1, 2].map(i => <span key={i} className="size-1.5 rounded-full bg-brand animate-pulse" style={{ animationDelay: `${i * 160}ms` }} />)}</span> }

function Welcome({ suggestions, onPick }: { suggestions: string[]; onPick: (q: string) => void }) {
  const L = useL()
  return <div className="text-center pt-6 pb-2">
    <span className="size-14 mx-auto grid place-items-center rounded-2xl bg-brand-soft text-brand"><Sparkles size={26} /></span>
    <h2 className="font-display font-bold text-[18px] mt-3">{L('كيف يمكنني مساعدتك؟', 'How can I help?')}</h2>
    <p className="text-[13px] text-ink-3 mt-1 max-w-sm mx-auto">{L('اسأل عن رصيدك وحضورك وطلباتك وسياسات الشركة، أو اطلب إجازة أو شهادة — أجهّزها لك لتراجعها وتؤكدها.', 'Ask about your balance, attendance, requests and company policies, or ask for leave or a certificate — I prepare it for you to review and confirm.')}</p>
    <div className="mt-4 flex flex-wrap justify-center gap-2">{suggestions.map(s => <button key={s} onClick={() => onPick(s)} className="rounded-full border border-line bg-surface hover:border-brand/50 hover:bg-brand-soft/40 px-3 py-1.5 text-[13px] text-start">{s}</button>)}</div>
  </div>
}

function go(to: string) { navigate(to); if (window.innerWidth < 768) closeAssistant() }

/** رقم المصدر [1] داخل النص ← شارة صغيرة */
function withCites(text: string): ReactNode[] {
  return text.split(/(\[\d\])/g).map((p, i) => /^\[\d\]$/.test(p) ? <sup key={i} className="mx-0.5 px-1 rounded bg-info-soft text-info text-[10px] font-bold num">{p.slice(1, -1)}</sup> : <Fragment key={i}>{p}</Fragment>)
}

function Answer({ m, live, onAsk, onUpdate, isLast }: { m: Msg; live?: string; onAsk: (q: string) => void; onUpdate: (m: Msg) => void; isLast: boolean }) {
  const L = useL()
  const writing = m.summary === 'writing'
  return <div className="flex gap-2.5">
    <span className="size-8 shrink-0 grid place-items-center rounded-lg bg-brand-soft text-brand mt-0.5"><Sparkles size={15} /></span>
    <div className="min-w-0 flex-1 space-y-2.5">
      {writing ? <div className="rounded-2xl rounded-ss-md bg-sub px-3.5 py-2.5 text-[14px] leading-7">
          <div className="text-[11.5px] font-semibold text-brand mb-0.5">{L('الخلاصة', 'Summary')}</div>
          {live ? <span className="whitespace-pre-wrap">{withCites(live)}</span> : <span className="inline-flex items-center gap-2 text-ink-3"><Dots />{L('يكتب المساعد الخلاصة من المصادر أدناه…', 'Writing a summary from the sources below…')}</span>}
        </div>
        : <div className="rounded-2xl rounded-ss-md bg-sub px-3.5 py-2.5 text-[14px] leading-7 whitespace-pre-wrap break-words">
          {m.summary === 'done' && <div className="text-[11.5px] font-semibold text-brand mb-0.5">{L('الخلاصة', 'Summary')}</div>}
          {withCites(m.text)}</div>}
      {m.cards.map((c, i) => <CardView key={i} c={c} />)}
      {m.action && <ActionCard id={m.id} a={m.action} onUpdate={onUpdate} />}
      {m.sources.length > 0 && <Sources list={m.sources} />}
      {isLast && m.suggestions.length > 0 && <div className="flex flex-wrap gap-1.5">{m.suggestions.map(s => <button key={s} onClick={() => onAsk(s)} className="rounded-full border border-line bg-surface hover:border-brand/50 px-2.5 py-1 text-[12.5px]">{s}</button>)}</div>}
    </div>
  </div>
}

function CardView({ c }: { c: Card }) {
  if (c.type === 'stats') return <div className="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(100px,1fr))]">{c.items.map((x, i) => <div key={i} className="rounded-xl border border-line bg-surface px-3 py-2">
    <div className="text-[11.5px] text-ink-3 truncate">{x.label}</div><div className={`font-display font-bold text-[20px] num leading-tight ${tones[x.tone ?? 'neutral']}`}>{x.value}</div>{x.sub && <div className="text-[11px] text-ink-3 num">{x.sub}</div>}</div>)}</div>
  if (c.type === 'list') return <div className="rounded-xl border border-line bg-surface overflow-hidden">
    {c.title && <div className="px-3 py-1.5 text-[12px] font-semibold text-ink-3 border-b border-line-2">{c.title}</div>}
    <ul className="divide-y divide-line-2">{c.items.map((x, i) => <li key={i} className="px-3 py-2 flex items-center gap-2">
      <div className="min-w-0 flex-1"><div className="text-[13.5px] font-semibold truncate">{x.title}</div>{x.sub && <div className="text-[12px] text-ink-3 break-words">{x.sub}</div>}</div>
      {x.badge && <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ${badge[x.badge.tone]}`}>{x.badge.label}</span>}</li>)}</ul></div>
  return <button onClick={() => go(c.to)} className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-brand hover:underline"><ArrowUpLeft size={14} className="ltr:-scale-x-100" />{c.label}</button>
}

function Sources({ list }: { list: Source[] }) {
  const L = useL(); const lang = useLang()
  const [open, setOpen] = useState<number | null>(null)
  return <div className="space-y-1.5">
    <div className="text-[11.5px] font-semibold text-ink-3">{L('المصادر', 'Sources')}</div>
    {list.map((s, i) => <div key={i} className="rounded-xl border border-line bg-surface">
      <button className="w-full flex items-center gap-2 px-3 py-2 text-start" onClick={() => setOpen(open === i ? null : i)} aria-expanded={open === i}>
        <span className="size-5 shrink-0 grid place-items-center rounded bg-info-soft text-info text-[11px] font-bold num">{i + 1}</span>
        {s.kind === 'policy' ? <FileText size={14} className="text-ink-3 shrink-0" /> : <BookOpen size={14} className="text-ink-3 shrink-0" />}
        <span className="min-w-0 flex-1 text-[12.5px]"><span className="font-semibold">{bi(s.title, lang)}</span>{s.sub && <span className="text-ink-3"> — {s.sub}</span>}
          <span className="block text-[11px] text-ink-3">{s.kind === 'policy' ? L('وثيقة من سياسات الشركة', 'Company policy document') : L('دليل استخدام النظام', 'System user guide')}</span></span>
        <ChevronDown size={15} className={`shrink-0 text-ink-3 transition ${open === i ? 'rotate-180' : ''}`} /></button>
      {open === i && <div className="px-3 pb-2.5 text-[13px] leading-6 text-ink-2">{s.excerpt}
        {s.to && <div className="mt-1.5"><button onClick={() => go(s.to!)} className="text-brand font-semibold text-[12.5px] hover:underline">{s.kind === 'policy' ? L('فتح الفقرة في مكتبة السياسات', 'Open in the policy library') : L('فتح الصفحة', 'Open the page')}</button></div>}</div>}
    </div>)}
  </div>
}

// ───────────── بطاقة الإجراء: معاينة، تعديل، إرسال بخطوتين ─────────────

type Opt = { key?: string; id?: number; date?: string; label: string; unit?: string; color?: string }

function ActionCard({ id, a, onUpdate }: { id: number; a: Action; onUpdate: (m: Msg) => void }) {
  const L = useL()
  const dec = useActionDecision()
  const [f, setF] = useState<Record<string, unknown>>(a.fields)
  const [dirty, setDirty] = useState(false)
  const [ask2, setAsk2] = useState(false)
  const timer = useRef<number>(0)
  useEffect(() => { if (!dirty) setF(a.fields) }, [a.fields, dirty])
  const set = (k: string, v: unknown) => {
    const n = { ...f, [k]: v }; setF(n); setDirty(true); setAsk2(false)
    window.clearTimeout(timer.current)
    timer.current = window.setTimeout(() => dec.mutate({ id, decision: 'preview', fields: n }, { onSuccess: m => { setDirty(false); onUpdate(m) }, onError: e => toast((e as Error).message) }), 450)
  }
  const run = (decision: 'confirm' | 'cancel') => dec.mutate({ id, decision, fields: f, digest: a.digest }, {
    onSuccess: m => { setAsk2(false); onUpdate(m); if (m.action?.status === 'done') queryClient.invalidateQueries({ refetchType: 'active' }) }, onError: e => toast((e as Error).message) })
  const title = { leave: L('طلب إجازة', 'Leave request'), justify: L('تبرير تأخير أو غياب', 'Justification'), certificate: L('طلب شهادة', 'Certificate request'), praise: L('شكر زميل', 'Praise a colleague') }[a.kind]
  const sendLabel = a.kind === 'praise' ? L('نشر الشكر', 'Post praise') : L('إرسال الطلب', 'Send request')

  if (a.status !== 'pending') return <div className={`rounded-xl border px-3 py-2.5 text-[13.5px] ${a.status === 'done' ? 'border-brand/40 bg-brand-soft/50' : 'border-line bg-surface text-ink-3'}`}>
    {a.status === 'done' && a.result ? <><div className="flex items-center gap-1.5 font-semibold text-brand"><CheckCircle2 size={16} />{L('تم', 'Done')}</div><div className="mt-1">{a.result.text}</div>
      <button onClick={() => go(a.result!.to)} className="mt-1.5 text-brand font-semibold text-[12.5px] hover:underline">{L('عرض', 'View')}</button></>
      : a.status === 'cancelled' ? <>{title} — {L('أُلغي ولم يُرسل.', 'cancelled, nothing was sent.')}</> : <>{title} — {L('انتهت صلاحية البطاقة. اطلب الإجراء من جديد.', 'the card expired. Ask again.')}</>}
  </div>

  const err = (k: string) => a.errors[k]
  const info = a.info as Record<string, unknown>
  const opts = (k: string) => (info[k] as Opt[] | undefined) ?? []
  const general = Object.entries(a.errors).filter(([k]) => !['type', 'from', 'to', 'hours', 'substitute_id', 'reason', 'date', 'recipients', 'value', 'message', 'kind'].includes(k) && !k.startsWith('addressee.')).map(([, v]) => v)
  const fieldsView = (() => {
    switch (a.kind) {
      case 'leave': {
        const hour = info.unit === 'hour'
        return <>
          <Field label={L('نوع الإجازة', 'Leave type')} error={err('type')}><select className="field" value={String(f.type ?? '')} onChange={e => set('type', e.target.value)}>{opts('types').map(o => <option key={o.key} value={o.key}>{o.label}</option>)}</select></Field>
          <div className="grid grid-cols-2 gap-2">
            <Field label={hour ? L('اليوم', 'Day') : L('من', 'From')} error={err('from')}><input type="date" className="field num" value={String(f.from ?? '')} onChange={e => set('from', e.target.value)} /></Field>
            {hour ? <Field label={L('عدد الساعات', 'Hours')} error={err('hours')}><input type="number" min={1} max={8} className="field num" value={String(f.hours ?? '')} onChange={e => set('hours', Number(e.target.value) || null)} /></Field>
              : <Field label={L('إلى', 'To')} error={err('to')}><input type="date" className="field num" value={String(f.to ?? '')} onChange={e => set('to', e.target.value)} /></Field>}
          </div>
          {!hour && <Field label={L('الشخص البديل', 'Substitute')} req={!!info.substitute_required} error={err('substitute_id')}><select className="field" value={String(f.substitute_id ?? '')} onChange={e => set('substitute_id', e.target.value ? Number(e.target.value) : null)}>
            <option value="">{L('اختر الزميل البديل', 'Choose a substitute')}</option>{opts('substitutes').map(o => <option key={o.id} value={o.id}>{o.label}</option>)}</select></Field>}
          <Field label={L('السبب', 'Reason')} error={err('reason')}><textarea className="field !h-auto py-2" rows={2} maxLength={500} value={String(f.reason ?? '')} onChange={e => set('reason', e.target.value)} /></Field>
          {!!info.days && <div className="text-[12.5px] text-ink-2">{hour ? L('مغادرة ساعية', 'Hourly leave') : <>{L('المدة المحسوبة', 'Counted')}: <b className="num">{String(info.days)}</b> {L('يوم عمل', 'working day(s)')}</>}{(info.levels as string[] | undefined)?.length ? <> · {L('المسار', 'Path')}: {(info.levels as string[]).join(' ← ')}</> : null}</div>}
        </>
      }
      case 'justify': return <>
        <Field label={L('اليوم', 'Day')} error={err('date')}><select className="field" value={String(f.date ?? '')} onChange={e => set('date', e.target.value)}>{opts('issues').map(o => <option key={o.date} value={o.date}>{o.label}</option>)}</select></Field>
        <Field label={L('السبب', 'Reason')} req error={err('reason')}><textarea className="field !h-auto py-2" rows={2} maxLength={500} value={String(f.reason ?? '')} onChange={e => set('reason', e.target.value)} /></Field>
      </>
      case 'certificate': {
        const ad = (f.addressee ?? {}) as Record<string, string>
        const salary = f.kind === 'salary_cert'
        const sa = (k: string, v: string) => set('addressee', { ...ad, [k]: v })
        return <>
          <Field label={L('نوع الشهادة', 'Certificate')} div><Seg value={String(f.kind)} onChange={v => set('kind', v)} options={opts('kinds').map(o => ({ v: o.key!, label: o.label }))} /></Field>
          <div className="grid sm:grid-cols-2 gap-2">
            <Field label={L('الجهة الموجهة إليها', 'Addressee')} req={salary} error={err('addressee.name')}><input className="field" value={ad.name ?? ''} onChange={e => sa('name', e.target.value)} /></Field>
            <Field label={L('رقم السجل التجاري', 'Commercial reg. no.')} req={salary} error={err('addressee.cr_no')}><input className="field ltr text-start" value={ad.cr_no ?? ''} onChange={e => sa('cr_no', e.target.value)} /></Field>
            <Field label={L('البريد الرسمي للجهة', 'Official email')} req={salary} error={err('addressee.email')}><input type="email" className="field ltr text-start" value={ad.email ?? ''} onChange={e => sa('email', e.target.value)} /></Field>
            <Field label={L('هاتف الجهة', 'Phone')} req={salary} error={err('addressee.phone')}><input className="field ltr text-start" value={ad.phone ?? ''} onChange={e => sa('phone', e.target.value)} /></Field>
          </div>
          {salary && <label className="flex items-start gap-2 text-[13px]"><input type="checkbox" className="mt-1 accent-[var(--brand)]" checked={!!f.notify_consent} onChange={e => set('notify_consent', e.target.checked)} />{L('أوافق على إبلاغ هذه الجهة عند انتهاء خدمتي', 'I agree that this organisation is notified when my service ends')}</label>}
        </>
      }
      case 'praise': {
        const people = opts('people'); const ids = (f.recipients as number[] | undefined) ?? []
        return <>
          <Field label={L('الزملاء', 'Colleagues')} req error={err('recipients')} div>
            {people.length ? <div className="flex flex-wrap gap-1.5">{people.map(p => <span key={p.id} className="inline-flex items-center gap-1 rounded-full bg-brand-soft text-brand px-2.5 py-0.5 text-[12.5px] font-semibold">{p.label}
              <button onClick={() => set('recipients', ids.filter(x => x !== p.id))} aria-label={L('إزالة', 'Remove')}><X size={12} /></button></span>)}</div>
              : <p className="text-[12.5px] text-ink-3">{L('لم أتعرف على اسم الزميل. اختره من نافذة «شكر زميل».', 'I could not recognise the name. Pick the colleague from the “Praise” window.')} <button className="text-brand font-semibold" onClick={() => go(String(window.location.pathname).startsWith('/hr') ? '/hr/space?praise=1' : '/me/space?praise=1')}>{L('فتح', 'Open')}</button></p>}
          </Field>
          <Field label={L('القيمة', 'Value')} req error={err('value')} div><div className="flex flex-wrap gap-1.5">{opts('values').map(v => <button key={v.key} onClick={() => set('value', v.key)} aria-pressed={f.value === v.key}
            className={`h-8 px-2.5 rounded-full border text-[12.5px] ${f.value === v.key ? 'border-brand bg-brand-soft text-brand font-semibold' : 'border-line'}`}>{v.label}</button>)}</div></Field>
          <Field label={L('رسالة الشكر', 'Message')} req error={err('message')}><textarea className="field !h-auto py-2" rows={2} maxLength={1000} value={String(f.message ?? '')} onChange={e => set('message', e.target.value)} /></Field>
          {Number(info.points) > 0 && <div className="text-[12px] text-ink-3">{L(`تُضاف ${info.points} نقاط لكل زميل في متجر المكافآت.`, `${info.points} points per colleague are added to the rewards store.`)}</div>}
        </>
      }
    }
  })()
  const blocked = Object.keys(a.errors).length > 0 || dirty || dec.isPending
  return <div className="rounded-xl border border-brand/40 bg-surface p-3 space-y-3">
    <div className="flex items-center gap-2 text-[13.5px] font-semibold"><ShieldCheck size={16} className="text-brand" />{title}<span className="ms-auto text-[11px] font-normal text-ink-3">{L('لن يُرسل شيء قبل تأكيدك', 'Nothing is sent before you confirm')}</span></div>
    {fieldsView}
    {a.warnings.length > 0 && <ul className="rounded-lg bg-brass-soft/60 text-[12.5px] px-3 py-2 space-y-1">{a.warnings.map((w, i) => <li key={i}>• {w}</li>)}</ul>}
    {general.length > 0 && <ul className="rounded-lg bg-danger-soft text-danger text-[12.5px] px-3 py-2 space-y-1">{general.map((w, i) => <li key={i}>• {w}</li>)}</ul>}
    {!ask2 ? <div className="flex gap-2 justify-end">
      <Btn size="sm" onClick={() => run('cancel')} disabled={dec.isPending}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn size="sm" kind="primary" disabled={blocked} onClick={() => setAsk2(true)}>{dirty || dec.isPending ? <Loader2 size={14} className="animate-spin" /> : sendLabel}</Btn></div>
      : <div className="rounded-lg border border-brand/40 bg-brand-soft/60 p-2.5" role="group" aria-label={L('تأكيد الإرسال', 'Confirm')}>
        <div className="text-[13px] font-semibold">{a.kind === 'praise' ? L('تأكيد نشر الشكر في الساحة؟', 'Post this praise in the Space?') : L('تأكيد إرسال الطلب؟ سيبدأ مسار الاعتماد فوراً.', 'Send this request? The approval path starts immediately.')}</div>
        <div className="flex gap-2 mt-2 justify-end"><Btn size="sm" onClick={() => setAsk2(false)} disabled={dec.isPending}>{L('تراجع', 'Back')}</Btn>
          <Btn size="sm" kind="primary" autoFocus disabled={dec.isPending} onClick={() => run('confirm')}>{dec.isPending ? L('جارٍ الإرسال…', 'Sending…') : L('تأكيد', 'Confirm')}</Btn></div></div>}
  </div>
}

