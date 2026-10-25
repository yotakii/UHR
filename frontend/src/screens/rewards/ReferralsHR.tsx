import { useState } from 'react'
import { Check, X, Plus, Pencil } from 'lucide-react'
import { useL, useLang, bi, fmtDateTime } from '../../lib/store'
import { setQuery, useRoute } from '../../lib/router'
import { ApiError } from '../../lib/api'
import { useDecideRedemption, useReferralsHr, useSaveReward, type Redemption, type Reward, type RewardInput, type RewardKind } from '../../lib/rewards'
import { Badge, Btn, Empty, Field, Modal, PageHead, Panel, Progress, Seg, Tabs, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'
import { Leaderboard, RedemptionBadge, ReferralBadge, rewardIcon, stagesText } from './parts'

type Tab = 'board' | 'links' | 'red' | 'store'

/** M12 — الإحالات والمكافآت للموارد البشرية (ReferralsHR في الديمو) + إدارة المتجر */
export function ReferralsHR() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const q = useReferralsHr(); const d = q.data
  const decide = useDecideRedemption()
  const tab = (['board', 'links', 'red', 'store'].includes(route.query.tab ?? '') ? route.query.tab : 'board') as Tab
  const [reject, setReject] = useState<Redemption | null>(null)
  const [edit, setEdit] = useState<Reward | 'new' | null>(null)
  if (!d) return <div className="max-w-[1200px] mx-auto"><PageHead title={L('الإحالات والمكافآت', 'Referrals & rewards')} /></div>
  const pend = d.redemptions.filter(r => r.status === 'pending')
  const approve = (r: Redemption) => decide.mutate({ id: r.id, approve: true }, { onSuccess: () => toast(L('اعتُمدت المكافأة', 'Reward approved')), onError: e => toast(e instanceof ApiError ? e.message : L('تعذّر الاعتماد', 'Could not approve')) })
  const funnel: [string, number][] = [[L('روابط مشاركة', 'Links shared'), d.funnel.shared], [L('تقدّموا عبر رابط', 'Applied via link'), d.funnel.applied], [L('عُيّنوا', 'Hired'), d.funnel.hired]]
  return <div className="max-w-[1200px] mx-auto">
    <PageHead title={L('الإحالات والمكافآت', 'Referrals & rewards')} sub={stagesText(d.stages, d.hard_to_fill_x, L)} />
    <Tabs value={tab} onChange={v => setQuery({ tab: v === 'board' ? undefined : v })} tabs={[{ v: 'board', label: L('لوحة المتصدرين', 'Leaderboard') }, { v: 'links', label: L('روابط الإحالة', 'Referral links'), count: d.links.length },
      { v: 'red', label: L('طلبات الاستبدال', 'Redemptions'), count: pend.length }, { v: 'store', label: L('المتجر', 'Store') }]} />
    <div className="mt-4">
      {tab === 'board' && <div className="grid lg:grid-cols-[1.3fr_1fr] gap-5"><Panel title={L('أكثر الموظفين استقطاباً للمواهب', 'Top talent advocates')}><Leaderboard rows={d.leaderboard} /></Panel>
        <Panel title={L('من الرابط إلى التعيين', 'From link to hire')}><div className="space-y-4">{funnel.map(([k, v], i) =>
          <div key={k}><div className="flex justify-between text-[13px] mb-1"><span>{k}</span><span className="font-bold num">{v}</span></div><Progress value={v / (funnel[0][1] || 1) * 100} tone={i === 2 ? 'brass' : 'brand'} /></div>)}
          <div className="flex justify-between text-[13px] border-t border-line-2 pt-3"><span>{L('نقاط مُنحت عبر الإحالات', 'Points awarded via referrals')}</span><span className="font-bold num text-brass">{d.funnel.points.toLocaleString('en-US')}</span></div></div></Panel></div>}
      {tab === 'links' && <Panel pad={false}>{d.links.length === 0 ? <Empty title={L('لا روابط إحالة بعد', 'No referral links yet')} /> : <table className="tbl"><thead><tr><th>{L('الموظف', 'Employee')}</th><th className="hidden md:table-cell">{L('الوظيفة', 'Role')}</th><th className="hidden lg:table-cell">{L('الرمز', 'Code')}</th><th className="hidden sm:table-cell">{L('زيارات', 'Visits')}</th><th className="hidden md:table-cell">{L('المرشح', 'Candidate')}</th><th>{L('الحالة', 'Status')}</th></tr></thead><tbody>
        {d.links.map(r => <tr key={r.id}><td><div className="font-medium">{r.employee ? bi(r.employee.name, lang) : '—'}</div><div className="md:hidden text-[12px] text-ink-3">{r.job ? bi(r.job.title, lang) : ''}</div>
          {r.candidate && <a className="md:hidden text-[12px] text-brand" href={`/hr/candidates/${r.candidate.id}`}>{bi(r.candidate.name, lang)}</a>}</td>
          <td className="hidden md:table-cell">{r.job ? bi(r.job.title, lang) : '—'}{r.job?.hard_to_fill && <span className="ms-1"><Badge tone="brass">×</Badge></span>}</td><td className="hidden lg:table-cell ltr num text-[13px]">{r.code}</td><td className="hidden sm:table-cell num">{r.clicks}</td>
          <td className="hidden md:table-cell">{r.candidate ? <a className="text-brand hover:underline" href={`/hr/candidates/${r.candidate.id}`}>{bi(r.candidate.name, lang)}</a> : '—'}</td><td><ReferralBadge s={r.status} awarded={r.awarded} /></td></tr>)}</tbody></table>}</Panel>}
      {tab === 'red' && <Panel pad={false}>{d.redemptions.length === 0 ? <Empty title={L('لا توجد طلبات استبدال', 'No redemptions')} /> : <table className="tbl"><thead><tr><th>{L('الموظف', 'Employee')}</th><th className="hidden sm:table-cell">{L('المكافأة', 'Reward')}</th><th className="hidden md:table-cell">{L('النقاط', 'Points')}</th><th className="hidden lg:table-cell">{L('التاريخ', 'Date')}</th><th>{L('الحالة', 'Status')}</th><th /></tr></thead><tbody>
        {d.redemptions.map(r => <tr key={r.id}><td><div className="font-medium">{r.employee ? bi(r.employee.name, lang) : '—'}</div><div className="sm:hidden text-[12px]">{r.reward ? bi(r.reward.title, lang) : ''} · <span className="num">{r.cost}</span></div></td>
          <td className="hidden sm:table-cell">{r.reward ? bi(r.reward.title, lang) : '—'}</td><td className="hidden md:table-cell num">{r.cost}</td><td className="hidden lg:table-cell num">{fmtDateTime(r.at, lang)}</td>
          <td><RedemptionBadge s={r.status} />{r.note && <div className="text-[12px] text-ink-3 mt-0.5 max-w-[240px]">{r.note}</div>}{r.decided_by && <div className="text-[11.5px] text-ink-3">{r.decided_by}</div>}</td>
          <td>{r.status === 'pending' && d.can.edit && <div className="flex flex-wrap gap-1.5 justify-end"><Btn size="sm" kind="primary" icon={<Check size={14} />} disabled={decide.isPending} onClick={() => approve(r)}>{L('اعتماد', 'Approve')}</Btn><Btn size="sm" icon={<X size={14} />} onClick={() => setReject(r)}>{L('رفض', 'Decline')}</Btn></div>}</td></tr>)}</tbody></table>}</Panel>}
      {tab === 'store' && <>
        {d.can.edit && <div className="flex justify-end mb-3"><Btn kind="primary" icon={<Plus size={16} />} onClick={() => setEdit('new')}>{L('مكافأة جديدة', 'New reward')}</Btn></div>}
        <div className="grid [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))] gap-4">{d.rewards.map(w => <div key={w.id} className={`rounded-2xl border border-line bg-surface p-5 ${w.active ? '' : 'opacity-60'}`}>
          <div className="flex items-start justify-between gap-2"><div className="size-12 rounded-xl grid place-items-center bg-brand-soft text-brand border border-brand/25">{rewardIcon[w.kind]}</div>
            {d.can.edit && <button className="text-ink-3 hover:text-brand p-1" aria-label={L('تعديل', 'Edit')} onClick={() => setEdit(w)}><Pencil size={15} /></button>}</div>
          <div className="mt-3 font-display font-bold">{bi(w.title, lang)}</div><div className="mt-1 flex justify-between text-[13px]"><span className="text-brass font-bold num">{w.cost} {L('نقطة', 'pts')}</span><span className="text-ink-3">{L('المخزون', 'Stock')}: <span className="num">{w.stock}</span></span></div>
          {!w.active && <div className="mt-2"><Badge>{L('موقوفة', 'Inactive')}</Badge></div>}</div>)}</div>
      </>}
    </div>
    {reject && <DecisionDialog open onClose={() => setReject(null)} tone="danger" reasonRequired minLength={d.reject_min} title={L('رفض طلب الاستبدال', 'Decline the redemption')}
      subject={<>{reject.employee ? bi(reject.employee.name, lang) : ''} — {reject.reward ? bi(reject.reward.title, lang) : ''} (<span className="num">{reject.cost}</span> {L('نقطة تُعاد', 'points refunded')})</>}
      confirmLabel={L('رفض وإعادة النقاط', 'Decline and refund')} onConfirm={note => decide.mutateAsync({ id: reject.id, approve: false, note }).then(() => toast(L('رُفض الطلب وأُعيدت النقاط', 'Declined — points refunded')))} />}
    {edit && <RewardForm reward={edit === 'new' ? null : edit} kinds={d.kinds} onClose={() => setEdit(null)} />}
  </div>
}

