import { useEffect, useState } from 'react'
import { Download, FileSpreadsheet, Loader2, UserPlus } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../../lib/store'
import { navigate, setQuery, useRoute } from '../../../lib/router'
import { exportUrl, useDirectory, type DirFilters, type DirStatus, type EmpStatus } from '../../../lib/employees'
import { Avatar, Badge, Btn, Empty, PageHead, Panel } from '../../../ui/kit'
import { AddEmployee } from '../onboarding/AddEmployee'
import { ImportEmployees } from '../onboarding/ImportEmployees'

export function EmpBadge({ s }: { s: EmpStatus }) {
  const L = useL()
  return {
    onboarding: <Badge tone="info">{L('قيد المباشرة', 'Onboarding')}</Badge>, probation: <Badge tone="brass">{L('فترة التجربة', 'Probation')}</Badge>, active: <Badge tone="brand">{L('على رأس العمل', 'Active')}</Badge>,
    leave: <Badge tone="neutral">{L('إجازة', 'On leave')}</Badge>, resigning: <Badge tone="warn">{L('مستقيل — فترة إخطار', 'Resigning')}</Badge>, terminated: <Badge tone="danger">{L('منتهي الخدمة', 'Exited')}</Badge>,
  }[s]
}

const STATUSES: DirStatus[] = ['current', 'probation', 'resigning', 'terminated', 'all']

/** M07 — دليل الموظفين (Employees في الديمو): بحث بالاسم أو الرقم، تصفية بالإدارة والحالة، تصدير Excel */
export function Employees() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const f: DirFilters = { q: route.query.q ?? '', dept: route.query.dept ?? '', status: STATUSES.find(s => s === route.query.status) ?? 'current' }
  const [q, setQ] = useState(f.q)
  useEffect(() => { const t = setTimeout(() => q !== f.q && setQuery({ ...route.query, q: q || undefined }), 300); return () => clearTimeout(t) }, [q])   // eslint-disable-line react-hooks/exhaustive-deps
  const { data, isLoading } = useDirectory(f)
  const [add, setAdd] = useState(false); const [imp, setImp] = useState(false)
  const set = (k: keyof DirFilters, v: string) => setQuery({ ...route.query, [k]: v || undefined })
  const st: Record<DirStatus, string> = { current: L('الحاليون', 'Current'), probation: L('فترة التجربة', 'Probation'), resigning: L('مستقيلون', 'Resigning'), terminated: L('منتهية خدمتهم', 'Exited'), all: L('الكل', 'All') }

  return <div className="max-w-[1280px] mx-auto">
    <PageHead title={L('الموظفون', 'Employees')}
      sub={data && L(`${data.current} موظفاً على رأس العمل في ${data.departments} إدارات`, `${data.current} staff across ${data.departments} departments`)}
      actions={<>
        {data?.can.create && <><Btn icon={<FileSpreadsheet size={15} />} onClick={() => setImp(true)}>{L('استيراد', 'Import')}</Btn><Btn kind="primary" icon={<UserPlus size={15} />} onClick={() => setAdd(true)}>{L('إضافة موظف', 'Add employee')}</Btn></>}
        {data?.can.export && <a href={exportUrl(f)} className="inline-flex items-center gap-2 h-9 px-3.5 rounded-lg border border-line bg-surface font-semibold text-[14px] hover:bg-sub"><Download size={15} />{L('تصدير Excel', 'Export Excel')}</a>}
      </>} />
    <div className="flex flex-wrap gap-2 mb-4">
      <input className="field !w-full sm:!w-60" placeholder={L('بحث بالاسم أو الرقم الوظيفي', 'Search name or ID')} value={q} onChange={e => setQ(e.target.value)} />
      <select className="field !w-[calc(50%-4px)] sm:!w-48" value={f.dept} onChange={e => set('dept', e.target.value)} aria-label={L('الإدارة', 'Department')}>
        <option value="">{L('كل الإدارات', 'All departments')}</option>{data?.depts.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select>
      <select className="field !w-[calc(50%-4px)] sm:!w-44" value={f.status} onChange={e => set('status', e.target.value === 'current' ? '' : e.target.value)} aria-label={L('الحالة', 'Status')}>
        {STATUSES.map(s => <option key={s} value={s}>{st[s]}</option>)}</select>
    </div>
    <Panel pad={false}>
      {isLoading || !data ? <div className="grid place-items-center py-16"><Loader2 className="animate-spin text-brand" /></div> : <>
        <table className="tbl">
          <thead><tr><th>{L('الموظف', 'Employee')}</th><th className="hidden md:table-cell">{L('الرقم', 'ID')}</th><th className="hidden lg:table-cell">{L('المسمى', 'Title')}</th><th className="hidden sm:table-cell">{L('الإدارة', 'Department')}</th>
            <th className="hidden xl:table-cell">{L('المدينة', 'City')}</th><th className="hidden xl:table-cell">{L('المباشرة', 'Hired')}</th><th>{L('الحالة', 'Status')}</th></tr></thead>
          <tbody>{data.data.map(e => <tr key={e.id} className="click" onClick={() => navigate(`/hr/employees/${e.id}`)}>
            <td><div className="flex items-center gap-2.5 min-w-0"><span className="hidden sm:contents"><Avatar size={30} /></span><div className="min-w-0"><div className="font-medium">{bi(e.name, lang)}</div>
              <div className="text-[12px] text-ink-3 ltr text-start truncate max-w-[52vw] sm:max-w-none">{e.email}</div>
              <div className="text-[12px] text-ink-3 lg:hidden">{bi(e.title, lang)}<span className="md:hidden"> · <bdi className="num">{e.code}</bdi></span><span className="sm:hidden"> · {bi(e.dept, lang)}</span></div></div></div></td>
            <td className="hidden md:table-cell ltr num">{e.code}</td><td className="hidden lg:table-cell">{bi(e.title, lang)}</td><td className="hidden sm:table-cell">{bi(e.dept, lang)}</td>
            <td className="hidden xl:table-cell">{bi(e.city, lang)}</td><td className="hidden xl:table-cell num">{fmtDate(e.hire_date, lang)}</td><td><EmpBadge s={e.status} /></td></tr>)}</tbody>
        </table>
        {data.data.length === 0 && <Empty title={L('لا نتائج مطابقة', 'No matches')}>{L('غيّر عبارة البحث أو المرشحات.', 'Change the search or filters.')}</Empty>}
      </>}
    </Panel>
    {add && <AddEmployee onClose={() => setAdd(false)} />}
    {imp && <ImportEmployees onClose={() => setImp(false)} />}
  </div>
}
