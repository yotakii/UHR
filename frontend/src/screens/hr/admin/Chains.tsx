import { useState } from 'react'
import { ArrowDown, ArrowUp, Check, Loader2, Pencil, Plus, Trash2, UserPlus, X } from 'lucide-react'
import { useL, useLang, bi } from '../../../lib/store'
import { can, useMe } from '../../../lib/session'
import { ApiError } from '../../../lib/api'
import { useChains, useSaveChain, type Chain, type ChainLevel } from '../../../lib/admin'
import { Btn, Modal, PageHead, toast } from '../../../ui/kit'

type Opt = { key: string; ar: string; en: string }
type Person = { id: number; ar: string; en: string; email: string; roles: string[] }

/** وصف قصير لكل نوع ومجموعته — لتبسيط الصفحة */
const INFO: Record<string, { group: 'staff' | 'hiring' | 'exit' | 'pay'; ar: string; en: string }> = {
  leave: { group: 'staff', ar: 'إجازات الموظفين بأنواعها', en: 'All employee leave types' },
  justification: { group: 'staff', ar: 'تبرير تأخير أو غياب أو يوم ناقص', en: 'Justifying lateness, absence or an incomplete day' },
  overtime: { group: 'staff', ar: 'تكليف موظف بعمل إضافي مسبقاً', en: 'Assigning overtime in advance' },
  service: { group: 'staff', ar: 'شهادات العمل والراتب وتحديث البيانات', en: 'Employment & salary certificates, data updates' },
  advance: { group: 'pay', ar: 'سلفة على الراتب', en: 'Salary advance' },
  salary_access: { group: 'pay', ar: 'إذن الاطلاع على رواتب الآخرين', en: 'Access to other people’s salaries' },
  internal: { group: 'hiring', ar: 'تقدّم موظف لوظيفة داخلية', en: 'An employee applying for an internal job' },
  appointment: { group: 'hiring', ar: 'قرار تعيين موظف جديد', en: 'Decision to hire a new employee' },
  resignation: { group: 'exit', ar: 'طلب استقالة', en: 'Resignation request' },
  termination: { group: 'exit', ar: 'إنهاء عقد موظف', en: 'Ending an employee’s contract' },
  penalty: { group: 'exit', ar: 'الجزاءات الأشد (حسم كبير، ترفيع، فصل)', en: 'Higher penalties (large deduction, promotion, dismissal)' },
}

/** M16 «سلاسل الاعتماد» (مبسطة): بطاقة لكل نوع طلب بمساره، والتعديل بسؤال واحد لكل خطوة «مين بيعتمد؟» */
export function Chains() {
  const L = useL(); const lang = useLang()
  const q = useChains(); const d = q.data
  const editable = can(useMe().data, 'settings', 'edit')
  const [edit, setEdit] = useState<Chain | null>(null)
  if (!d) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const groups: [string, string][] = [['staff', L('طلبات الموظفين', 'Employee requests')], ['pay', L('الرواتب', 'Pay')], ['hiring', L('التوظيف والتعيين', 'Hiring')], ['exit', L('الخروج والانضباط', 'Exit & discipline')]]
  return <div className="max-w-[1100px] mx-auto space-y-6">
    <PageHead title={L('سلاسل الاعتماد', 'Approval chains')} sub={L('من يعتمد كل نوع من الطلبات، وبأي ترتيب. يسري التعديل على الطلبات الجديدة فقط.', 'Who approves each kind of request, in what order. Changes apply to new requests only.')} />
    {groups.map(([g, title]) => <section key={g}>
      <h2 className="text-[13px] font-semibold text-ink-3 mb-2">{title}</h2>
      <div className="grid [grid-template-columns:repeat(auto-fill,minmax(320px,1fr))] gap-3">{d.data.filter(c => (INFO[c.type]?.group ?? 'staff') === g).map(c =>
        <div key={c.type} className="rounded-2xl border border-line bg-surface p-4 flex flex-col gap-3">
          <div className="flex items-start gap-2"><div className="flex-1 min-w-0"><div className="font-semibold text-[14.5px]">{bi(c, lang)}</div>
            <div className="text-[12.5px] text-ink-3">{INFO[c.type] ? bi(INFO[c.type], lang) : ''}</div></div>
            {editable && <Btn size="sm" icon={<Pencil size={13} />} onClick={() => setEdit(c)}>{L('تعديل', 'Edit')}</Btn>}</div>
          <Path levels={c.levels} roles={d.roles} />
        </div>)}</div>
    </section>)}
    {edit && <ChainEditor chain={edit} roles={d.roles} users={d.users} onClose={() => setEdit(null)} />}
  </div>
}

