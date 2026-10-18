import { useState } from 'react'
import { ArrowRight, ArrowLeft, Check, Copy, Send, Loader2, Lock, Undo2, BadgeCheck } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime } from '../../../lib/store'
import { setQuery, useRoute } from '../../../lib/router'
import { can, useMe } from '../../../lib/session'
import type { ApiError } from '../../../lib/api'
import { useConfirm, useIssueAsset, useOnboardFile, useReturnAsset, useSendMemo, useToggleItem, type OnboardFile } from '../../../lib/onboarding'
import { Avatar, Badge, Btn, Empty, Panel, Progress, Tabs, toast } from '../../../ui/kit'
import { Paper, SigBox, Timeline } from '../../../ui/docs'
import { DecisionDialog } from '../../../ui/decision'
import { StatusBadge } from './OnboardingList'

type Tab = 'f19' | 'f32' | 'custody' | 'pledges' | 'log'

/** صفحة مباشرة موظف (M06): F-19، مذكرة الترحيب F-32، تسليم العهدة، التعهدات، السجل — والتثبيت بعد التجربة */
export function OnboardPage({ id }: { id: number }) {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const { data: e, isLoading, error } = useOnboardFile(id)
  const editable = can(useMe().data, 'employees', 'edit')
  const [confirm, setConfirm] = useState(false)
  const done = useConfirm(id)
  const tab = (['f19', 'f32', 'custody', 'pledges', 'log'] as Tab[]).find(t => t === route.query.tab) ?? 'f19'
  if (isLoading) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  if (error || !e) return <div className="max-w-[1100px] mx-auto bg-surface border border-line rounded-2xl"><Empty title={L('سجل المباشرة غير موجود', 'Onboarding record not found')} action={<a href="/hr/onboarding" className="text-brand font-semibold">{L('العودة للقائمة', 'Back to the list')}</a>} /></div>
  const Back = lang === 'ar' ? ArrowRight : ArrowLeft
  const pending = e.assets.filter(a => a.status === 'pending_ack').length
  return <div className="max-w-[1100px] mx-auto">
    <a href="/hr/onboarding" className="text-[13px] text-ink-3 hover:text-ink inline-flex items-center gap-1 mb-2" data-noprint><Back size={14} />{L('التعيين والمباشرة', 'Onboarding')}</a>
    <div className="bg-surface border border-line rounded-2xl p-5 mb-4 flex flex-wrap items-center gap-4" data-noprint>
      <Avatar size={48} />
      <div className="flex-1 min-w-[200px]"><h1 className="text-[20px] font-bold flex flex-wrap items-center gap-2">{bi(e.name, lang)} <StatusBadge s={e.status} /></h1>
        <div className="text-ink-2 text-[14px]">{bi(e.title, lang)} — {bi(e.dept, lang)} — <span className="ltr inline-block">{e.code}</span></div>
        <div className="text-[12.5px] text-ink-3 mt-0.5">{L('المباشرة', 'Start')} <span className="num">{fmtDate(e.hire_date, lang)}</span>{e.manager && <> · {L('المدير المباشر', 'Manager')}: {bi(e.manager, lang)}</>}
          {e.probation.end && <> · {L('نهاية التجربة', 'Probation ends')} <span className="num">{fmtDate(e.probation.end, lang)}</span></>}</div></div>
      <div className="w-full sm:w-56"><div className="flex justify-between text-[12px] mb-1"><span className="text-ink-3">{L('إجراءات المباشرة', 'Onboarding')}</span><span className="num font-semibold">{e.done}/{e.total}</span></div><Progress value={e.done / e.total * 100} />
        {e.status === 'onboarding' && <div className="text-[11.5px] text-ink-3 mt-1">{L(`فترة التجربة تبدأ عند ${e.probation_at} بنداً`, `Probation starts at ${e.probation_at} items`)}</div>}</div>
      {editable && e.status === 'probation' && <Btn kind="primary" icon={<BadgeCheck size={16} />} onClick={() => setConfirm(true)}>{L('تثبيت التعيين', 'Confirm appointment')}</Btn>}
    </div>
    <Tabs value={tab} onChange={v => setQuery({ tab: v === 'f19' ? undefined : v })} tabs={[{ v: 'f19', label: L('قائمة إجراءات المباشرة', 'Onboarding checklist') }, { v: 'f32', label: L('مذكرة الترحيب', 'Welcome memo') },
      { v: 'custody', label: L('تسليم العهدة', 'Custody'), count: e.assets.length }, { v: 'pledges', label: L('التعهدات', 'Undertakings') }, { v: 'log', label: L('السجل', 'History'), count: e.logs.length }]} />
    <div className="mt-4">
      {tab === 'f19' && <Checklist e={e} editable={editable} />}
      {tab === 'f32' && <Memo e={e} editable={editable} />}
      {tab === 'custody' && <Custody e={e} editable={editable} pending={pending} />}
      {tab === 'pledges' && <div className="grid md:grid-cols-3 gap-4">
        <Panel title={L('تعهد المحافظة على سرية العمل', 'Confidentiality undertaking')} sub="F-22"><SigBox label={L('توقيع الموظف', 'Employee signature')} name={bi(e.name, lang)} sig={e.f22.signature ?? undefined} at={e.f22.signed_at ?? undefined} /></Panel>
        <Panel title={L('إقرار إبراء الذمة الوقائي', 'Precautionary clearance')} sub={<>F-20{e.f20.mother_name && <> · {L('اسم الأم', "Mother's name")}: {e.f20.mother_name}</>}</>}><SigBox label={L('توقيع الموظف', 'Employee signature')} name={bi(e.name, lang)} sig={e.f20.signature ?? undefined} at={e.f20.signed_at ?? undefined} /></Panel>
        <Panel title={L('إقرار ميثاق السلوك المهني', 'Code of conduct acknowledgment')}><SigBox label={L('توقيع الموظف', 'Employee signature')} name={bi(e.name, lang)} sig={e.conduct.signature ?? undefined} at={e.conduct.signed_at ?? undefined} /></Panel>
        <p className="text-[13px] text-ink-3 md:col-span-3">{L('يوقّع الموظف التعهدات من حسابه في قسم «وثائق للتوقيع».', 'The employee signs these from "Documents to sign" in their account.')}</p>
      </div>}
      {tab === 'log' && <Panel><Timeline items={e.logs.map(l => ({ ...l, form: l.form ?? undefined }))} /></Panel>}
    </div>
    <DecisionDialog open={confirm} onClose={() => setConfirm(false)} title={L('تثبيت التعيين بعد فترة التجربة', 'Confirm appointment after probation')} confirmLabel={L('تثبيت التعيين', 'Confirm appointment')}
      subject={<>{bi(e.name, lang)} — <span className="ltr inline-block">{e.code}</span>{e.probation.end && !e.probation.due && <div className="text-[12.5px] text-warn mt-1">{L(`فترة التجربة تنتهي في ${fmtDate(e.probation.end, lang)} — التثبيت قبلها استثناء.`, `Probation ends on ${fmtDate(e.probation.end, lang)} — confirming earlier is an exception.`)}</div>}</>}
      reasonLabel={L('ملاحظة تقييم نهاية التجربة (اختيارية)', 'Probation review note (optional)')}
      onConfirm={note => done.mutateAsync(note).then(() => toast(L('ثُبّت التعيين — الموظف على رأس العمل', 'Appointment confirmed — employee is active')))} />
  </div>
}

