import { useEffect, useMemo, useState } from 'react'
import { Loader2, RotateCcw, Save, ShieldCheck, Users } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { can, type SessionUser } from '../../lib/session'
import { usePermGrid, useSavePerms, type Matrix } from '../../lib/console'
import type { ApiError } from '../../lib/api'
import { Badge, Btn, Modal, PageHead, Panel, toast } from '../../ui/kit'
import { PermissionGrid, letters } from '../../ui/permgrid'

/**
 * لوحة الصلاحيات (M16 — SRS §10.2): اختيار الدور ← شبكة الوحدات × الإجراءات + النطاق، ثم الحفظ بتأكيد يعرض التغييرات.
 * التعديل فوري على الخادم ومسجّل في التدقيق؛ والمصفوفة الكاملة للمقارنة تحتها.
 */
export function Permissions({ user }: { user: SessionUser }) {
  const L = useL(); const lang = useLang()
  const { data: grid, isLoading } = usePermGrid()
  const save = useSavePerms()
  const editable = can(user, 'users', 'edit')
  const [role, setRole] = useState('employee')
  const [draft, setDraft] = useState<Matrix | null>(null)
  const [confirm, setConfirm] = useState(false)
  useEffect(() => { if (grid && !draft) setDraft(structuredClone(grid.matrix)) }, [grid, draft])

  const changes = useMemo(() => {
    if (!grid || !draft) return []
    return Object.entries(draft).flatMap(([r, mods]) => Object.entries(mods).filter(([m, c]) => JSON.stringify(c) !== JSON.stringify(grid.matrix[r][m])).map(([m, c]) => ({ role: r, module: m, before: grid.matrix[r][m], after: c })))
  }, [grid, draft])

  if (isLoading || !grid || !draft) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const roleOf = (k: string) => grid.roles.find(r => r.key === k)!
  const modOf = (k: string) => grid.modules.find(m => m.key === k)!
  const changedRoles = new Set(changes.map(c => c.role))

  const submit = () => save.mutate(Object.fromEntries([...changedRoles].map(r => [r, draft[r]])), {
    onSuccess: r => { setConfirm(false); setDraft(structuredClone(r.matrix)); toast(L(`حُفظت الصلاحيات — ${r.changed} تعديل`, `Permissions saved — ${r.changed} changes`)) },
    onError: e => { setConfirm(false); toast((e as ApiError).message) },
  })

  return <div className="max-w-[1200px] mx-auto space-y-5">
    <PageHead title={L('الصلاحيات', 'Permissions')} sub={L('لكل دور: ما يراه وما يفعله في كل وحدة، وعلى أي نطاق. التعديل يُطبَّق فوراً ويُسجَّل في التدقيق.', 'For each role: what it sees and does in every module, and on which scope. Changes apply immediately and are audited.')}
      actions={editable && <>
        <Btn icon={<RotateCcw size={16} />} disabled={!changes.length} onClick={() => setDraft(structuredClone(grid.matrix))}>{L('تراجع', 'Discard')}</Btn>
        <Btn kind="primary" icon={<Save size={16} />} disabled={!changes.length} onClick={() => setConfirm(true)}>{L('حفظ', 'Save')}{changes.length ? <span className="num">({changes.length})</span> : null}</Btn>
      </>} />

    <div className="flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label={L('الأدوار', 'Roles')}>
      {grid.roles.map(r => <button key={r.key} role="tab" aria-selected={role === r.key} onClick={() => setRole(r.key)}
        className={`shrink-0 h-10 px-3.5 rounded-xl border text-[13px] font-semibold flex items-center gap-2 ${role === r.key ? 'bg-brand text-on-brand border-brand' : 'bg-surface border-line hover:border-brand/50'}`}>
        {bi(r, lang)}<span className={`text-[11px] font-bold num rounded-full px-1.5 ${role === r.key ? 'bg-white/20' : 'bg-sub text-ink-3'}`}>{r.users}</span>
        {changedRoles.has(r.key) && <span className="size-1.5 rounded-full bg-brass" />}
      </button>)}
    </div>

    <Panel title={<span className="flex items-center gap-2"><ShieldCheck size={17} className="text-brand" />{bi(roleOf(role), lang)}</span>}
      sub={<span className="flex items-center gap-1.5"><Users size={13} />{L(`${roleOf(role).users} مستخدمين بهذا الدور`, `${roleOf(role).users} users with this role`)}</span>}
      action={!editable && <Badge>{L('عرض فقط', 'Read only')}</Badge>} pad={false}>
      <PermissionGrid grid={grid} cells={draft[role]} base={grid.matrix[role]} readOnly={!editable} onChange={(m, c) => setDraft(d => ({ ...d!, [role]: { ...d![role], [m]: c } }))} />
    </Panel>

    <Panel title={L('المصفوفة كاملة', 'Full matrix')} sub={L('ع عرض · ض إضافة · ت تعديل · ق اعتماد/رفض · ص تصدير · ط طباعة · ح أرشفة — انقر عمود دور لتعديله', 'ع view · ض create · ت edit · ق approve · ص export · ط print · ح archive — click a role column to edit it')} pad={false}>
      <div className="scroll-x"><table className="tbl min-w-[980px] text-[12.5px]">
        <thead><tr><th>{L('الوحدة', 'Module')}</th>{grid.roles.map(r => <th key={r.key} className="text-center"><button onClick={() => setRole(r.key)} className={`font-semibold whitespace-nowrap ${role === r.key ? 'text-brand' : ''}`}>{bi(r, lang)}</button></th>)}</tr></thead>
        <tbody>{grid.modules.map(m => <tr key={m.key}><td className="font-medium whitespace-nowrap">{bi(m, lang)}</td>
          {grid.roles.map(r => { const c = draft[r.key][m.key]
            return <td key={r.key} className={`text-center whitespace-nowrap ${role === r.key ? 'bg-brand-soft/50' : ''}`}>{c.actions.length ? <><span className="font-semibold">{letters(grid, c)}</span>{c.scope !== 'all' && <span className="block text-[10.5px] text-ink-3">{bi(grid.scopes.find(s => s.key === c.scope), lang)}</span>}</> : <span className="text-ink-3">—</span>}</td> })}
        </tr>)}</tbody>
      </table></div>
    </Panel>

    <Modal open={confirm} onClose={() => setConfirm(false)} title={L('تأكيد تعديل الصلاحيات', 'Confirm permission changes')} width={620}
      footer={<><Btn onClick={() => setConfirm(false)}>{L('رجوع', 'Back')}</Btn><Btn kind="primary" disabled={save.isPending} icon={save.isPending ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />} onClick={submit}>{L('تأكيد الحفظ', 'Confirm and save')}</Btn></>}>
      <p className="text-[13.5px] text-ink-2 mb-3">{L('ستُطبَّق هذه التعديلات فوراً على كل مستخدمي الأدوار التالية:', 'These changes apply immediately to every user with these roles:')}</p>
      <ul className="divide-y divide-line-2 border border-line rounded-xl max-h-[50vh] overflow-y-auto">{changes.map(c => <li key={c.role + c.module} className="px-3 py-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px]">
        <span className="font-semibold">{bi(roleOf(c.role), lang)}</span><span className="text-ink-3">·</span><span>{bi(modOf(c.module), lang)}</span>
        <span className="ms-auto flex items-center gap-2 num"><span className="text-ink-3 line-through">{letters(grid, c.before) || '—'}</span><span>{lang === 'ar' ? '←' : '→'}</span><span className="font-bold text-brand">{letters(grid, c.after) || '—'}</span>{c.after.actions.length > 0 && c.after.scope !== c.before.scope && <Badge tone="info">{bi(grid.scopes.find(s => s.key === c.after.scope), lang)}</Badge>}</span>
      </li>)}</ul>
    </Modal>
  </div>
}