/** المسار كشرائح: الطلب ← خطوة ← خطوة ← معتمد */
function Path({ levels, roles }: { levels: ChainLevel[]; roles: Opt[] }) {
  const L = useL(); const lang = useLang()
  const arrow = <span className="text-ink-3">{lang === 'ar' ? '←' : '→'}</span>
  return <div className="flex flex-wrap items-center gap-1.5 text-[12.5px]">
    <span className="text-ink-3">{L('الطلب', 'Request')}</span>
    {levels.map((x, i) => <span key={i} className="contents">{arrow}
      <span className="rounded-lg bg-brand-soft text-brand px-2 py-1 font-semibold">{x.user ? bi(x.user, lang) : bi(roles.find(r => r.key === x.role_key) ?? { ar: x.role_ar, en: x.role_en }, lang)}
        {x.deputy && <span className="font-normal text-ink-2"> · {L('نائبه', 'deputy')}: {bi(x.deputy, lang)}</span>}</span></span>)}
    {arrow}<span className="inline-flex items-center gap-1 text-brand"><Check size={13} />{L('معتمد', 'Approved')}</span>
  </div>
}

function ChainEditor({ chain, roles, users, onClose }: { chain: Chain; roles: Opt[]; users: Person[]; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const save = useSaveChain()
  const [lv, setLv] = useState<ChainLevel[]>(chain.levels)
  const [deputyOpen, setDeputyOpen] = useState<Record<number, boolean>>({})
  const err = save.error instanceof ApiError ? save.error : null
  const roleOpts = roles.filter(r => chain.manager_allowed || r.key !== 'manager')
  const levelRoles = roles.map(r => r.key).filter(k => k !== 'manager')
  const valueOf = (x: ChainLevel) => x.user_id ? `user:${x.user_id}` : `role:${x.role_key}`
  const pick = (i: number, v: string) => {
    const [kind, id] = v.split(':')
    let next: ChainLevel
    if (kind === 'role') { const r = roles.find(r => r.key === id)!; next = { ...lv[i], role_key: r.key, role_ar: r.ar, role_en: r.en, user_id: null } }
    else { const u = users.find(u => u.id === Number(id))!; const rk = levelRoles.find(k => u.roles.includes(k)) ?? 'executive'
      next = { ...lv[i], role_key: rk, role_ar: u.ar, role_en: u.en, user_id: u.id } }
    setLv(lv.map((x, j) => j === i ? next : x))
  }
  const move = (i: number, by: number) => { const n = [...lv]; [n[i], n[i + by]] = [n[i + by], n[i]]; setLv(n) }
  const add = () => { const r = roleOpts.find(r => r.key === 'hr_director') ?? roleOpts[0]; setLv([...lv, { role_key: r.key, role_ar: r.ar, role_en: r.en, user_id: null, deputy_id: null }]) }
  const firstErr = err && (Object.values(err.errors)[0]?.[0] ?? err.message)
  return <Modal open onClose={onClose} width={640} title={bi(chain, lang)}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending || !lv.length}
      onClick={() => save.mutate({ type: chain.type, levels: lv }, { onSuccess: () => { toast(L('حُفظ المسار', 'Path saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <p className="text-[13px] text-ink-3 mb-4">{INFO[chain.type] ? bi(INFO[chain.type], lang) : ''} — {L('الطلب يمر على الخطوات بالترتيب.', 'The request goes through the steps in order.')}</p>
    <ol className="space-y-2.5">{lv.map((x, i) => <li key={i} className="rounded-xl border border-line p-3">
      <div className="flex items-center gap-2">
        <span className="size-7 rounded-full bg-brand text-on-brand grid place-items-center text-[12.5px] font-bold num shrink-0">{i + 1}</span>
        <label className="flex-1 min-w-0"><span className="block text-[12px] text-ink-3 mb-1">{L('من يعتمد؟', 'Who approves?')}</span>
          <select className="field" value={valueOf(x)} onChange={e => pick(i, e.target.value)}>
            <optgroup label={L('حسب الدور', 'By role')}>{roleOpts.map(r => <option key={r.key} value={`role:${r.key}`}>{r.key === 'manager' ? bi(r, lang) : L(`أي ${r.ar}`, `Any ${r.en}`)}</option>)}</optgroup>
            <optgroup label={L('شخص محدد', 'A specific person')}>{users.map(u => <option key={u.id} value={`user:${u.id}`}>{bi(u, lang)}</option>)}</optgroup>
          </select></label>
        <div className="flex flex-col sm:flex-row gap-0.5 self-end pb-1">
          <button className="p-1.5 rounded hover:bg-sub text-ink-3 disabled:opacity-30" disabled={i === 0} onClick={() => move(i, -1)} aria-label={L('للأعلى', 'Up')}><ArrowUp size={15} /></button>
          <button className="p-1.5 rounded hover:bg-sub text-ink-3 disabled:opacity-30" disabled={i === lv.length - 1} onClick={() => move(i, 1)} aria-label={L('للأسفل', 'Down')}><ArrowDown size={15} /></button>
          <button className="p-1.5 rounded hover:bg-sub text-ink-3 hover:text-danger disabled:opacity-30" disabled={lv.length < 2} onClick={() => setLv(lv.filter((_, j) => j !== i))} aria-label={L('حذف الخطوة', 'Remove step')}><Trash2 size={15} /></button>
        </div>
      </div>
      {x.deputy_id || deputyOpen[i] ? <div className="mt-2 ms-9 flex items-center gap-2">
        <span className="text-[12.5px] text-ink-3 shrink-0">{L('النائب (يعتمد بدلاً منه)', 'Deputy (can approve instead)')}</span>
        <select className="field !h-9 flex-1" value={x.deputy_id ?? ''} onChange={e => setLv(lv.map((y, j) => j === i ? { ...y, deputy_id: e.target.value ? Number(e.target.value) : null } : y))}>
          <option value="">—</option>{users.filter(u => u.id !== x.user_id).map(u => <option key={u.id} value={u.id}>{bi(u, lang)}</option>)}</select>
        <button className="p-1 text-ink-3 hover:text-danger" onClick={() => { setLv(lv.map((y, j) => j === i ? { ...y, deputy_id: null } : y)); setDeputyOpen({ ...deputyOpen, [i]: false }) }} aria-label={L('بلا نائب', 'No deputy')}><X size={15} /></button>
      </div> : <button className="mt-2 ms-9 inline-flex items-center gap-1 text-[12.5px] text-brand font-semibold" onClick={() => setDeputyOpen({ ...deputyOpen, [i]: true })}><UserPlus size={13} />{L('إضافة نائب', 'Add a deputy')}</button>}
    </li>)}</ol>
    {lv.length < 6 && <Btn size="sm" className="mt-3" icon={<Plus size={14} />} onClick={add}>{L('إضافة خطوة', 'Add a step')}</Btn>}
    <div className="mt-4 rounded-xl bg-sub p-3"><div className="text-[12px] text-ink-3 mb-1.5">{L('المسار', 'Path')}</div>
      <Path levels={lv.map(x => ({ ...x, user: x.user_id ? users.find(u => u.id === x.user_id) ?? null : null, deputy: x.deputy_id ? users.find(u => u.id === x.deputy_id) ?? null : null }))} roles={roles} /></div>
    {firstErr && <div role="alert" className="mt-3 rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{firstErr}</div>}
  </Modal>
}