function Checklist({ e, editable }: { e: OnboardFile; editable: boolean }) {
  const L = useL(); const lang = useLang()
  const toggle = useToggleItem(e.id)
  const closed = !!e.probation.confirmed_at
  const autoHint = { memo: L('يُنجز بتعميم مذكرة الترحيب', 'Done by circulating the welcome memo'), f22: L('يُنجز بتوقيع الموظف على F-22', 'Done when the employee signs F-22'), f20: L('يُنجز بتوقيع الموظف على F-20', 'Done when the employee signs F-20'),
    custody: L('يُنجز عند توقيع الموظف استلام كل عهده', 'Done when the employee signs for all assets'), confirm: L('يُنجز بزر «تثبيت التعيين» في فترة التجربة', 'Done with "Confirm appointment" during probation') }
  return <Paper code="F-19" title={L('قائمة تحقق إجراءات الموظف الجديد', 'New employee onboarding checklist')} subject={e.code}
    sub={L(`بعد إنجاز ${e.probation_at} خطوة ينتقل الموظف تلقائياً إلى فترة التجربة.`, `After ${e.probation_at} steps the employee moves to probation automatically.`)}>
    <ol className="grid md:grid-cols-2 gap-x-6">{e.items.map((s, i) => { const locked = !editable || closed || !!s.auto
      return <li key={i}><button disabled={locked || toggle.isPending} title={s.auto ? autoHint[s.auto] : undefined} aria-pressed={s.done}
        onClick={() => toggle.mutate(i, { onError: x => toast((x as ApiError).message) })}
        className="w-full flex items-start gap-3 py-2 border-b border-line-2 text-start enabled:hover:bg-sub disabled:cursor-default">
        <span className={`mt-0.5 size-5 rounded border grid place-items-center shrink-0 ${s.done ? 'bg-brand border-brand text-on-brand' : 'border-ink-3'}`}>{s.done && <Check size={13} />}</span>
        <span className="text-[12px] text-ink-3 num w-5 shrink-0 mt-0.5">{i + 1}</span>
        <span className={`flex-1 text-[14px] ${s.done ? 'text-ink-3 line-through' : ''}`}>{bi(s, lang)}</span>
        {s.auto && !s.done && <Lock size={13} className="mt-1 text-ink-3 shrink-0" aria-label={autoHint[s.auto]} />}
      </button></li> })}</ol>
    <p className="text-[12px] text-ink-3 mt-3 flex items-center gap-1.5" data-noprint><Lock size={12} />{L('البنود المقفلة تُنجز تلقائياً من مصدرها: التعميم، التوقيعات، العهد، والتثبيت.', 'Locked items complete automatically from their source: the memo, signatures, custody and confirmation.')}</p>
  </Paper>
}

