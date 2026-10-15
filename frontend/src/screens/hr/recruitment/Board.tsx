import { useEffect, useState } from 'react'
import { Clock, Settings2, Loader2 } from 'lucide-react'
import { useL, useLang, bi } from '../../../lib/store'
import { navigate, setQuery, useRoute } from '../../../lib/router'
import { can, useMe } from '../../../lib/session'
import { useBoard, useMove, type CandCard } from '../../../lib/candidates'
import { STAGES, type Stage } from '../../../lib/recruitment'
import type { ApiError } from '../../../lib/api'
import { Avatar, Badge, Btn, PageHead, toast } from '../../../ui/kit'
import { DecisionDialog } from '../../../ui/decision'
import { RulesModal } from './RulesModal'

const COLUMNS: Stage[] = ['new', 'screening', 'interview', 'technical', 'offer', 'hired', 'rejected']

export const rejectPresets = (L: ReturnType<typeof useL>) => [L('لا تتوفر الخبرة المطلوبة للوظيفة', 'The required experience is not met'), L('الراتب المتوقع خارج نطاق الوظيفة', 'Salary expectation is outside the band'),
  L('نتيجة المقابلة غير كافية', 'The interview result was insufficient'), L('تم شغل الوظيفة بمرشح آخر', 'The position was filled by another candidate')]

/**
 * لوحة المتقدمين (M03 — Recruitment في الديمو): سبعة أعمدة، السحب والإفلات، المهلة والمطابقة والشارات.
 * قواعد النقل على الخادم؛ «عدم القبول» عبر DecisionDialog بسبب إلزامي، و«عرض العمل» بلا عرض يفتح تبويب العرض.
 */
