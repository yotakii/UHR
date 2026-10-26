import { useEffect, useMemo, useState } from 'react'
import { ChevronDown, Loader2, RotateCcw, Save, ShieldCheck, SlidersHorizontal, Users } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { can, type PermAction, type SessionUser } from '../../lib/session'
import { usePermGrid, useSavePerms, type Cell, type Matrix, type Scope } from '../../lib/console'
import type { ApiError } from '../../lib/api'
import { Badge, Btn, Modal, PageHead, Panel, toast } from '../../ui/kit'

type Level = 'none' | 'view' | 'edit' | 'full'
const PRESET: Record<Exclude<Level, 'none'>, PermAction[]> = { view: ['view'], edit: ['view', 'create', 'edit'], full: ['view', 'create', 'edit', 'decide', 'export', 'print', 'archive'] }
const levelOf = (c: Cell): Level | 'custom' => !c.actions.length ? 'none' : (Object.entries(PRESET).find(([, a]) => a.length === c.actions.length && a.every(x => c.actions.includes(x)))?.[0] as Level | undefined) ?? 'custom'

/** أقسام الصلاحيات بلغة المستخدم */
const GROUPS: { key: string; ar: string; en: string; modules: string[] }[] = [
  { key: 'people', ar: 'شؤون الموظفين', en: 'People', modules: ['employees', 'org', 'assets', 'discipline', 'announcements'] },
  { key: 'time', ar: 'الدوام والإجازات', en: 'Time & leave', modules: ['attendance', 'leaves', 'excuses', 'policies'] },
  { key: 'pay', ar: 'الرواتب', en: 'Pay', modules: ['payroll', 'salary_cert'] },
  { key: 'hiring', ar: 'التوظيف', en: 'Hiring', modules: ['jobs', 'candidates', 'internal', 'referrals'] },
  { key: 'growth', ar: 'الأداء والتطوير', en: 'Performance & growth', modules: ['performance', 'succession'] },
  { key: 'admin', ar: 'الإدارة', en: 'Administration', modules: ['settings', 'users', 'audit'] },
]

/**
 * الصلاحيات (M16 — SRS §10.2) بشكل مبسّط: اختر الدور ← لكل قسم مستوى واحد (لا وصول / عرض / عرض وتعديل / كامل) ونطاقه (نفسه / فريقه / الكل).
 * التفاصيل الدقيقة (الإجراءات السبعة) عند الطلب فقط. الحفظ بتأكيد يعرض التغييرات، ومسجّل في التدقيق.
 */
