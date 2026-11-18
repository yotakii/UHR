import { useState } from 'react'
import { AlertTriangle, Ban, CalendarDays, CheckCheck, Copy, Lock, Plus, Search, Star, UserPlus, X } from 'lucide-react'
import { useL, useLang, bi, fmtDate, money } from '../../lib/store'
import { setQuery, useRoute } from '../../lib/router'
import { ATT, DELIVERY, E_STATUS, PATH, S_STATUS, useCalendar, useSession, useSessions, useTdAct, type Can, type EnrollmentRow, type Lookups, type SessionFull } from '../../lib/training'
import { Badge, Btn, Drawer, Empty, Field, KV, Modal, Panel, Tabs, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'
import { EmpSelect, PathBadge, Spin, StatusBadge, errOf, fail } from './parts'
import { PrintBtn, QuotesPanel, SessionMore, SurveySummary } from './TdMore'

export function SessionsTab({ lk, can }: { lk: Lookups; can: Can }) {
  const L = useL(); const lang = useLang()
  const q = useRoute().query
  const f = { status: q.status ?? 'open', q: q.q }
  const list = useSessions(f)
  const [add, setAdd] = useState(false)
  return <>
    <div className="flex flex-wrap gap-2 mb-3">
      <div className="relative w-full sm:w-56"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" /><input className="field ps-9" placeholder={L('عنوان أو رقم…', 'Title or ref…')} defaultValue={q.q ?? ''} onKeyDown={e => { if (e.key === 'Enter') setQuery({ q: (e.target as HTMLInputElement).value || undefined }) }} /></div>
      <select className="field !w-full sm:!w-44" value={f.status} onChange={e => setQuery({ status: e.target.value })} aria-label={L('الحالة', 'Status')}><option value="open">{L('الجارية والقادمة', 'Open & upcoming')}</option><option value="">{L('الكل', 'All')}</option>
        {['planned', 'enrolling', 'confirmed', 'in_progress', 'postponed', 'completed', 'closed', 'evaluated', 'cancelled'].map(s => <option key={s} value={s}>{L(S_STATUS[s][0], S_STATUS[s][1])}</option>)}</select>
      <div className="flex-1" />
      {can.create && <Btn kind="primary" icon={<Plus size={15} />} onClick={() => setAdd(true)}>{L('تنفيذ جديد', 'New run')}</Btn>}
    </div>
    {!list.data ? <Spin /> : list.data.length === 0 ? <Panel><Empty title={L('لا توجد دورات', 'No sessions')}>{L('أنشئ تنفيذاً لبرنامج من المكتبة بمواعيد الجلسات والمدرب والمكان، ثم رشّح المتدربين.', 'Create a run of a library programme with meetings, trainer and place, then nominate trainees.')}</Empty></Panel>
      : <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(290px,1fr))]">{list.data.map(s => <button key={s.id} onClick={() => setQuery({ s: String(s.id) })} className="text-start bg-surface border border-line rounded-2xl p-4 hover:border-brand/50">
        <div className="flex items-start justify-between gap-2"><div className="font-semibold">{s.title}</div><StatusBadge map={S_STATUS} s={s.status} /></div>
        <div className="flex flex-wrap items-center gap-1.5 mt-1 text-[12px] text-ink-3"><span className="ltr">{s.ref}</span><PathBadge p={s.program?.path} />{s.program && DELIVERY[s.program.delivery] ? L(DELIVERY[s.program.delivery][0], DELIVERY[s.program.delivery][1]) : ''}</div>
        <div className="text-[12.5px] mt-2 flex items-center gap-1.5"><CalendarDays size={13} className="text-ink-3" />{fmtDate(s.starts_on, lang)}{s.ends_on !== s.starts_on ? ` — ${fmtDate(s.ends_on, lang)}` : ''} · {L(`${s.meetings.length} جلسة`, `${s.meetings.length} meetings`)}</div>
        <div className="text-[12.5px] text-ink-3 mt-0.5">{s.trainer ? bi(s.trainer, lang) : s.center ?? '—'}{s.place ? ` · ${s.place}` : ''}</div>
        <div className="flex items-center justify-between mt-2 text-[12.5px]"><span>{L('المؤكدون', 'Confirmed')} <b className="num">{s.counts.confirmed ?? 0}</b>/<span className="num">{s.capacity}</span>{s.counts.waitlist ? ` · ${L('انتظار', 'waitlist')} ${s.counts.waitlist}` : ''}</span>
          {s.total_cost > 0 && <span className="num text-ink-3">{money(s.total_cost, s.currency, lang)}</span>}</div>
      </button>)}</div>}
    {add && <SessionForm lk={lk} onClose={() => setAdd(false)} />}
    {q.s && <SessionDrawer id={Number(q.s)} lk={lk} can={can} onClose={() => setQuery({ s: undefined })} />}
  </>
}