function RewardForm({ reward, kinds, onClose }: { reward: Reward | null; kinds: RewardKind[]; onClose: () => void }) {
  const L = useL()
  const save = useSaveReward(reward?.id ?? null)
  const [v, setV] = useState<RewardInput>(reward ? { title_ar: reward.title.ar, title_en: reward.title.en, cost: reward.cost, stock: reward.stock, kind: reward.kind, active: reward.active } : { title_ar: '', title_en: '', cost: '', stock: '', kind: 'gift', active: true })
  const errs = save.error instanceof ApiError ? save.error.errors ?? {} : {}
  const set = <K extends keyof RewardInput>(k: K, x: RewardInput[K]) => setV({ ...v, [k]: x })
  const kindName: Record<RewardKind, string> = { leave: L('إجازة', 'Leave'), food: L('طعام', 'Food'), gym: L('رياضة', 'Gym'), course: L('دورة', 'Course'), parking: L('موقف', 'Parking'), gift: L('هدية', 'Gift') }
  return <Modal open onClose={onClose} title={reward ? L('تعديل مكافأة', 'Edit reward') : L('مكافأة جديدة', 'New reward')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => { toast(L('حُفظت المكافأة', 'Reward saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field req label={L('الاسم بالعربية', 'Arabic name')} error={errs.title_ar?.[0]}><input className="field" value={v.title_ar} onChange={e => set('title_ar', e.target.value)} /></Field>
      <Field req label={L('الاسم بالإنكليزية', 'English name')} error={errs.title_en?.[0]}><input className="field ltr" value={v.title_en} onChange={e => set('title_en', e.target.value)} /></Field>
      <Field req label={L('النقاط', 'Points')} error={errs.cost?.[0]}><input className="field num" type="number" min={1} value={v.cost} onChange={e => set('cost', e.target.value === '' ? '' : Number(e.target.value))} /></Field>
      <Field req label={L('المخزون', 'Stock')} error={errs.stock?.[0]}><input className="field num" type="number" min={0} value={v.stock} onChange={e => set('stock', e.target.value === '' ? '' : Number(e.target.value))} /></Field>
    </div>
    <Field div label={L('النوع', 'Kind')} className="mt-3"><div className="flex flex-wrap gap-2">{kinds.map(k => <button key={k} type="button" onClick={() => set('kind', k)}
      className={`flex items-center gap-2 rounded-xl border px-3 h-10 text-[13px] ${v.kind === k ? 'border-brand bg-brand-soft text-brand font-semibold' : 'border-line'}`}><span className="[&_svg]:size-4">{rewardIcon[k]}</span>{kindName[k]}</button>)}</div></Field>
    <Field div label={L('الحالة', 'Status')} className="mt-3"><Seg value={v.active ? 'on' : 'off'} onChange={x => set('active', x === 'on')} options={[{ v: 'on', label: L('متاحة في المتجر', 'Available') }, { v: 'off', label: L('موقوفة', 'Inactive') }]} /></Field>
    {save.error && !Object.keys(errs).length && <p className="text-danger text-[13px] mt-2">{save.error.message}</p>}
  </Modal>
}