function Memo({ e, editable }: { e: OnboardFile; editable: boolean }) {
  const L = useL(); const lang = useLang()
  const send = useSendMemo(e.id)
  const text = bi(e.memo.text, lang)
  return <Paper code="F-32" title={L('مذكرة الترحيب بالموظف الجديد', 'Welcome memo')} subject={e.code} actions={<>
    <Btn size="sm" icon={<Copy size={15} />} onClick={() => { navigator.clipboard?.writeText(text); toast(L('نُسخ النص', 'Copied')) }}>{L('نسخ النص', 'Copy text')}</Btn>
    {editable && !e.probation.confirmed_at && <Btn size="sm" kind="primary" disabled={send.isPending} icon={send.isPending ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />}
      onClick={() => send.mutate(undefined, { onSuccess: () => toast(L('عُمّمت المذكرة على الأقسام', 'Memo circulated')), onError: x => toast((x as ApiError).message) })}>{e.memo.sent_at ? L('إعادة الإرسال', 'Resend') : L('تعميم على الأقسام', 'Circulate')}</Btn>}</>}>
    {e.memo.sent_at && <div className="mb-3"><Badge tone="brand">{L('عُمّمت', 'Circulated')} <span className="num">{fmtDateTime(e.memo.sent_at, lang)}</span></Badge></div>}
    <div className="text-[13px] text-ink-3 mb-1">{L('إلى: جميع الأقسام — نسخة إلى', 'To: all departments — cc')} {e.manager ? bi(e.manager, lang) : '—'}</div>
    <pre className="whitespace-pre-wrap font-sans text-[15px] leading-8 border-t border-line-2 pt-3">{text}</pre>
  </Paper>
}

function Custody({ e, editable, pending }: { e: OnboardFile; editable: boolean; pending: number }) {
  const L = useL(); const lang = useLang()
  const [pick, setPick] = useState('')
  const issue = useIssueAsset(e.id); const back = useReturnAsset(e.id)
  const open = editable && !e.probation.confirmed_at
  return <Panel title={L('العهد المسلّمة للموظف', 'Assets issued')} sub={pending ? L(`${pending} بانتظار توقيع الموظف`, `${pending} awaiting the employee's signature`) : undefined} pad={false}
    action={open && <div className="flex flex-wrap gap-2 w-full sm:w-auto"><select className="field !h-8 flex-1 sm:!w-64" value={pick} onChange={x => setPick(x.target.value)} aria-label={L('أصل من المستودع', 'Asset from stock')}>
      <option value="">{L('اختر من المستودع…', 'Choose from stock…')}</option>{e.stock.map(a => <option key={a.id} value={a.id}>{bi(a.name, lang)} — {a.tag}</option>)}</select>
      <Btn size="sm" kind="primary" disabled={!pick || issue.isPending} onClick={() => issue.mutate(Number(pick), { onSuccess: () => { setPick(''); toast(L('سُلّمت العهدة — بانتظار توقيع الموظف', 'Issued — awaiting employee signature')) }, onError: x => toast((x as ApiError).message) })}>{L('تسليم', 'Issue')}</Btn></div>}>
    {e.assets.length === 0 ? <Empty title={L('لم تُسلّم عهد بعد', 'No assets issued yet')} /> : <table className="tbl w-full"><thead><tr><th>{L('الأصل', 'Asset')}</th><th className="hidden sm:table-cell">{L('الرمز', 'Tag')}</th><th className="hidden md:table-cell">{L('الرقم التسلسلي', 'Serial')}</th><th>{L('الحالة', 'Status')}</th><th className="w-0" /></tr></thead><tbody>
      {e.assets.map(a => <tr key={a.id}><td><div>{bi(a.name, lang)}</div><div className="text-[12px] text-ink-3 sm:hidden ltr text-start">{a.tag}</div></td><td className="hidden sm:table-cell ltr num">{a.tag}</td><td className="hidden md:table-cell ltr num text-ink-3">{a.serial ?? '—'}</td>
        <td>{a.status === 'assigned' ? <Badge tone="brand">{L('موقّع الاستلام', 'Signed')}</Badge> : <Badge tone="brass">{L('بانتظار توقيع الموظف', 'Awaiting signature')}</Badge>}</td>
        <td className="text-end">{open && a.status === 'pending_ack' && <Btn size="sm" kind="ghost" icon={<Undo2 size={14} />} disabled={back.isPending} title={L('إلغاء التسليم وإعادته للمستودع', 'Cancel and return to stock')}
          onClick={() => back.mutate(a.id, { onSuccess: () => toast(L('أُعيد الأصل للمستودع', 'Returned to stock')), onError: x => toast((x as ApiError).message) })}><span className="hidden sm:inline">{L('إلغاء', 'Cancel')}</span></Btn>}</td></tr>)}
    </tbody></table>}
  </Panel>
}