export function Permissions({ user }: { user: SessionUser }) {
  const L = useL(); const lang = useLang()
  const { data: grid, isLoading } = usePermGrid()
  const save = useSavePerms()
  const editable = can(user, 'users', 'edit')
  const [role, setRole] = useState('employee')
  const [draft, setDraft] = useState<Matrix | null>(null)
  const [confirm, setConfirm] = useState(false)
  const [open, setOpen] = useState<string | null>(null)
  useEffect(() => { if (grid && !draft) setDraft(structuredClone(grid.matrix)) }, [grid, draft])
  const changes = useMemo(() => {
    if (!grid || !draft) return []
    return Object.entries(draft).flatMap(([r, mods]) => Object.entries(mods).filter(([m, c]) => JSON.stringify(c) !== JSON.stringify(grid.matrix[r][m])).map(([m, c]) => ({ role: r, module: m, before: grid.matrix[r][m], after: c })))
  }, [grid, draft])
  if (isLoading || !grid || !draft) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>

  const roleOf = (k: string) => grid.roles.find(r => r.key === k)!
  const modOf = (k: string) => grid.modules.find(m => m.key === k)!
  const changedRoles = new Set(changes.map(c => c.role))
  const levelName: Record<Level | 'custom', string> = { none: L('لا وصول', 'No access'), view: L('عرض', 'View'), edit: L('عرض وتعديل', 'View & edit'), full: L('كامل', 'Full'), custom: L('مخصص', 'Custom') }
  const scopeName: Record<Scope, string> = { own: L('نفسه', 'Own'), team: L('فريقه', 'Team'), all: L('الكل', 'Everyone') }
  const set = (m: string, c: Cell) => setDraft(d => ({ ...d!, [role]: { ...d![role], [m]: c } }))
  const cells = draft[role]
  // المخصص يُكتب بالكلمات: «عرض، إضافة، طباعة · فريقه»
  const describe = (c: Cell) => { const lv = levelOf(c)
    if (lv === 'none') return levelName.none
    const what = lv === 'custom' ? grid.actions.filter(a => c.actions.includes(a.key)).map(a => bi(a, lang)).join(lang === 'ar' ? '، ' : ', ') : levelName[lv]
    return `${what} · ${scopeName[c.scope]}` }
  const known = new Set(GROUPS.flatMap(g => g.modules))
  const groups = [...GROUPS, { key: 'other', ar: 'أخرى', en: 'Other', modules: grid.modules.map(m => m.key).filter(k => !known.has(k)) }].filter(g => g.modules.some(m => cells[m]))
  const submit = () => save.mutate(Object.fromEntries([...changedRoles].map(r => [r, draft[r]])), {
    onSuccess: r => { setConfirm(false); setDraft(structuredClone(r.matrix)); toast(L(`حُفظت الصلاحيات — ${r.changed} تعديل`, `Permissions saved — ${r.changed} changes`)) },
    onError: e => { setConfirm(false); toast((e as ApiError).message) },
  })

  return <div className="max-w-[1200px] mx-auto space-y-5">
    <PageHead title={L('الصلاحيات', 'Permissions')} sub={L('اختر الدور، وحدد لكل قسم شو بيقدر يعمل وعلى مين. التغيير بيطبق فوراً على كل أصحاب الدور.', 'Pick a role, then choose for each section what it can do and for whom. Changes apply at once to everyone with the role.')}
      actions={editable && <>
        <Btn icon={<RotateCcw size={16} />} disabled={!changes.length} onClick={() => setDraft(structuredClone(grid.matrix))}>{L('تراجع', 'Discard')}</Btn>
        <Btn kind="primary" icon={<Save size={16} />} disabled={!changes.length} onClick={() => setConfirm(true)}>{L('حفظ', 'Save')}{changes.length ? <span className="num">({changes.length})</span> : null}</Btn>
      </>} />

    <div className="grid lg:grid-cols-[260px_1fr] gap-5 items-start">
      <div className="grid [grid-template-columns:repeat(auto-fill,minmax(150px,1fr))] lg:grid-cols-1 gap-2" role="tablist" aria-label={L('الأدوار', 'Roles')}>
        {grid.roles.map(r => <button key={r.key} role="tab" aria-selected={role === r.key} onClick={() => { setRole(r.key); setOpen(null) }}
          className={`rounded-xl border px-3 py-2.5 text-start flex items-center gap-2 ${role === r.key ? 'bg-brand text-on-brand border-brand' : 'bg-surface border-line hover:border-brand/50'}`}>
          <span className="flex-1 min-w-0"><span className="block font-semibold text-[13.5px] truncate">{bi(r, lang)}</span>
            <span className={`flex items-center gap-1 text-[11.5px] ${role === r.key ? 'text-on-brand/80' : 'text-ink-3'}`}><Users size={12} /><span className="num">{r.users}</span> {L('مستخدم', 'users')}</span></span>
          {changedRoles.has(r.key) && <span className="size-2 rounded-full bg-brass shrink-0" title={L('تعديلات غير محفوظة', 'Unsaved changes')} />}
        </button>)}
      </div>

      <div className="space-y-4">
        <div className="flex items-center gap-2 text-[15px] font-bold"><ShieldCheck size={18} className="text-brand" />{bi(roleOf(role), lang)}{!editable && <Badge>{L('عرض فقط', 'Read only')}</Badge>}</div>
        {groups.map(g => <Panel key={g.key} title={bi(g, lang)} pad={false}>
          <ul className="divide-y divide-line-2">{g.modules.filter(m => cells[m]).map(m => { const c = cells[m]; const lv = levelOf(c); const changed = JSON.stringify(c) !== JSON.stringify(grid.matrix[role][m])
            return <li key={m} className={`px-4 py-3 ${changed ? 'bg-brass-soft/40' : ''}`}>
              <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <span className="flex-1 min-w-[170px]"><span className={`block font-medium text-[14px] ${lv === 'none' ? 'text-ink-3' : ''}`}>{bi(modOf(m), lang)}</span>
                  <span className="block text-[12px] text-ink-3">{describe(c)}</span></span>
                <div className="inline-flex flex-wrap rounded-lg border border-line bg-surface p-0.5">{(['none', 'view', 'edit', 'full'] as Level[]).map(x => <button key={x} type="button" disabled={!editable}
                  onClick={() => set(m, { ...c, actions: x === 'none' ? [] : [...PRESET[x]] })}
                  className={`h-8 px-2.5 rounded-md text-[12.5px] font-semibold ${lv === x ? (x === 'none' ? 'bg-ink-3 text-on-brand' : 'bg-brand text-on-brand') : 'text-ink-2 hover:bg-sub'} disabled:cursor-default`}>{levelName[x]}</button>)}
                  {lv === 'custom' && <span className="h-8 px-2.5 rounded-md text-[12.5px] font-semibold bg-brass text-on-brand inline-flex items-center">{levelName.custom}</span>}</div>
                {lv !== 'none' && <div className="inline-flex items-center gap-1.5"><span className="text-[12px] text-ink-3">{L('على مين؟', 'For whom?')}</span>
                  <div className="inline-flex rounded-lg border border-line bg-surface p-0.5">{(['own', 'team', 'all'] as Scope[]).map(s => <button key={s} type="button" disabled={!editable} onClick={() => set(m, { ...c, scope: s })}
                    className={`h-8 px-2.5 rounded-md text-[12.5px] ${c.scope === s ? 'bg-info text-on-brand font-semibold' : 'text-ink-2 hover:bg-sub'} disabled:cursor-default`}>{scopeName[s]}</button>)}</div></div>}
                <button type="button" onClick={() => setOpen(open === m ? null : m)} className="inline-flex items-center gap-1 text-[12px] text-ink-3 hover:text-brand" aria-expanded={open === m}>
                  <SlidersHorizontal size={13} />{L('تفاصيل', 'Details')}<ChevronDown size={13} className={open === m ? 'rotate-180' : ''} /></button>
              </div>
              {open === m && <div className="mt-3 flex flex-wrap gap-2">{grid.actions.map(a => { const on = c.actions.includes(a.key)
                return <label key={a.key} className={`inline-flex items-center gap-1.5 rounded-lg border px-2.5 h-8 text-[12.5px] ${on ? 'border-brand/50 bg-brand-soft' : 'border-line'} ${editable ? 'cursor-pointer' : ''}`}>
                  <input type="checkbox" className="accent-[var(--brand)]" disabled={!editable} checked={on} onChange={() => {
                    let next: PermAction[] = on ? c.actions.filter(x => x !== a.key) : [...c.actions, a.key]
                    if (a.key === 'view' && on) next = []
                    if (!on && a.key !== 'view' && !next.includes('view')) next = ['view', ...next]
                    set(m, { ...c, actions: grid.actions.map(x => x.key).filter(k => next.includes(k)) })
                  }} />{bi(a, lang)}</label> })}</div>}
            </li> })}</ul>
        </Panel>)}
      </div>
    </div>

    <Modal open={confirm} onClose={() => setConfirm(false)} title={L('تأكيد تعديل الصلاحيات', 'Confirm permission changes')} width={620}
      footer={<><Btn onClick={() => setConfirm(false)}>{L('رجوع', 'Back')}</Btn><Btn kind="primary" disabled={save.isPending} icon={save.isPending ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />} onClick={submit}>{L('تأكيد الحفظ', 'Confirm and save')}</Btn></>}>
      <p className="text-[13.5px] text-ink-2 mb-3">{L('ستُطبَّق هذه التعديلات فوراً على كل مستخدمي الأدوار التالية:', 'These changes apply immediately to every user with these roles:')}</p>
      <ul className="divide-y divide-line-2 border border-line rounded-xl max-h-[50vh] overflow-y-auto">{changes.map(c => <li key={c.role + c.module} className="px-3 py-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px]">
        <span className="font-semibold">{bi(roleOf(c.role), lang)}</span><span className="text-ink-3">·</span><span>{bi(modOf(c.module), lang)}</span>
        <span className="ms-auto flex items-center gap-2"><span className="text-ink-3 line-through">{describe(c.before)}</span><span>{lang === 'ar' ? '←' : '→'}</span><span className="font-bold text-brand">{describe(c.after)}</span></span>
      </li>)}</ul>
    </Modal>
  </div>
}
