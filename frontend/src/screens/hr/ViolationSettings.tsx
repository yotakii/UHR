import { useState } from 'react'
import { Plus, RotateCcw, Trash2 } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { can, useMe } from '../../lib/session'
import { useCatalog, useResetCatalog, useSaveType, type Opt, type PenaltyKind, type TypeInput, type ViolationType } from '../../lib/discipline'
import { Badge, Btn, Empty, Field, Modal, PageHead, Panel, Seg, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'

const AUTO: Record<string, [string, string]> = { late15: ['تأخر حتى 15 د', 'Late ≤ 15 min'], late30: ['تأخر 16–30 د', 'Late 16–30 min'], late60: ['تأخر 31–60 د', 'Late 31–60 min'],
  late_over: ['تأخر أكثر من ساعة', 'Late > 1 h'], absent: ['غياب غير مبرر', 'Unjustified absence'], early: ['خروج مبكر', 'Early leave'] }

/**
 * دليل المخالفات (M16، SRS §10.7): يُبذر من «جدول المخالفات» للشركة — لكل مخالفة جزاء أول/ثاني/ثالث/رابع مرة ومرجع المادة،
 * وربط اختياري بالاقتراح الآلي من الحضور. التعديل لمن يملك «الإعدادات/تعديل»؛ البند المستخدم يُعطَّل ولا يُحذف.
 */
export function ViolationSettings() {
  const L = useL(); const lang = useLang()
  const user = useMe().data
  const edit = !!user && can(user, 'settings', 'edit')
  const q = useCatalog(); const d = q.data
  const [form, setForm] = useState<ViolationType | 'new' | null>(null)
  const [reset, setReset] = useState(false)
  const r = useResetCatalog()
  return <div className="max-w-[1200px] mx-auto space-y-4">
    <PageHead title={L('دليل المخالفات', 'Violations catalogue')} sub={L('الجزاء يتصاعد مع التكرار خلال مدة التكرار في سياسة المخالفات.', 'The penalty rises with each repeat within the policy’s repeat period.')}
      actions={edit && <div className="flex flex-wrap gap-2"><Btn icon={<RotateCcw size={15} />} onClick={() => setReset(true)}>{L('استعادة الافتراضي', 'Reset to defaults')}</Btn>
        <Btn kind="primary" icon={<Plus size={16} />} onClick={() => setForm('new')}>{L('إضافة مخالفة', 'Add violation')}</Btn></div>} />
    {!d ? <Panel><Empty title={q.isError ? L('تعذّر التحميل', 'Could not load') : L('جارٍ التحميل…', 'Loading…')} /></Panel> : d.groups.map(g => {
      const rows = d.data.filter(t => t.group === g.key)
      return <Panel key={g.key} title={lang === 'ar' ? g.ar : g.en} sub={L(`${rows.filter(t => t.active).length} مخالفة فعّالة`, `${rows.filter(t => t.active).length} active`)} pad={false}>
        <table className="tbl"><thead><tr><th className="w-10 hidden sm:table-cell">#</th><th>{L('المخالفة', 'Violation')}</th><th className="hidden md:table-cell">{L('الجزاء حسب التكرار', 'Penalty by repeat')}</th><th className="hidden sm:table-cell">{L('من الحضور', 'Attendance')}</th>{edit && <th />}</tr></thead>
          <tbody>{rows.map(t => <tr key={t.id} className={t.active ? '' : 'opacity-55'}>
            <td className="num hidden sm:table-cell">{t.number}</td>
            <td><div className="text-[13.5px]"><span className="sm:hidden num">{t.number}. </span>{bi(t.title, lang)}</div><div className="text-[12px] text-ink-3">{t.article ? <>{L('المادة', 'Art.')} <bdi>{t.article}</bdi></> : ''}{!t.active && <> · {L('معطّلة', 'disabled')}</>}{t.used ? <> · {L(`${t.used} حالة`, `${t.used} case(s)`)}</> : null}</div>
              <div className="md:hidden mt-1 flex flex-wrap gap-1">{t.penalties.map((p, i) => <span key={i} className="rounded-md bg-sub px-2 py-0.5 text-[12px] font-semibold text-ink-2">{i + 1}. {bi(p.label, lang)}</span>)}</div></td>
            <td className="hidden md:table-cell"><div className="flex flex-wrap gap-1">{t.penalties.map((p, i) => <Badge key={i} tone={p.kind === 'dismissal' ? 'danger' : p.kind === 'deduct' ? 'warn' : 'neutral'}>{i + 1}. {bi(p.label, lang)}</Badge>)}</div></td>
            <td className="hidden sm:table-cell text-[12.5px] text-info">{t.auto ? L(...AUTO[t.auto]) : ''}</td>
            {edit && <td className="text-end"><Btn size="sm" kind="ghost" onClick={() => setForm(t)}>{L('تعديل', 'Edit')}</Btn></td>}
          </tr>)}</tbody></table></Panel>
    })}
    {d && form && <TypeForm t={form === 'new' ? null : form} groups={d.groups} kinds={d.kinds} onClose={() => setForm(null)} />}
    <DecisionDialog open={reset} onClose={() => setReset(false)} tone="danger" title={L('استعادة الدليل الافتراضي', 'Reset the catalogue')}
      subject={L('تُحذف البنود غير المستخدمة، وتُعطَّل المستخدمة في حالات سابقة، ثم يُضاف جدول المخالفات الافتراضي للشركة.', 'Unused items are deleted, used ones are disabled, then the company’s default table is added.')}
      confirmLabel={L('استعادة', 'Reset')} onConfirm={() => r.mutateAsync().then(() => toast(L('استُعيد الدليل الافتراضي', 'Catalogue reset')))} />
  </div>
}

function TypeForm({ t, groups, kinds, onClose }: { t: ViolationType | null; groups: Opt[]; kinds: Opt[]; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const save = useSaveType(t?.id ?? null)
  const [v, setV] = useState<TypeInput>(t ? { group: t.group, title_ar: t.title.ar, title_en: t.title.en, article: t.article ?? '', auto: t.auto ?? '', active: t.active, penalties: t.penalties.map(p => ({ kind: p.kind, days: p.days })) }
    : { group: groups[0]?.key ?? 'time', title_ar: '', title_en: '', article: '', auto: '', active: true, penalties: [{ kind: 'warning' }] })
  const err = save.error instanceof ApiError ? save.error : null
  const setP = (i: number, p: Partial<TypeInput['penalties'][number]>) => setV({ ...v, penalties: v.penalties.map((x, j) => j === i ? { ...x, ...p } : x) })
  return <Modal open onClose={onClose} title={t ? L('تعديل مخالفة', 'Edit violation') : L('إضافة مخالفة', 'Add violation')} width={680} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => { toast(L('حُفظ', 'Saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('المجموعة', 'Group')} req><select className="field" value={v.group} onChange={e => setV({ ...v, group: e.target.value })}>{groups.map(g => <option key={g.key} value={g.key}>{lang === 'ar' ? g.ar : g.en}</option>)}</select></Field>
      <Field label={L('مرجع المادة', 'Legal article')}><input className="field" maxLength={40} value={v.article} onChange={e => setV({ ...v, article: e.target.value })} /></Field>
      <Field label={L('نص المخالفة', 'Violation text')} req error={err?.field('title_ar')} className="sm:col-span-2"><textarea className="field" rows={2} value={v.title_ar} onChange={e => setV({ ...v, title_ar: e.target.value })} /></Field>
      <Field label={L('النص بالإنكليزية', 'English text')} req error={err?.field('title_en')} className="sm:col-span-2"><textarea className="field ltr" rows={2} value={v.title_en} onChange={e => setV({ ...v, title_en: e.target.value })} /></Field>
      <Field label={L('اقتراح آلي من الحضور', 'Suggest from attendance')} hint={L('بند واحد لكل نوع', 'One item per kind')}><select className="field" value={v.auto} onChange={e => setV({ ...v, auto: e.target.value })}>
        <option value="">—</option>{Object.entries(AUTO).map(([k, x]) => <option key={k} value={k}>{L(...x)}</option>)}</select></Field>
      <Field div label={L('الحالة', 'Status')}><Seg value={v.active ? 'on' : 'off'} onChange={x => setV({ ...v, active: x === 'on' })} options={[{ v: 'on', label: L('فعّالة', 'Active') }, { v: 'off', label: L('معطّلة', 'Disabled') }]} /></Field>
      <Field div label={L('الجزاء لكل مرة (يتصاعد)', 'Penalty per occurrence (ascending)')} className="sm:col-span-2" error={err?.field('penalties')}>
        <div className="space-y-2">{v.penalties.map((p, i) => <div key={i} className="flex flex-wrap items-center gap-2">
          <span className="num text-ink-3 w-5">{i + 1}.</span>
          <select className="field !w-auto flex-1 min-w-[180px]" value={p.kind} onChange={e => setP(i, { kind: e.target.value as PenaltyKind, days: e.target.value === 'deduct' ? (p.days || 0.25) : undefined })}>
            {kinds.map(k => <option key={k.key} value={k.key}>{lang === 'ar' ? k.ar : k.en}</option>)}</select>
          {p.kind === 'deduct' && <input type="number" step={0.25} min={0.25} max={30} className="field num !w-24" value={p.days ?? ''} onChange={e => setP(i, { days: e.target.value === '' ? '' : Number(e.target.value) })} aria-label={L('أيام', 'Days')} />}
          {v.penalties.length > 1 && <button className="p-1.5 rounded-lg hover:bg-sub text-ink-3" onClick={() => setV({ ...v, penalties: v.penalties.filter((_, j) => j !== i) })} aria-label={L('حذف', 'Remove')}><Trash2 size={15} /></button>}
        </div>)}
          {v.penalties.length < 6 && <Btn size="sm" icon={<Plus size={14} />} onClick={() => setV({ ...v, penalties: [...v.penalties, { ...v.penalties[v.penalties.length - 1] }] })}>{L('المرة التالية', 'Next occurrence')}</Btn>}</div></Field>
    </div>
    {err && !Object.keys(err.errors).length && <p role="alert" className="mt-3 text-[13px] text-danger">{err.message}</p>}
  </Modal>
}
