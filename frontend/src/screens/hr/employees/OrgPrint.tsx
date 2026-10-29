import { useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { ArrowRight, Loader2, Printer, Save } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../../lib/store'
import { setQuery, useRoute } from '../../../lib/router'
import { usePrintMeta } from '../../../lib/console'
import { deptTone, logOrgExport, orgPages, useOrg, useSaveOrgSettings, type OrgNode } from '../../../lib/employees'
import { Btn, Empty, Field, Seg, toast } from '../../../ui/kit'

// صفحة A4 أفقية بدقة 96 نقطة/إنش بعد هوامش 8 مم
const PAGE_W = 1062, PAGE_H = 733

/**
 * تصدير الهيكل التنظيمي PDF (D-33): حسب الشركة أو الإدارة أو فرع يبدأ بموظف، بعدد مستويات لكل صفحة،
 * بالشعار ورأس الشركة وتذييل النموذج ORG، والهيكل الكبير يُقسَّم صفحات («يتابع في صفحة N»). العرض بالأسماء والمسميات أو بالمسميات فقط.
 * الطباعة تُسجَّل في التدقيق، و«حفظ بصيغة PDF» من نافذة الطباعة.
 */
export function OrgPrint() {
  const L = useL(); const lang = useLang()
  const route = useRoute()
  const { data } = useOrg()
  const meta = usePrintMeta('ORG').data
  const save = useSaveOrgSettings()
  const scope = (route.query.scope as 'company' | 'department' | 'branch') || 'company'
  const mode = (route.query.mode as 'names_titles' | 'titles') || data?.chart.mode || 'names_titles'
  const levels = Math.max(2, Number(route.query.levels) || data?.chart.levels || 3)
  const dept = route.query.dept ?? ''
  const root = Number(route.query.root) || 0

  const plan = useMemo(() => {
    if (!data) return null
    const all = data.data
    let list: OrgNode[] = all; let roots: number[] = []
    if (scope === 'department') {
      list = all.filter(e => e.dept === (dept || data.depts[0]?.code))
      const ids = new Set(list.map(e => e.id))
      roots = list.filter(e => !e.manager_id || !ids.has(e.manager_id)).map(e => e.id)
    } else if (scope === 'branch') {
      roots = root ? [root] : []
    } else {
      const ids = new Set(all.map(e => e.id))
      roots = all.filter(e => !e.manager_id || !ids.has(e.manager_id)).map(e => e.id)
    }
    const pages = orgPages(list, roots, levels)
    return { list, pages, byId: new Map(all.map(e => [e.id, e])), pageOf: new Map(pages.map((p, i) => [p.root, i + 1])), color: new Map(data.depts.map(d => [d.code, d.color])) }
  }, [data, scope, dept, root, levels])

  if (!data || !plan) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const deptName = (code: string) => { const d = data.depts.find(x => x.code === code); return d ? bi(d, lang) : '' }
  const scopeLabel = scope === 'department' ? deptName(dept || data.depts[0]?.code) : scope === 'branch' && root ? bi(plan.byId.get(root)?.name ?? { ar: '', en: '' }, lang) : L('الشركة كاملة', 'The whole company')
  const print = async () => {
    await logOrgExport({ scope, dept: scope === 'department' ? dept || data.depts[0]?.code : undefined, root: scope === 'branch' ? root : undefined, levels, mode, pages: plan.pages.length }).catch(() => null)
    window.print()
  }
  const people = [...data.data].sort((a, b) => bi(a.name, lang).localeCompare(bi(b.name, lang)))
  return <div className="print-root">
    <style>{`@media print { @page { size: A4 landscape; margin: 8mm } .org-page { break-after: page; box-shadow: none !important; border: none !important; } .org-page:last-child { break-after: auto; } }`}</style>
    <div data-noprint className="max-w-[1100px] mx-auto space-y-4 mb-5">
      <a href="/hr/org" className="text-[13px] text-ink-3 hover:text-ink flex items-center gap-1"><ArrowRight size={14} className={lang === 'en' ? 'rotate-180' : ''} />{L('الهيكل التنظيمي', 'Org chart')}</a>
      <div className="rounded-2xl border border-line bg-surface p-4 grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(210px,1fr))] items-end">
        <Field label={L('النطاق', 'Scope')}><select className="field" value={scope} onChange={e => setQuery({ scope: e.target.value })}>
          <option value="company">{L('الشركة كاملة', 'The whole company')}</option><option value="department">{L('إدارة', 'A department')}</option><option value="branch">{L('فرع يبدأ بموظف', 'A branch from an employee')}</option></select></Field>
        {scope === 'department' && <Field label={L('الإدارة', 'Department')}><select className="field" value={dept || data.depts[0]?.code} onChange={e => setQuery({ dept: e.target.value })}>{data.depts.map(d => <option key={d.code} value={d.code}>{bi(d, lang)}</option>)}</select></Field>}
        {scope === 'branch' && <Field label={L('يبدأ من', 'Starts from')}><select className="field" value={root || ''} onChange={e => setQuery({ root: e.target.value || undefined })}><option value="">—</option>{people.map(e => <option key={e.id} value={e.id}>{bi(e.name, lang)} · {bi(e.title, lang)}</option>)}</select></Field>}
        <Field label={L('مستويات في الصفحة', 'Levels per page')}><select className="field num" value={levels} onChange={e => setQuery({ levels: e.target.value })}>{[2, 3, 4, 5, 6].map(n => <option key={n} value={n}>{n}</option>)}</select></Field>
        <Field label={L('العرض', 'Show')} div><Seg value={mode} onChange={v => setQuery({ mode: v })} options={[{ v: 'names_titles', label: L('الاسم والمسمى', 'Name & title') }, { v: 'titles', label: L('المسميات فقط', 'Titles only') }]} /></Field>
        <div className="flex flex-wrap gap-2 [grid-column:1/-1] items-center">
          <span className="text-[13px] text-ink-3 flex-1">{L(`${plan.pages.length} صفحة — A4 أفقي. اختر «حفظ بصيغة PDF» في نافذة الطباعة.`, `${plan.pages.length} page(s) — A4 landscape. Choose “Save as PDF” in the print dialog.`)}</span>
          {data.can.settings && <Btn icon={<Save size={15} />} disabled={save.isPending} onClick={() => save.mutate({ mode, levels }, { onSuccess: () => toast(L('حُفظ كإعداد افتراضي', 'Saved as the default')) })}>{L('حفظ كافتراضي', 'Save as default')}</Btn>}
          <Btn kind="primary" icon={<Printer size={15} />} disabled={!plan.pages.length} onClick={print}>{L('طباعة / PDF', 'Print / PDF')}</Btn>
        </div>
      </div>
    </div>
    {!plan.pages.length ? <div data-noprint className="max-w-[1100px] mx-auto rounded-2xl border border-line bg-surface"><Empty title={scope === 'branch' ? L('اختر الموظف الذي يبدأ منه الفرع', 'Choose the employee the branch starts from') : L('لا يوجد موظفون في هذا النطاق', 'No employees in this scope')} /></div>
      : <div className="space-y-6 print:space-y-0">{plan.pages.map((p, i) => <PageFrame key={p.root}>
        <article className="org-page bg-white text-[#17242f] rounded-xl shadow-sm border border-line flex flex-col" style={{ width: PAGE_W, height: PAGE_H }} dir={lang === 'ar' ? 'rtl' : 'ltr'}>
          <header className="flex items-center gap-3 px-6 pt-4 pb-2 border-b border-[#e5e9ec]">
            {meta?.template.logo && meta.company.logo && <img src={meta.company.logo} alt="" className="h-11 w-auto object-contain" />}
            <div className="flex-1"><div className="font-bold text-[14px]">{meta ? bi(meta.company.name, lang) : ''}</div><div className="text-[11.5px] text-[#6b7680]">{meta ? bi(meta.company.dept, lang) : ''}</div></div>
            <div className="text-center flex-[2]"><h2 className="text-[18px] font-bold">{L('الهيكل التنظيمي', 'Organization chart')}</h2>
              <div className="text-[12px] text-[#3b4652]">{i === 0 ? scopeLabel : <>{L('تابع: فريق', 'Continued: team of')} {mode === 'titles' ? bi(plan.byId.get(p.root)!.title, lang) : bi(plan.byId.get(p.root)!.name, lang)}</>}</div></div>
            <div className="flex-1 text-end text-[11.5px] text-[#6b7680] num">{fmtDate(new Date().toISOString(), lang)}</div>
          </header>
          <FitTree><Tree id={p.root} depth={1} levels={levels} plan={plan} mode={mode} lang={lang} L={L} isRoot page={i + 1} /></FitTree>
          <footer className="px-6 py-1.5 border-t border-[#e5e9ec] text-[10.5px] text-[#6b7680] flex justify-between ltr" dir="ltr">
            <span className="font-semibold text-[#3b4652]">{meta?.form.ref ?? 'ORG'}</span><span>Issue:{meta?.form.issue ?? '01'}</span><span className="num">{L('صفحة', 'Page')} {i + 1} / {plan.pages.length}</span>
          </footer>
        </article>
      </PageFrame>)}</div>}
  </div>
}

/** معاينة الصفحة على الشاشة بعرض الحاوية (بلا تمرير أفقي) — وبحجمها الحقيقي عند الطباعة */
function PageFrame({ children }: { children: ReactNode }) {
  const box = useRef<HTMLDivElement>(null); const [w, setW] = useState(PAGE_W)
  useLayoutEffect(() => { if (!box.current) return; const ro = new ResizeObserver(() => box.current && setW(box.current.clientWidth)); ro.observe(box.current); return () => ro.disconnect() }, [])
  const s = Math.min(1, w / PAGE_W)
  return <div ref={box} className="relative overflow-hidden max-w-[1062px] mx-auto print:overflow-visible print:max-w-none print:!h-auto" style={{ height: PAGE_H * s }}>
    <div className="absolute top-0 left-0 origin-top-left print:static print:!transform-none" style={{ width: PAGE_W, transform: `scale(${s})`, direction: 'ltr' }}>{children}</div>
  </div>
}

/** يصغّر الشجرة لتتسع في مساحة الصفحة طولاً وعرضاً */
function FitTree({ children }: { children: ReactNode }) {
  const area = useRef<HTMLDivElement>(null); const tree = useRef<HTMLDivElement>(null)
  const [s, setS] = useState(1)
  useLayoutEffect(() => {
    if (!area.current || !tree.current) return
    const fit = () => { if (!area.current || !tree.current) return; const a = area.current, t = tree.current; setS(Math.min(1, (a.clientWidth - 24) / t.offsetWidth, (a.clientHeight - 16) / t.offsetHeight)) }
    const ro = new ResizeObserver(fit); ro.observe(tree.current); ro.observe(area.current); fit()
    return () => ro.disconnect()
  }, [])
  return <div ref={area} className="flex-1 relative overflow-hidden">
    <div ref={tree} className="absolute top-3 left-1/2 w-max" style={{ transform: `translateX(-50%) scale(${s})`, transformOrigin: 'top center', direction: 'ltr' }}><ul className="flex justify-center">{children}</ul></div>
  </div>
}

type Plan = { byId: Map<number, OrgNode>; list: OrgNode[]; pageOf: Map<number, number>; color: Map<string, string> }
function Tree({ id, depth, levels, plan, mode, lang, L, isRoot, page }: { id: number; depth: number; levels: number; plan: Plan; mode: 'names_titles' | 'titles'; lang: 'ar' | 'en'; L: (a: string, b: string) => string; isRoot?: boolean; page: number }) {
  const e = plan.byId.get(id)
  if (!e) return null
  const ch = plan.list.filter(x => x.manager_id === id && x.id !== id)
  const cut = !isRoot && depth >= levels && ch.length > 0
  const next = cut ? plan.pageOf.get(id) : undefined
  return <li className="relative flex flex-col items-center px-1.5">
    <div className="relative w-[150px] rounded-xl border border-[#cfd6dc] bg-white px-2 pt-2.5 pb-2 text-center" dir={lang === 'ar' ? 'rtl' : 'ltr'}>
      <span className={`absolute inset-x-5 top-0 h-[3px] rounded-b ${deptTone(plan.color.get(e.dept) ?? 'brand')}`} />
      {mode === 'names_titles' ? <><div className="font-bold text-[12.5px] leading-snug">{bi(e.name, lang)}</div><div className="text-[10.5px] text-[#6b7680] leading-snug mt-0.5">{bi(e.title, lang)}</div></>
        : <div className="font-bold text-[12px] leading-snug">{bi(e.title, lang)}</div>}
      {next && next !== page && <div className="mt-1 text-[9.5px] text-[#3b4652] border-t border-dashed border-[#cfd6dc] pt-0.5">{L(`فريقه (${ch.length}) ← صفحة ${next}`, `Team (${ch.length}) → page ${next}`)}</div>}
    </div>
    {!cut && ch.length > 0 && <><span className="h-4 w-px bg-[#9aa4ad]" /><ul className="relative flex">{ch.map((c, i) => <div key={c.id} className="relative pt-4">
      <span className="absolute top-0 left-1/2 h-4 w-px bg-[#9aa4ad]" />
      {ch.length > 1 && <span className={`absolute top-0 h-px bg-[#9aa4ad] ${i === 0 ? 'left-1/2 right-0' : i === ch.length - 1 ? 'left-0 right-1/2' : 'inset-x-0'}`} />}
      <Tree id={c.id} depth={depth + 1} levels={levels} plan={plan} mode={mode} lang={lang} L={L} page={page} /></div>)}</ul></>}
  </li>
}