export function Board() {
  const L = useL(); const lang = useLang(); const P = lang === 'ar' ? '٪' : '%'
  const route = useRoute(); const me = useMe().data
  const job = Number(route.query.job) || undefined
  const [q, setQ] = useState(route.query.q ?? '')
  const [search, setSearch] = useState(q)
  useEffect(() => { const t = setTimeout(() => { setSearch(q); setQuery({ q: q || undefined }) }, 250); return () => clearTimeout(t) }, [q])
  const { data, isLoading } = useBoard(job, search)
  const move = useMove()
  const [drag, setDrag] = useState<number | null>(null); const [over, setOver] = useState<Stage | null>(null)
  const [reject, setReject] = useState<CandCard | null>(null); const [rulesOpen, setRulesOpen] = useState(false)
  const canEdit = can(me, 'candidates', 'edit'), canDecide = can(me, 'candidates', 'decide')
  const list = data?.data ?? []

  const drop = (to: Stage) => {
    setOver(null); if (!drag) return
    const c = list.find(x => x.id === drag); setDrag(null)
    if (!c || c.stage === to) return
    if (c.appointment && to !== 'rejected') { toast(L('لا يمكن نقل المتقدم بعد قبول العرض — أكمل قرار التعيين', 'Cannot move after offer acceptance — complete the appointment')); return }
    if (to === 'hired') { toast(L('التعيين يتم عبر قرار التعيين بعد قبول العرض', 'Hiring happens through the appointment decision after offer acceptance')); return }
    if (to === 'offer' && !c.offer) { navigate(`/hr/candidates/${c.id}?tab=offer`); return }
    if (to === 'rejected') { if (canDecide) setReject(c); else toast(L('عدم القبول يحتاج صلاحية الاعتماد/الرفض', 'Not selecting needs the approve/reject permission')); return }
    move.mutate({ id: c.id, stage: to }, {
      onSuccess: () => toast(L(`نُقل ${c.name.ar} إلى ${STAGES[to].ar}`, `${c.name.en} moved to ${STAGES[to].en}`)),
      onError: e => toast((e as ApiError).message),
    })
  }

  return <div className="max-w-[1500px] mx-auto">
    <PageHead title={L('المتقدمون', 'Applicants')} sub={L('اسحب البطاقة لنقل المتقدم بين المراحل، أو افتحها لعرض ملفه.', 'Drag a card to move it between stages, or open it to see the file.')}
      actions={<>
        <Btn icon={<Settings2 size={15} />} onClick={() => setRulesOpen(true)}>{L('قواعد الأتمتة', 'Automation rules')}</Btn>
        <input className="field sm:!w-56" placeholder={L('بحث بالاسم أو رقم الطلب', 'Search name or reference')} value={q} onChange={e => setQ(e.target.value)} aria-label={L('بحث', 'Search')} />
        <select className="field sm:!w-60" value={job ?? ''} onChange={e => setQuery({ job: e.target.value || undefined })} aria-label={L('الوظيفة', 'Position')}>
          <option value="">{L('كل الوظائف', 'All positions')}</option>{data?.jobs.map(j => <option key={j.id} value={j.id}>{bi(j.title, lang)} — {j.code}</option>)}</select>
      </>} />
    {isLoading ? <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
      : <div className="flex gap-3 overflow-x-auto pb-3 items-start">
        {COLUMNS.map(st => { const items = list.filter(c => c.stage === st); const sla = data?.sla[st]
          return <section key={st} onDragOver={e => { if (canEdit) { e.preventDefault(); setOver(st) } }} onDragLeave={() => setOver(null)} onDrop={() => drop(st)}
            className={`w-[280px] shrink-0 rounded-2xl border ${over === st ? 'border-brand bg-brand-soft' : 'border-line bg-sub/70'}`} aria-label={STAGES[st][lang]}>
            <header className="h-11 px-3 flex items-center justify-between"><span className="font-semibold text-[14px]">{STAGES[st][lang]}</span>
              <span className="text-[12px] text-ink-3 num" title={sla ? L(`مهلة المرحلة ${sla} أيام`, `Stage limit ${sla} days`) : ''}>{items.length}</span></header>
            <div className="px-2 pb-2 space-y-2 min-h-[120px] max-h-[calc(100vh-250px)] overflow-y-auto">
              {items.map(c => <article key={c.id} draggable={canEdit} onDragStart={() => setDrag(c.id)} onDragEnd={() => setDrag(null)} onClick={() => navigate(`/hr/candidates/${c.id}`)}
                className={`hoverlift bg-surface rounded-xl border border-line p-3 cursor-pointer ${drag === c.id ? 'opacity-50' : ''}`}>
                <div className="flex items-start gap-2.5"><Avatar size={30} tone={c.internal ? 'ink' : 'brand'} />
                  <div className="flex-1 min-w-0"><div className="font-semibold text-[14px] truncate">{bi(c.name, lang)}</div><div className="text-[12px] text-ink-3 truncate">{bi(c.job.title, lang)}</div></div>
                  <span className={`text-[13px] font-bold num ${c.match >= 75 ? 'text-brand' : c.match >= 55 ? 'text-brass' : 'text-ink-3'}`}>{c.match}{P}</span></div>
                <div className="flex items-center justify-between gap-2 mt-2.5 text-[12px]">
                  <span className="flex items-center gap-1.5 min-w-0"><span className="text-ink-3 ltr num truncate">#{c.ref.split('-').pop()}</span>{c.internal && <Badge tone="info">{L('داخلي', 'Internal')}</Badge>}{c.referred && <Badge tone="brass">{L('إحالة', 'Referral')}</Badge>}{c.band === 'review' && c.stage === 'new' && <Badge tone="warn">{L('مطابقة منخفضة', 'Low match')}</Badge>}</span>
                  {c.sla === 'late' ? <Badge tone="danger"><Clock size={11} />{L(`متأخر ${c.days_in_stage - c.sla_limit} ي`, `${c.days_in_stage - c.sla_limit}d over`)}</Badge>
                    : c.sla === 'due' ? <Badge tone="warn">{L('يستحق اليوم', 'Due today')}</Badge>
                    : c.offer === 'accepted' ? <Badge tone="brand">{L('وقّع العرض', 'Offer signed')}</Badge>
                    : c.offer === 'negotiation' ? <Badge tone="warn">{L('طلب تعديل', 'Negotiating')}</Badge>
                    : c.stage === 'hired' || c.stage === 'rejected' ? null : <span className="text-ink-3 shrink-0">{c.days_in_stage} {L('ي بالمرحلة', 'd in stage')}</span>}
                </div>
              </article>)}
            </div>
          </section> })}
      </div>}
    <RulesModal open={rulesOpen} onClose={() => setRulesOpen(false)} />
    <DecisionDialog open={!!reject} onClose={() => setReject(null)} tone="danger" reasonRequired presets={rejectPresets(L)}
      title={L('عدم قبول المتقدم', 'Not selecting the applicant')} reasonLabel={L('سبب عدم القبول', 'Reason for not selecting')}
      subject={reject && <><span className="font-semibold">{bi(reject.name, lang)}</span> · {bi(reject.job.title, lang)}<div className="text-[13px] text-ink-2 mt-1">{L('سيصل المتقدم إشعار مهذب بانتهاء مراجعة طلبه.', 'The applicant will receive a courteous notice.')}</div></>}
      confirmLabel={L('تأكيد عدم القبول', 'Confirm')}
      onConfirm={reason => move.mutateAsync({ id: reject!.id, stage: 'rejected', reason }).then(() => toast(L('أُغلق الطلب وأُبلغ المتقدم', 'Application closed and applicant notified')))} />
  </div>
}