function SessionForm({ lk, s, onClose }: { lk: Lookups; s?: SessionFull; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const act = useTdAct()
  const [v, setV] = useState({
    program_id: s?.program?.id ?? null as number | null, title: s?.title ?? '', meetings: s?.meetings ?? [{ date: '', from: '09:00', to: '13:00' }] as { date: string; from: string | null; to: string | null }[],
    trainer_id: s?.trainer?.id ?? null as number | null, center_id: s?.center_id ?? null as number | null, place: s?.place ?? '', link: s?.link ?? '', capacity: s?.capacity ?? 15, currency: s?.currency ?? 'USD',
    costs: s?.costs ?? [] as { type: string; amount: number }[], quotes: s?.quotes ?? [] as { center: string; price: number; duration: string; prior: string; terms: string; score: number }[], quote_choice: s?.quote_choice ?? null as number | null, quote_reason: s?.quote_reason ?? '',
    plan_item_id: s?.plan_item_id ?? null as number | null, room_id: s?.room_id ?? null as number | null,
  })
  const set = (o: Partial<typeof v>) => setV(x => ({ ...x, ...o }))
  const prog = lk.programs.find(p => p.id === v.program_id)
  const ext = prog && ['external', 'internal_ext'].includes(prog.delivery)
  const cap = prog ? lk.cfg.capacity[prog.method] ?? lk.cfg.capacity.general : null
  const e = errOf(act.error)
  const COST: Record<string, [string, string]> = { trainer: ['أتعاب المدرب', 'Trainer fee'], center: ['المركز', 'Centre'], room: ['القاعة', 'Room'], materials: ['المواد', 'Materials'], hospitality: ['الضيافة', 'Hospitality'], travel: ['السفر والإقامة', 'Travel'], certificates: ['الشهادات', 'Certificates'] }
  return <Modal open onClose={onClose} width={800} title={s ? s.title : L('تنفيذ برنامج', 'New run')} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={act.isPending || !v.program_id || v.meetings.some(m => !m.date)} onClick={() => act.mutate({ path: s ? `sessions/${s.id}` : 'sessions', method: s ? 'PUT' : 'POST', v: { ...v, title: v.title || null, place: v.place || null, link: v.link || null, quote_reason: v.quote_reason || null } },
      { onSuccess: r => { const w = (r.warnings as { ar: string; en: string }[] | undefined) ?? []; toast(w.length ? w.map(x => bi(x, lang)).join(' · ') : L('تم الحفظ', 'Saved')); if (!s && r.id) setQuery({ s: String(r.id) }); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid gap-3 sm:grid-cols-2">
      <Field label={L('البرنامج', 'Programme')} req error={e?.field('program_id')} className="sm:col-span-2"><select className="field" value={v.program_id ?? ''} onChange={x => set({ program_id: x.target.value ? Number(x.target.value) : null })} disabled={!!s}>
        <option value="">—</option>{lk.programs.map(p => <option key={p.id} value={p.id}>{p.code} — {bi(p, lang)} ({p.hours} {L('ساعة', 'h')})</option>)}</select></Field>
      <Field label={L('عنوان التنفيذ', 'Run title')} hint={L('اتركه فارغاً لاستعمال اسم البرنامج', 'Leave empty to use the programme name')} className="sm:col-span-2"><input className="field" value={v.title} onChange={x => set({ title: x.target.value })} /></Field>
      <Field label={L('المدرب', 'Trainer')}><select className="field" value={v.trainer_id ?? ''} onChange={x => set({ trainer_id: x.target.value ? Number(x.target.value) : null })}><option value="">—</option>
        {lk.trainers.filter(t => t.status !== 'banned').map(t => <option key={t.id} value={t.id}>{bi(t, lang)}{t.status === 'watch' ? ` (${L('تحت المراقبة', 'watch')})` : ''}{t.rating ? ` ★${t.rating}` : ''}</option>)}</select></Field>
      <Field label={L('المركز', 'Centre')}><select className="field" value={v.center_id ?? ''} onChange={x => set({ center_id: x.target.value ? Number(x.target.value) : null })}><option value="">—</option>
        {lk.centers.filter(t => t.status !== 'banned').map(t => <option key={t.id} value={t.id}>{bi(t, lang)}</option>)}</select></Field>
      <Field label={L('القاعة', 'Room')} error={e?.field('room_id')}><select className="field" value={v.room_id ?? ''} onChange={x => set({ room_id: x.target.value ? Number(x.target.value) : null })}><option value="">—</option>
        {(lk.rooms ?? []).map(r => <option key={r.id} value={r.id}>{bi(r, lang)} ({r.capacity})</option>)}</select></Field>
      <Field label={L('المكان', 'Place')}><input className="field" value={v.place} onChange={x => set({ place: x.target.value })} /></Field>
      <Field label={L('رابط عن بعد', 'Online link')}><input className="field ltr" value={v.link} onChange={x => set({ link: x.target.value })} /></Field>
      <Field label={L('السعة', 'Capacity')} hint={cap ? L(`المنصوح لهذا الأسلوب ${cap[0]}–${cap[1]}`, `Recommended for this method ${cap[0]}–${cap[1]}`) : undefined}><input type="number" min={1} className="field num" value={v.capacity} onChange={x => set({ capacity: Number(x.target.value) })} /></Field>
      <Field label={L('العملة', 'Currency')}><select className="field" value={v.currency} onChange={x => set({ currency: x.target.value })}>{lk.currencies.map(c => <option key={c.code} value={c.code}>{c.code}</option>)}</select></Field>
    </div>
    <div className="mt-4"><div className="font-semibold text-[13.5px] mb-2">{L('الجلسات', 'Meetings')}</div>
      <div className="space-y-2">{v.meetings.map((m, i) => <div key={i} className="grid grid-cols-[1fr_90px_90px_auto] gap-2">
        <input type="date" className="field" value={m.date} aria-label={L('التاريخ', 'Date')} onChange={x => set({ meetings: v.meetings.map((y, k) => k === i ? { ...y, date: x.target.value } : y) })} />
        <input type="time" className="field" value={m.from ?? ''} aria-label={L('من', 'From')} onChange={x => set({ meetings: v.meetings.map((y, k) => k === i ? { ...y, from: x.target.value } : y) })} />
        <input type="time" className="field" value={m.to ?? ''} aria-label={L('إلى', 'To')} onChange={x => set({ meetings: v.meetings.map((y, k) => k === i ? { ...y, to: x.target.value } : y) })} />
        <Btn size="sm" kind="ghost" icon={<X size={14} />} disabled={v.meetings.length < 2} aria-label={L('حذف', 'Remove')} onClick={() => set({ meetings: v.meetings.filter((_, k) => k !== i) })} /></div>)}</div>
      <Btn size="sm" className="mt-2" icon={<Plus size={14} />} onClick={() => set({ meetings: [...v.meetings, { ...v.meetings[v.meetings.length - 1], date: '' }] })}>{L('جلسة', 'Meeting')}</Btn>
      {e?.field('meetings') && <div className="text-[12px] text-danger mt-1">{e.field('meetings')}</div>}</div>
    <div className="mt-4"><div className="font-semibold text-[13.5px] mb-2">{L('التكاليف التقديرية', 'Estimated costs')}</div>
      <div className="space-y-2">{v.costs.map((c, i) => <div key={i} className="grid grid-cols-[1fr_140px_auto] gap-2">
        <select className="field" value={c.type} aria-label={L('البند', 'Item')} onChange={x => set({ costs: v.costs.map((y, k) => k === i ? { ...y, type: x.target.value } : y) })}>{lk.cfg.cost_types.map(t => <option key={t} value={t}>{COST[t] ? L(COST[t][0], COST[t][1]) : t}</option>)}</select>
        <input type="number" min={0} className="field num" value={c.amount} aria-label={L('المبلغ', 'Amount')} onChange={x => set({ costs: v.costs.map((y, k) => k === i ? { ...y, amount: Number(x.target.value) } : y) })} />
        <Btn size="sm" kind="ghost" icon={<X size={14} />} aria-label={L('حذف', 'Remove')} onClick={() => set({ costs: v.costs.filter((_, k) => k !== i) })} /></div>)}</div>
      <Btn size="sm" className="mt-2" icon={<Plus size={14} />} onClick={() => set({ costs: [...v.costs, { type: lk.cfg.cost_types[0], amount: 0 }] })}>{L('بند تكلفة', 'Cost item')}</Btn></div>
    {ext && <div className="mt-4 rounded-xl border border-line p-3"><div className="font-semibold text-[13.5px]">{L(`عروض الأسعار (${lk.cfg.min_quotes} على الأقل)`, `Quotes (at least ${lk.cfg.min_quotes})`)}</div>
      <div className="space-y-2 mt-2">{v.quotes.map((x, i) => <div key={i} className="grid gap-2 sm:grid-cols-[auto_1fr_110px_1fr_70px_auto] items-center">
        <input type="radio" name="qc" checked={v.quote_choice === i} onChange={() => set({ quote_choice: i })} aria-label={L('المختار', 'Chosen')} />
        <input className="field" placeholder={L('الجهة', 'Provider')} value={x.center} onChange={y => set({ quotes: v.quotes.map((z, k) => k === i ? { ...z, center: y.target.value } : z) })} />
        <input type="number" className="field num" placeholder={L('السعر', 'Price')} value={x.price} onChange={y => set({ quotes: v.quotes.map((z, k) => k === i ? { ...z, price: Number(y.target.value) } : z) })} />
        <input className="field" placeholder={L('الشروط والخبرة السابقة', 'Terms & prior work')} value={x.terms} onChange={y => set({ quotes: v.quotes.map((z, k) => k === i ? { ...z, terms: y.target.value } : z) })} />
        <input type="number" min={0} max={100} className="field num" placeholder="/100" value={x.score} onChange={y => set({ quotes: v.quotes.map((z, k) => k === i ? { ...z, score: Number(y.target.value) } : z) })} aria-label={L('التقييم', 'Score')} />
        <Btn size="sm" kind="ghost" icon={<X size={14} />} aria-label={L('حذف', 'Remove')} onClick={() => set({ quotes: v.quotes.filter((_, k) => k !== i), quote_choice: null })} /></div>)}</div>
      <Btn size="sm" className="mt-2" icon={<Plus size={14} />} onClick={() => set({ quotes: [...v.quotes, { center: '', price: 0, duration: '', prior: '', terms: '', score: 0 }] })}>{L('عرض', 'Quote')}</Btn>
      <Field label={L('مبرر الاختيار أو قلة العروض', 'Reason for the choice or fewer quotes')} className="mt-2"><input className="field" value={v.quote_reason} onChange={x => set({ quote_reason: x.target.value })} /></Field></div>}
    {act.error && !e?.errors && <div className="text-[13px] text-danger mt-2">{(act.error as Error).message}</div>}
  </Modal>
}

function SessionDrawer({ id, lk, can, onClose }: { id: number; lk: Lookups; can: Can; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const { data: s } = useSession(id)
  const act = useTdAct()
  const [tab, setTab] = useState<'people' | 'attendance' | 'scores' | 'eval' | 'quotes' | 'info'>('people')
  const [nom, setNom] = useState(false)
  const [edit, setEdit] = useState(false)
  const [cancel, setCancel] = useState(false)
  const [closeIt, setClose] = useState(false)
  const run = (path: string, v?: unknown, ok?: string) => act.mutate({ path, v }, { onSuccess: () => ok && toast(ok), onError: fail })
  const live = s && !['closed', 'cancelled'].includes(s.status)
  return <Drawer open onClose={onClose} width={860}>
    {!s ? <Spin /> : <div className="p-5">
      <div className="flex items-start justify-between gap-3">
        <div><div className="text-[12px] text-ink-3 ltr text-start">{s.ref}</div><h2 className="text-[19px] font-semibold">{s.title}</h2>
          <div className="flex flex-wrap items-center gap-2 mt-1 text-[12.5px]"><StatusBadge map={S_STATUS} s={s.status} /><PathBadge p={s.program?.path} />{s.program && <span className="text-ink-3">{s.program.hours} {L('ساعة', 'h')}</span>}</div></div>
        <Btn kind="ghost" icon={<X size={16} />} onClick={onClose} aria-label={L('إغلاق', 'Close')} />
      </div>
      {s.warnings.length > 0 && <div className="mt-3 space-y-1">{s.warnings.map(w => <div key={w.key} className="flex items-center gap-2 text-[12.5px] text-[var(--warn)]"><AlertTriangle size={14} />{bi(w, lang)}</div>)}</div>}
      {can.manage && <div className="flex flex-wrap gap-2 mt-3">
        {live && <Btn size="sm" icon={<UserPlus size={14} />} onClick={() => setNom(true)}>{L('ترشيح متدربين', 'Nominate')}</Btn>}
        {live && <Btn size="sm" onClick={() => setEdit(true)}>{L('تعديل', 'Edit')}</Btn>}
        {['in_progress', 'enrolling', 'confirmed'].includes(s.status) && <Btn size="sm" kind="primary" icon={<CheckCheck size={14} />} onClick={() => run(`sessions/${s.id}/complete`, {}, L('اكتمل التنفيذ — صدرت الشهادات للناجحين وجُدولت متابعة الأثر', 'Completed — certificates issued and impact follow-ups scheduled'))}>{L('إكمال التنفيذ', 'Complete')}</Btn>}
        {s.status === 'completed' && <Btn size="sm" kind="primary" icon={<Lock size={14} />} onClick={() => setClose(true)}>{L('إغلاق التنفيذ', 'Close the run')}</Btn>}
        {live && s.status !== 'completed' && <Btn size="sm" kind="danger" icon={<Ban size={14} />} onClick={() => setCancel(true)}>{L('إلغاء', 'Cancel')}</Btn>}
        <SessionMore s={s} />
        <Btn size="sm" kind="ghost" icon={<Copy size={14} />} onClick={() => navigator.clipboard?.writeText(s.survey_url).then(() => toast(L('نُسخ رابط استبيان الرضا', 'Survey link copied')))}>{L('رابط الاستبيان', 'Survey link')}</Btn>
        <Btn size="sm" kind="ghost" icon={<Copy size={14} />} onClick={() => navigator.clipboard?.writeText(s.checkin_url).then(() => toast(L('نُسخ رابط تسجيل الحضور', 'Check-in link copied')))}>{L('رابط الحضور', 'Check-in link')}</Btn>
      </div>}
      <div className="mt-4"><Tabs value={tab} onChange={setTab} tabs={[{ v: 'people', label: L('المتدربون', 'Trainees'), count: s.enrollments.length }, { v: 'attendance', label: L('الحضور', 'Attendance') },
        { v: 'scores', label: L('النتائج', 'Results') }, { v: 'eval', label: L('التقييم والأثر', 'Evaluation') },
        ...(s.quotes.length || (s.program && ['external', 'internal_ext'].includes(s.program.delivery)) ? [{ v: 'quotes' as const, label: L('العروض', 'Quotes'), count: s.quotes.length || undefined }] : []), { v: 'info', label: L('التفاصيل', 'Details') }]} /></div>
      {s.postpone_reason && <div className="mt-2 text-[12.5px] text-ink-3">{L('سبب التأجيل', 'Postponement reason')}: {s.postpone_reason}</div>}
      <div className="mt-3">{tab === 'people' ? <People s={s} can={can} /> : tab === 'attendance' ? <AttendanceGrid s={s} /> : tab === 'scores' ? <Scores s={s} /> : tab === 'eval' ? <Evaluation s={s} /> : tab === 'quotes' ? <QuotesPanel s={s} /> : <Info s={s} />}</div>
    </div>}
    {s && nom && <Nominate s={s} lk={lk} onClose={() => setNom(false)} />}
    {s && edit && <SessionForm lk={lk} s={s} onClose={() => setEdit(false)} />}
    {s && closeIt && <CloseRun s={s} onClose={() => setClose(false)} />}
    <DecisionDialog open={cancel} onClose={() => setCancel(false)} tone="danger" reasonRequired minLength={5} title={L('إلغاء التنفيذ', 'Cancel the run')} subject={s?.title} confirmLabel={L('إلغاء التنفيذ', 'Cancel the run')}
      onConfirm={r => act.mutateAsync({ path: `sessions/${id}/cancel`, v: { reason: r } })} />
  </Drawer>
}

function People({ s, can }: { s: SessionFull; can: Can }) {
  const L = useL(); const lang = useLang()
  const act = useTdAct()
  const [wd, setWd] = useState<EnrollmentRow | null>(null)
  const [ex, setEx] = useState<EnrollmentRow | null>(null)
  const [fd, setFd] = useState<EnrollmentRow | null>(null)
  const [dec, setDec] = useState('retrain')
  const [note, setNote] = useState('')
  const run = (path: string, v?: unknown, ok?: string) => act.mutate({ path, v }, { onSuccess: () => ok && toast(ok), onError: fail })
  if (s.enrollments.length === 0) return <Empty title={L('لا متدربين بعد', 'No trainees yet')}>{L('رشّح المتدربين؛ يُفحص لكل منهم: فترة التجربة، التعارض مع إجازة أو مهمة، والمتطلبات السابقة.', 'Nominate trainees; each is checked for probation, leave or mission clashes and prerequisites.')}</Empty>
  return <>
    <div className="space-y-2">{s.enrollments.map(en => <div key={en.id} className="rounded-xl border border-line p-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div><div className="font-medium">{en.employee ? bi(en.employee, lang) : '—'} <span className="text-[11.5px] text-ink-3 ltr">{en.employee?.code}</span></div>
          <div className="text-[12px] text-ink-3">{en.exclude_reason ? `${L('سبب الاستبعاد', 'Excluded')}: ${en.exclude_reason} · ` : ''}{en.reason ?? ''}{en.exception ? ` · ${L('استثناء', 'Exception')}: ${en.exception}` : ''}{en.manager_ok === true ? ` · ${L('وافق المدير', 'Manager approved')}` : en.manager_ok === false ? ` · ${L('رفض المدير', 'Manager declined')}` : ''}</div></div>
        <div className="flex flex-wrap items-center gap-1.5"><StatusBadge map={E_STATUS} s={en.status} />
          {en.result && <Badge tone={en.result === 'passed' ? 'brand' : en.result === 'failed' ? 'danger' : 'neutral'}>{en.result === 'passed' ? L('ناجح', 'Passed') : en.result === 'failed' ? L('غير ناجح', 'Failed') : L('منسحب', 'Withdrawn')}</Badge>}</div>
      </div>
      <div className="flex flex-wrap gap-x-4 gap-y-1 mt-1.5 text-[12px] text-ink-3">
        {en.attendance_pct != null && <span>{L('الحضور', 'Attendance')} <b className="num text-ink">{en.attendance_pct}%</b></span>}
        {en.pre_score != null && <span>{L('قبلي', 'Pre')} <b className="num text-ink">{en.pre_score}</b></span>}{en.post_score != null && <span>{L('بعدي', 'Post')} <b className="num text-ink">{en.post_score}</b></span>}
        {en.commitment && <span>{L('التعهد', 'Commitment')}: {en.commitment.status === 'generated' ? L('بانتظار التوقيع', 'awaiting signature') : en.commitment.status === 'active' ? L(`سارٍ ${en.commitment.months} شهراً`, `active ${en.commitment.months} months`) : en.commitment.status}</span>}
        {en.result_reason && <span>{en.result_reason}</span>}{en.failure?.decision && <span>{L('القرار', 'Decision')}: {en.failure.decision}</span>}
      </div>
      {can.manage && <div className="flex flex-wrap gap-1.5 mt-2">
        {en.status === 'nominated' && <Btn size="sm" kind="primary" onClick={() => run(`enrollments/${en.id}/confirm`, {}, L('تم التأكيد', 'Confirmed'))}>{L('تأكيد', 'Confirm')}</Btn>}
        {en.status === 'nominated' && en.manager_ok == null && <Btn size="sm" onClick={() => run(`enrollments/${en.id}/manager`, { ok: true })}>{L('موافقة المدير', 'Manager approval')}</Btn>}
        {['nominated', 'pending_commitment', 'confirmed', 'waitlist'].includes(en.status) && <Btn size="sm" kind="ghost" onClick={() => setWd(en)}>{L('سحب', 'Withdraw')}</Btn>}
        {['nominated', 'pending_commitment', 'confirmed', 'waitlist'].includes(en.status) && <Btn size="sm" kind="ghost" onClick={() => setEx(en)}>{L('استبعاد', 'Exclude')}</Btn>}
        {en.commitment && <PrintBtn code="TD3" id={en.commitment.id} label={L('التعهد', 'Commitment')} />}
        {en.apply_plan && <PrintBtn code="TD12" id={en.id} label={L('خطة التطبيق', 'Application plan')} />}
        {en.result === 'failed' && !en.failure?.decision && can.director && <Btn size="sm" kind="danger" onClick={() => { setFd(en); setNote('') }}>{L('قرار عدم النجاح', 'Failure decision')}</Btn>}
      </div>}
    </div>)}</div>
    <DecisionDialog open={!!wd} onClose={() => setWd(null)} tone="danger" reasonRequired minLength={5} title={L('سحب المتدرب', 'Withdraw the trainee')} subject={wd?.employee ? bi(wd.employee, lang) : ''} confirmLabel={L('سحب', 'Withdraw')}
      onConfirm={r => act.mutateAsync({ path: `enrollments/${wd!.id}/withdraw`, v: { reason: r } })} />
    <DecisionDialog open={!!ex} onClose={() => setEx(null)} tone="danger" reasonRequired minLength={5} title={L('استبعاد المتدرب', 'Exclude the trainee')} subject={ex?.employee ? bi(ex.employee, lang) : ''} confirmLabel={L('استبعاد', 'Exclude')}
      onConfirm={r => act.mutateAsync({ path: `enrollments/${ex!.id}/exclude`, v: { reason: r } })} />
    {fd && <Modal open onClose={() => setFd(null)} title={L('قرار موثق لعدم النجاح', 'Documented failure decision')} footer={<><Btn onClick={() => setFd(null)}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={note.trim().length < 10 || act.isPending} onClick={() => act.mutate({ path: `enrollments/${fd.id}/failure`, v: { decision: dec, note } }, { onSuccess: () => setFd(null), onError: fail })}>{L('حفظ القرار', 'Save the decision')}</Btn></>}>
      <div className="grid gap-2">{[['retrain', 'إعادة التدريب (احتياج جديد معتمد)', 'Retrain (a new approved need)'], ['extend', 'تمديد فترة التطبيق', 'Extend the practice period'], ['charge', 'تحميل التكلفة وفق التعهد', 'Charge the cost per the commitment']].map(o =>
        <label key={o[0]} className="flex items-center gap-2 text-[13.5px]"><input type="radio" checked={dec === o[0]} onChange={() => setDec(o[0])} />{L(o[1], o[2])}</label>)}
        <Field label={L('السبب الموثق', 'Documented reason')} req><textarea className="field" rows={3} value={note} onChange={e => setNote(e.target.value)} /></Field>
        <div className="text-[12px] text-ink-3">{L('لا يُخصم أي مبلغ آلياً؛ التحميل يجعل التعهد «مُخَلّاً به» بانتظار قرار الاسترداد.', 'Nothing is deducted automatically; charging marks the commitment as breached pending a recovery decision.')}</div></div>
    </Modal>}
  </>
}

function Nominate({ s, lk, onClose }: { s: SessionFull; lk: Lookups; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const act = useTdAct()
  const [ids, setIds] = useState<number[]>([])
  const [reason, setReason] = useState('')
  const [exception, setExc] = useState('')
  const [out, setOut] = useState<{ added: number; waitlist: number; blocked: { id: number; ar: string; en: string; reasons: { ar: string; en: string }[] }[] } | null>(null)
  return <Modal open onClose={onClose} width={640} title={L('ترشيح متدربين', 'Nominate trainees')} footer={<><Btn onClick={onClose}>{L('إغلاق', 'Close')}</Btn>
    <Btn kind="primary" disabled={!ids.length || act.isPending} onClick={() => act.mutate({ path: `sessions/${s.id}/nominate`, v: { employee_ids: ids, reason: reason || null, exception: exception || null } }, { onSuccess: r => { setOut(r as never); setIds([]) }, onError: fail })}>{L('ترشيح', 'Nominate')}</Btn></>}>
    {s.candidates.length > 0 && <div className="mb-3 rounded-xl border border-line p-3"><div className="flex items-center gap-2"><div className="font-semibold text-[13px] flex-1">{L('من الاحتياجات المعتمدة المرتبطة', 'From linked approved needs')}</div>
      <Btn size="sm" kind="ghost" onClick={() => setIds([...new Set([...ids, ...s.candidates.map(c => c.id)])])}>{L('اختيار الكل', 'Select all')}</Btn></div>
      <div className="mt-1.5 space-y-1">{s.candidates.map(c => <label key={c.id} className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={ids.includes(c.id)} onChange={x => setIds(x.target.checked ? [...ids, c.id] : ids.filter(i => i !== c.id))} />
        <span className="flex-1">{bi(c, lang)}</span><span className="text-[11.5px] text-ink-3">{c.need.title} · <span className="ltr">{c.need.ref}</span></span></label>)}</div></div>}
    <EmpSelect multiple lk={lk} value={ids} onChange={setIds} />
    <Field label={L('سبب الترشيح', 'Reason')} className="mt-3"><input className="field" value={reason} onChange={e => setReason(e.target.value)} /></Field>
    <Field label={L('مبرر الاستثناء (لمن في فترة التجربة أو لديه تعارض)', 'Exception reason (probation or clash)')} className="mt-2"><input className="field" value={exception} onChange={e => setExc(e.target.value)} /></Field>
    {out && <div className="mt-3 rounded-xl border border-line p-3 text-[13px]">
      <div>{L(`أُضيف ${out.added}، وعلى قائمة الانتظار ${out.waitlist}.`, `${out.added} added, ${out.waitlist} on the waitlist.`)}</div>
      {out.blocked.length > 0 && <div className="mt-2"><div className="font-semibold text-danger">{L('لم يُرشَّح (يحتاج مبرر استثناء):', 'Not nominated (needs an exception reason):')}</div>
        <ul className="list-disc ps-5">{out.blocked.map(b => <li key={b.id}>{bi(b, lang)} — {b.reasons.map(x => bi(x, lang)).join('، ')}</li>)}</ul></div>}
    </div>}
  </Modal>
}

export function AttendanceGrid({ s, base }: { s: SessionFull; base?: string }) {
  const L = useL(); const lang = useLang()
  const act = useTdAct()
  const today = new Date().toISOString().slice(0, 10)
  const [m, setM] = useState(() => Math.max(0, s.meetings.findIndex(x => x.date === today)))
  const people = s.enrollments.filter(e => ['confirmed', 'completed'].includes(e.status))
  const [marks, setMarks] = useState<Record<number, string>>({})
  const val = (en: EnrollmentRow) => marks[en.id] ?? en.attendance[String(m)] ?? ''
  if (!people.length) return <Empty title={L('لا متدربين مؤكدين', 'No confirmed trainees')} />
  return <>
    <div className="flex flex-wrap gap-1.5 mb-3">{s.meetings.map((x, i) => <button key={i} onClick={() => { setM(i); setMarks({}) }} className={`rounded-lg border px-2.5 py-1 text-[12.5px] ${i === m ? 'border-brand bg-[color-mix(in_oklab,var(--brand)_10%,transparent)]' : 'border-line'}`}>{fmtDate(x.date, lang)}</button>)}</div>
    <div className="space-y-1.5">{people.map(en => <div key={en.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-line px-3 py-2">
      <span className="text-[13.5px]">{en.employee ? bi(en.employee, lang) : '—'}</span>
      <div className="flex gap-1">{Object.entries(ATT).map(([k, t]) => <button key={k} disabled={en.status !== 'confirmed'} onClick={() => setMarks({ ...marks, [en.id]: k })}
        className={`rounded-md border px-2 py-0.5 text-[12px] ${val(en) === k ? (k === 'absent' ? 'bg-danger text-white border-danger' : 'bg-brand text-white border-brand') : 'border-line'}`}>{L(t[0], t[1])}</button>)}</div></div>)}</div>
    {s.status !== 'closed' && <Btn kind="primary" className="mt-3" disabled={!Object.keys(marks).length || act.isPending} onClick={() => act.mutate({ path: `${base ?? `sessions/${s.id}`}/attendance`, v: { meeting: m, marks } }, { onSuccess: () => { setMarks({}); toast(L('حُفظ الحضور', 'Attendance saved')) }, onError: fail })}>{L('حفظ الحضور', 'Save attendance')}</Btn>}
  </>
}

export function Scores({ s, base }: { s: SessionFull; base?: string }) {
  const L = useL(); const lang = useLang()
  const act = useTdAct()
  const people = s.enrollments.filter(e => ['confirmed', 'completed'].includes(e.status))
  const [rows, setRows] = useState<Record<number, { pre: string; post: string }>>(() => Object.fromEntries(people.map(e => [e.id, { pre: e.pre_score?.toString() ?? '', post: e.post_score?.toString() ?? '' }])))
  const pass = s.program_full?.pass_mark ?? null
  if (!people.length) return <Empty title={L('لا متدربين مؤكدين', 'No confirmed trainees')} />
  return <>
    <div className="text-[12.5px] text-ink-3 mb-2">{s.program_full?.has_test ? L(`علامة النجاح ${pass ?? '—'}٪ وحضور ${s.program_full?.attendance_min ?? '—'}٪ على الأقل. الفرق بين القبلي والبعدي يقيس التعلم (المستوى الثاني).`, `Pass mark ${pass ?? '—'}% and attendance ${s.program_full?.attendance_min ?? '—'}%. Pre/post difference measures learning (level 2).`)
      : L('البرنامج دون اختبار — يُحسب النجاح بالحضور.', 'No test — success is based on attendance.')}</div>
    <Panel pad={false}><table className="tbl"><thead><tr><th>{L('المتدرب', 'Trainee')}</th><th>{L('قبلي', 'Pre')}</th><th>{L('بعدي', 'Post')}</th><th className="hidden sm:table-cell">{L('الحضور', 'Attendance')}</th></tr></thead>
      <tbody>{people.map(en => <tr key={en.id}><td>{en.employee ? bi(en.employee, lang) : '—'}</td>
        {(['pre', 'post'] as const).map(k => <td key={k}><input type="number" min={0} max={100} className="field num !w-20" disabled={en.status === 'completed' && s.status === 'closed'} value={rows[en.id]?.[k] ?? ''} aria-label={k}
          onChange={e => setRows({ ...rows, [en.id]: { ...rows[en.id], [k]: e.target.value } })} /></td>)}
        <td className="hidden sm:table-cell num">{en.attendance_pct ?? '—'}%</td></tr>)}</tbody></table></Panel>
    {s.status !== 'closed' && <Btn kind="primary" className="mt-3" disabled={act.isPending} onClick={() => act.mutate({ path: `${base ?? `sessions/${s.id}`}/scores`, v: { rows: people.map(e => ({ id: e.id, pre: rows[e.id]?.pre === '' ? null : Number(rows[e.id]?.pre), post: rows[e.id]?.post === '' ? null : Number(rows[e.id]?.post) })) } }, { onSuccess: () => toast(L('حُفظت النتائج', 'Results saved')), onError: fail })}>{L('حفظ النتائج', 'Save results')}</Btn>}
  </>
}

function Evaluation({ s }: { s: SessionFull }) {
  const L = useL()
  const act = useTdAct()
  const KEYS: [string, string, string][] = [['content', 'المحتوى', 'Content'], ['trainer', 'المدرب', 'Trainer'], ['punctuality', 'الالتزام بالمواعيد', 'Punctuality'], ['facilities', 'التجهيزات', 'Facilities'], ['results', 'نتائج المتدربين', 'Trainee results'], ['value', 'القيمة مقابل التكلفة', 'Value for money']]
  const [sc, setSc] = useState<Record<string, number>>(s.rating?.scores ?? Object.fromEntries(KEYS.map(k => [k[0], 3])))
  const [note, setNote] = useState(s.rating?.note ?? '')
  const [kpi, setKpi] = useState({ before: s.kpi?.before?.toString() ?? '', after: s.kpi?.after?.toString() ?? '', note: s.kpi?.note ?? '', benefit: s.roi?.benefit?.toString() ?? '' })
  const REC: Record<string, [string, string, 'brand' | 'warn' | 'danger']> = { approve: ['اعتماد الجهة', 'Keep the provider', 'brand'], watch: ['تحت المراقبة', 'Watch', 'warn'], ban: ['توصية بالحظر', 'Ban recommended', 'danger'] }
  const cost = s.total_cost; const benefit = Number(kpi.benefit) || 0
  return <div className="space-y-4">
    <div className="flex flex-wrap justify-end gap-1"><PrintBtn code="TD7" id={s.id} label={L('نتائج الاستبيان F-07', 'Survey results F-07')} /><PrintBtn code="TD13" id={s.id} label={L('تقييم الجهة F-13', 'Provider rating F-13')} /></div>
    <div className="grid gap-3 sm:grid-cols-2">
      <div className="rounded-xl border border-line p-3"><div className="text-[12px] text-ink-3">{L('المستوى 1 — رضا المتدربين', 'Level 1 — reaction')}</div><div className="text-[20px] font-semibold num">{s.surveys.avg != null ? `${Math.round(s.surveys.avg * 10) / 10}/5` : '—'}</div>
        <div className="text-[12px] text-ink-3">{L(`${s.surveys.n} استجابة من ${s.counts.completed ?? s.counts.confirmed ?? 0}`, `${s.surveys.n} responses of ${s.counts.completed ?? s.counts.confirmed ?? 0}`)}</div></div>
      <div className="rounded-xl border border-line p-3"><div className="text-[12px] text-ink-3">{L('المستوى 2 — التعلم (متوسط التحسن)', 'Level 2 — learning (average gain)')}</div>
        <div className="text-[20px] font-semibold num">{(() => { const g = s.enrollments.filter(e => e.pre_score != null && e.post_score != null); return g.length ? `+${Math.round(g.reduce((a, e) => a + (e.post_score! - e.pre_score!), 0) / g.length)}` : '—' })()}</div></div>
    </div>
    <Panel title={L('تقييم الجهة المدربة', 'Provider rating')} action={s.rating && <Badge tone={REC[s.rating.recommendation]?.[2] ?? 'neutral'}>{REC[s.rating.recommendation] ? L(REC[s.rating.recommendation][0], REC[s.rating.recommendation][1]) : s.rating.recommendation} · {s.rating.avg}</Badge>}>
      <div className="grid gap-2 sm:grid-cols-2">{KEYS.map(k => <div key={k[0]} className="flex items-center justify-between gap-2"><span className="text-[13px]">{L(k[1], k[2])}</span>
        <div className="flex gap-0.5">{[1, 2, 3, 4, 5].map(n => <button key={n} onClick={() => setSc({ ...sc, [k[0]]: n })} aria-label={`${n}`}><Star size={18} className={n <= (sc[k[0]] ?? 0) ? 'text-brass fill-current' : 'text-ink-3'} /></button>)}</div></div>)}</div>
      <Field label={L('ملاحظات', 'Notes')} className="mt-2"><input className="field" value={note} onChange={e => setNote(e.target.value)} /></Field>
      <Btn size="sm" kind="primary" className="mt-2" disabled={act.isPending} onClick={() => act.mutate({ path: `sessions/${s.id}/rate`, v: { scores: sc, note: note || null } }, { onSuccess: () => toast(L('حُفظ التقييم', 'Rating saved')), onError: fail })}>{L('حفظ التقييم', 'Save the rating')}</Btn>
    </Panel>
    {s.surveys.n > 0 && <SurveySummary s={s} />}
    <Panel title={L('المستوى 4 — النتائج والعائد', 'Level 4 — results & return')}>
      <div className="grid gap-2 sm:grid-cols-3"><Field label={L('المؤشر قبل', 'KPI before')}><input type="number" className="field num" value={kpi.before} onChange={e => setKpi({ ...kpi, before: e.target.value })} /></Field>
        <Field label={L('المؤشر بعد', 'KPI after')}><input type="number" className="field num" value={kpi.after} onChange={e => setKpi({ ...kpi, after: e.target.value })} /></Field>
        <Field label={L('المنفعة المقدّرة', 'Estimated benefit')}><input type="number" className="field num" value={kpi.benefit} onChange={e => setKpi({ ...kpi, benefit: e.target.value })} /></Field></div>
      <Field label={L('ملاحظة', 'Note')} className="mt-2"><input className="field" value={kpi.note} onChange={e => setKpi({ ...kpi, note: e.target.value })} /></Field>
      {cost > 0 && benefit > 0 && <div className="text-[13px] mt-2">{L('العائد على الاستثمار', 'ROI')}: <b className="num">{Math.round((benefit - cost) * 100 / cost)}%</b></div>}
      <Btn size="sm" className="mt-2" disabled={act.isPending} onClick={() => act.mutate({ path: `sessions/${s.id}/kpi`, v: { kpi: { before: kpi.before === '' ? undefined : Number(kpi.before), after: kpi.after === '' ? undefined : Number(kpi.after), note: kpi.note || undefined }, roi: kpi.benefit ? { benefit: Number(kpi.benefit) } : null } }, { onSuccess: () => toast(L('تم الحفظ', 'Saved')), onError: fail })}>{L('حفظ', 'Save')}</Btn>
    </Panel>
  </div>
}

function Info({ s }: { s: SessionFull }) {
  const L = useL(); const lang = useLang()
  const act = useTdAct()
  const [lg, setLg] = useState(s.logistics)
  return <div className="space-y-4">
    <div className="grid gap-2 sm:grid-cols-2">
      <KV k={L('المدرب', 'Trainer')} v={s.trainer ? bi(s.trainer, lang) : '—'} /><KV k={L('المركز', 'Centre')} v={s.center ?? '—'} />
      <KV k={L('المكان', 'Place')} v={s.place ?? '—'} /><KV k={L('رابط', 'Link')} v={s.link ? <a className="underline ltr" href={s.link} target="_blank" rel="noreferrer">{s.link}</a> : '—'} />
      <KV k={L('السعة', 'Capacity')} v={s.capacity} /><KV k={L('التكلفة', 'Cost')} v={money(s.total_cost, s.currency, lang)} />
    </div>
    <Panel title={L('الجلسات', 'Meetings')}><ul className="text-[13px] space-y-1">{s.meetings.map((m, i) => <li key={i}>{fmtDate(m.date, lang)} <span className="num text-ink-3">{m.from ?? ''}{m.to ? `–${m.to}` : ''}</span></li>)}</ul></Panel>
    {s.costs.length > 0 && <Panel title={L('التكاليف', 'Costs')}><ul className="text-[13px] space-y-1">{s.costs.map((c, i) => <li key={i} className="flex justify-between"><span>{c.type}</span><span className="num">{money(c.amount, c.currency ?? s.currency, lang)}</span></li>)}</ul></Panel>}
    {lg.length > 0 && <Panel title={L('قائمة التجهيزات', 'Logistics checklist')}>
      <div className="space-y-1">{lg.map((x, i) => <label key={i} className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={x.done} onChange={e => setLg(lg.map((y, k) => k === i ? { ...y, done: e.target.checked } : y))} />{x.item}</label>)}</div>
      <Btn size="sm" className="mt-2" disabled={act.isPending} onClick={() => act.mutate({ path: `sessions/${s.id}/logistics`, v: { logistics: lg } }, { onSuccess: () => toast(L('تم الحفظ', 'Saved')), onError: fail })}>{L('حفظ', 'Save')}</Btn></Panel>}
  </div>
}

function CloseRun({ s, onClose }: { s: SessionFull; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const act = useTdAct()
  const [costs, setCosts] = useState(s.costs.map(c => ({ ...c })))
  return <Modal open onClose={onClose} title={L('إغلاق التنفيذ', 'Close the run')} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={act.isPending} onClick={() => act.mutate({ path: `sessions/${s.id}/close`, v: { costs } }, { onSuccess: () => { toast(L('أُغلق التنفيذ', 'Run closed')); onClose() }, onError: fail })}>{L('إغلاق', 'Close')}</Btn></>}>
    <div className="text-[13px] text-ink-3 mb-3">{L('لا يُغلق التنفيذ قبل اعتماد الحضور وإدخال النتائج ووصول استبيان الرضا إلى الحد الأدنى. أدخل التكاليف الفعلية:', 'A run cannot close before attendance, results and the minimum survey response. Enter the actual costs:')}</div>
    <div className="space-y-2">{costs.map((c, i) => <div key={i} className="flex items-center justify-between gap-2"><span className="text-[13px]">{c.type}</span>
      <input type="number" min={0} className="field num !w-36" value={c.amount} onChange={e => setCosts(costs.map((y, k) => k === i ? { ...y, amount: Number(e.target.value) } : y))} aria-label={c.type} /></div>)}</div>
    <div className="text-[12.5px] mt-2">{L('المجموع', 'Total')}: <b className="num">{money(costs.reduce((a, c) => a + Number(c.amount || 0), 0), s.currency, lang)}</b></div>
  </Modal>
}

// ———————————————— التقويم ————————————————

export function CalendarTab({ year }: { year: number }) {
  const L = useL(); const lang = useLang()
  const { data } = useCalendar(year)
  const months = Array.from({ length: 12 }, (_, i) => i + 1)
  const name = (m: number) => new Date(year, m - 1, 1).toLocaleDateString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { month: 'long' })
  if (!data) return <Spin />
  return <>
    <div className="flex items-center gap-2 mb-3"><select className="field !w-28" value={year} onChange={e => setQuery({ year: e.target.value })} aria-label={L('السنة', 'Year')}>{[year - 1, year, year + 1].map(y => <option key={y} value={y}>{y}</option>)}</select>
      <a className="text-[12.5px] underline text-ink-3" href="/api/me/training/calendar.ics">{L('اشتراك iCal', 'iCal subscription')}</a>
      <span className="flex-1" />{Object.entries(PATH).map(([k, v]) => <span key={k} className="hidden sm:inline-flex items-center gap-1 text-[12px]"><span className="size-2.5 rounded-full" style={{ background: v[2] }} />{L(v[0], v[1])}</span>)}</div>
    <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(250px,1fr))]">{months.map(m => {
      const mm = String(m).padStart(2, '0')
      const ss = data.sessions.filter(s => s.from.slice(5, 7) === mm); const it = data.items.filter(i => i.month === m); const hs = data.holidays.filter(h => h.date.slice(5, 7) === mm)
      return <div key={m} className="bg-surface border border-line rounded-2xl p-3 min-h-[120px]">
        <div className="font-semibold mb-1.5">{name(m)}</div>
        <div className="space-y-1">{ss.map(s => <button key={s.id} onClick={() => setQuery({ tab: 'sessions', s: String(s.id) })} className="w-full text-start flex items-start gap-1.5 text-[12.5px] hover:text-brand">
          <span className="size-2 rounded-full mt-1.5 shrink-0" style={{ background: s.path ? PATH[s.path][2] : 'var(--line)' }} /><span className="flex-1">{s.title} <span className="text-ink-3 num">{s.from.slice(8)}{s.to !== s.from ? `–${s.to.slice(8)}` : ''}</span></span></button>)}
          {it.map(i => <div key={i.id} className="flex items-start gap-1.5 text-[12.5px] text-ink-3"><span className="size-2 rounded-full mt-1.5 shrink-0 border" style={{ borderColor: PATH[i.path][2] }} /><span>{i.title} · {L('غير مجدول', 'unscheduled')}</span></div>)}
          {hs.map(h => <div key={h.date} className="text-[11.5px] text-danger">{h.date.slice(8)} · {bi(h, lang)}</div>)}
          {!ss.length && !it.length && !hs.length && <div className="text-[12px] text-ink-3">—</div>}</div>
      </div>
    })}</div>
  </>
}
