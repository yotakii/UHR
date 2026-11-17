import { useState } from 'react'
import { Loader2 } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { fmtDate } from '../../lib/dates'
import { useMyContracts, useSignEval, useSignMine } from '../../lib/contracts'
import { Badge, Empty, Field, PageHead, Panel, toast } from '../../ui/kit'
import { ConfirmSend } from '../../ui/decision'
import { CaseBadge, ContractBadge, ContractSigs, EvalForm, EvalView, Left, PrintLink, SignContract } from './parts'

/** «عقدي» (البوابتان): عقودي وتوقيعها، تقييم تجربتي للتوقيع، وتقييمات فريقي (للمدير) — الدفعة 19 */
export function MyContracts({ base }: { base: string }) {
  const L = useL(); const lang = useLang()
  const { data } = useMyContracts()
  const sign = useSignMine(); const signEval = useSignEval()
  const [notes, setNotes] = useState<Record<number, string>>({})
  if (!data) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  return <div className="max-w-[1000px] mx-auto space-y-5">
    <PageHead title={L('عقدي', 'My contract')} sub={L('عقود عملك وتوقيعها، وتقييم فترة التجربة.', 'Your employment contracts, their signatures and your probation evaluation.')} />
    {data.team.length > 0 && <Panel title={L('تقييمات فريقي المطلوبة', 'Team evaluations to do')}><div className="space-y-5">{data.team.map(c => <div key={c.id} className="rounded-xl border border-line p-4">
      <div className="flex flex-wrap items-center gap-2 mb-3"><b>{bi(c.employee, lang)}</b><span className="text-ink-3">· {c.kind === 'renewal' ? L('تجديد العقد', 'Contract renewal') : L('تقييم فترة التجربة', 'Probation evaluation')}</span>
        <CaseBadge c={c} />{c.manager_due && c.status === 'manager' && <span className="text-[12.5px]">{L('المهلة', 'Deadline')} <span className="num">{fmtDate(c.manager_due, lang)}</span></span>}</div>
      {c.can?.evaluate ? <EvalForm c={c} me /> : <><EvalView c={c} />{c.can?.sign && <div className="mt-3"><ConfirmSend label={L('توقيعي كمدير القسم', 'Sign as department head')} busy={signEval.isPending}
        onConfirm={() => signEval.mutate({ id: c.id }, { onSuccess: () => toast(L('سُجّل التوقيع', 'Signed')) })} /></div>}</>}
    </div>)}</div></Panel>}
    {data.evaluations.length > 0 && <Panel title={L('تقييم فترة تجربتي', 'My probation evaluation')}><div className="space-y-4">{data.evaluations.map(c => <div key={c.id}>
      <div className="flex flex-wrap items-center gap-2 mb-2"><span className="ltr inline-block text-ink-3">{c.ref_no}</span><CaseBadge c={c} /></div>
      <EvalView c={c} />
      {c.can?.sign && <div className="mt-3 space-y-2"><Field label={L('ملاحظتك (اختيارية)', 'Your note (optional)')}><input className="field" value={notes[c.id] ?? ''} onChange={e => setNotes({ ...notes, [c.id]: e.target.value })} /></Field>
        <ConfirmSend label={L('اطلعت ووقّعت', 'I have read and sign')} busy={signEval.isPending} onConfirm={() => signEval.mutate({ id: c.id, note: notes[c.id] }, { onSuccess: () => toast(L('سُجّل توقيعك', 'Your signature is recorded')) })} /></div>}
      <div className="mt-2 flex flex-wrap gap-3"><PrintLink href={`${base}/print/evaluation/${c.id}`}>{L('طباعة التقييم', 'Print the evaluation')}</PrintLink>
        {c.letters.length > 0 && <PrintLink href={`${base}/print/letter/${c.id}`}>{L('الخطاب', 'Letter')}</PrintLink>}</div>
    </div>)}</div></Panel>}
    {data.contracts.length === 0 ? <Panel><Empty title={L('لا عقود مسجلة', 'No contracts on record')} /></Panel> : data.contracts.map(c => {
      const mine = c.signatures.find(s => s.role === 'employee' && s.can)
      return <Panel key={c.id} title={<span className="flex flex-wrap items-center gap-2"><span className="ltr">{c.ref_no}</span><ContractBadge s={c.status} /></span>}
        sub={<>{c.type ? bi(c.type, lang) : ''} · <span className="num">{fmtDate(c.starts_on, lang)} — {c.ends_on ? fmtDate(c.ends_on, lang) : L('غير محدد المدة', 'open-ended')}</span></>}>
        {c.status === 'active' && c.ends_on && <div className="text-[13px] mb-2">{L('ينتهي', 'Ends')} <Left d={Math.round((new Date(c.ends_on).getTime() - Date.now()) / 864e5)} /></div>}
        {c.probation_ends_on && <div className="text-[13px] mb-2">{L('نهاية فترة التجربة', 'Probation ends')}: <span className="num">{fmtDate(c.probation_ends_on, lang)}</span></div>}
        {c.body && mine && <pre className="whitespace-pre-wrap font-[inherit] text-[13.5px] bg-sub rounded-xl p-3 mb-3 max-h-[320px] overflow-y-auto">{c.body}</pre>}
        <ContractSigs c={c} />
        {mine && <div className="mt-3"><Badge tone="brass">{L('بانتظار توقيعك', 'Awaiting your signature')}</Badge><div className="mt-2 max-w-[460px]"><SignContract busy={sign.isPending} onSign={sig => sign.mutate({ id: c.id, signature: sig }, { onSuccess: () => toast(L('وُقّع العقد', 'Contract signed')) })} /></div></div>}
        <div className="mt-3"><PrintLink href={`${base}/print/contract/${c.id}`}>{L('طباعة العقد', 'Print the contract')}</PrintLink></div>
      </Panel>
    })}
  </div>
}
