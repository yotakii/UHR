import { useEffect, useState } from 'react'
import { ArrowDown, ArrowUp, Loader2, Plus, Save, Trash2 } from 'lucide-react'
import { useL, useLang, bi } from '../../../lib/store'
import { can, useMe } from '../../../lib/session'
import { ApiError } from '../../../lib/api'
import { useChains, useSaveChain, type Chain, type ChainLevel } from '../../../lib/admin'
import { Btn, Field, PageHead, Panel, toast } from '../../../ui/kit'

/** M16 «سلاسل الاعتماد»: لكل نوع طلب مستوياته بالترتيب — الدور، صاحب محدد اختياري، ونائب يعتمد بدلاً منه. تسري على الطلبات الجديدة. */
export function Chains() {
  const L = useL()
  const q = useChains(); const d = q.data
  if (!d) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  return <div className="max-w-[1100px] mx-auto space-y-5">
    <PageHead title={L('سلاسل الاعتماد', 'Approval chains')} sub={L('من يعتمد كل نوع طلب وبأي ترتيب. النائب يعتمد بدلاً من صاحب المستوى ويصله الطلب أيضاً. التعديل يسري على الطلبات الجديدة فقط.', 'Who approves each request type and in what order. The deputy can approve in the owner’s place and also receives it. Changes apply to new requests only.')} />
    {d.data.map(c => <ChainCard key={c.type} chain={c} roles={d.roles} users={d.users} />)}
  </div>
}

function ChainCard({ chain, roles, users }: { chain: Chain; roles: { key: string; ar: string; en: string }[]; users: { id: number; ar: string; en: string; email: string; roles: string[] }[] }) {
  const L = useL(); const lang = useLang()
  const editable = can(useMe().data, 'settings', 'edit')
  const save = useSaveChain()
  const [lv, setLv] = useState<ChainLevel[]>(chain.levels)
  useEffect(() => setLv(chain.levels), [chain])
  const err = save.error instanceof ApiError ? save.error : null
  const dirty = JSON.stringify(lv.map(({ user, deputy, ...x }) => x)) !== JSON.stringify(chain.levels.map(({ user, deputy, ...x }) => x))
  const opts = roles.filter(r => chain.manager_allowed || r.key !== 'manager')
  const upd = (i: number, p: Partial<ChainLevel>) => setLv(lv.map((x, j) => j === i ? { ...x, ...p } : x))
  const move = (i: number, by: number) => { const n = [...lv]; [n[i], n[i + by]] = [n[i + by], n[i]]; setLv(n) }
  return <Panel title={bi(chain, lang)} action={editable && dirty && <Btn size="sm" kind="primary" icon={save.isPending ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />} disabled={save.isPending}
    onClick={() => save.mutate({ type: chain.type, levels: lv }, { onSuccess: () => toast(L('حُفظت السلسلة', 'Chain saved')), onError: x => toast((x as Error).message) })}>{L('حفظ', 'Save')}</Btn>}>
    <ol className="space-y-2">{lv.map((x, i) => <li key={i} className="rounded-xl border border-line p-3">
      <div className="flex flex-wrap items-end gap-3">
        <span className="size-7 rounded-full bg-brand-soft text-brand grid place-items-center text-[12px] font-bold num shrink-0 mb-1">{i + 1}</span>
        <Field label={L('الدور', 'Role')} className="min-w-[150px] flex-1" error={err?.field(`levels.${i}.role_key`)}>
          <select className="field" disabled={!editable} value={x.role_key} onChange={e => { const r = roles.find(r => r.key === e.target.value)!; upd(i, { role_key: r.key, role_ar: r.ar, role_en: r.en, user_id: r.key === 'manager' ? null : x.user_id }) }}>
            {opts.map(r => <option key={r.key} value={r.key}>{bi(r, lang)}</option>)}</select></Field>
        <Field label={L('اسم المستوى', 'Level name')} className="min-w-[150px] flex-1" error={err?.field(`levels.${i}.role_ar`)}>
          <input className="field" disabled={!editable} value={lang === 'ar' ? x.role_ar : x.role_en} onChange={e => upd(i, lang === 'ar' ? { role_ar: e.target.value } : { role_en: e.target.value })} /></Field>
        {x.role_key !== 'manager' && <Field label={L('صاحب محدد (اختياري)', 'Specific owner (optional)')} className="min-w-[170px] flex-1" error={err?.field(`levels.${i}.user_id`)}>
          <select className="field" disabled={!editable} value={x.user_id ?? ''} onChange={e => upd(i, { user_id: e.target.value ? Number(e.target.value) : null })}>
            <option value="">{L('أي حامل للدور', 'Any role holder')}</option>{users.filter(u => u.roles.includes(x.role_key)).map(u => <option key={u.id} value={u.id}>{bi(u, lang)}</option>)}</select></Field>}
        <Field label={L('النائب', 'Deputy')} className="min-w-[170px] flex-1" error={err?.field(`levels.${i}.deputy_id`)}>
          <select className="field" disabled={!editable} value={x.deputy_id ?? ''} onChange={e => upd(i, { deputy_id: e.target.value ? Number(e.target.value) : null })}>
            <option value="">—</option>{users.filter(u => u.id !== x.user_id).map(u => <option key={u.id} value={u.id}>{bi(u, lang)}</option>)}</select></Field>
        {editable && <div className="flex gap-1 mb-1">
          <button className="p-1.5 rounded hover:bg-sub text-ink-3 disabled:opacity-30" disabled={i === 0} onClick={() => move(i, -1)} aria-label={L('أعلى', 'Up')}><ArrowUp size={15} /></button>
          <button className="p-1.5 rounded hover:bg-sub text-ink-3 disabled:opacity-30" disabled={i === lv.length - 1} onClick={() => move(i, 1)} aria-label={L('أسفل', 'Down')}><ArrowDown size={15} /></button>
          <button className="p-1.5 rounded hover:bg-sub text-ink-3 hover:text-danger disabled:opacity-30" disabled={lv.length < 2} onClick={() => setLv(lv.filter((_, j) => j !== i))} aria-label={L('حذف', 'Remove')}><Trash2 size={15} /></button></div>}
      </div>
    </li>)}</ol>
    {editable && lv.length < 6 && <Btn size="sm" className="mt-3" icon={<Plus size={14} />} onClick={() => { const r = opts.find(r => r.key === 'hr_director') ?? opts[0]; setLv([...lv, { role_key: r.key, role_ar: r.ar, role_en: r.en, user_id: null, deputy_id: null }]) }}>{L('مستوى', 'Level')}</Btn>}
  </Panel>
}
