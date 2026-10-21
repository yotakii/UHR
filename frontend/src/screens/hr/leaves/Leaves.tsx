import { useEffect, useState } from 'react'
import { Search } from 'lucide-react'
import { useL } from '../../../lib/store'
import { setQuery, useRoute } from '../../../lib/router'
import { useHrLeaves, type LeaveTab } from '../../../lib/requests'
import { Empty, PageHead, Panel, Tabs } from '../../../ui/kit'
import { RequestTable } from '../../requests/TeamApprovals'

const TABS: LeaveTab[] = ['pending', 'justify', 'overtime', 'all']

/** M09 — الإجازات والتبريرات والتكليف بالإضافي (Leaves في الديمو) ضمن نطاق صلاحية «الإجازات»؛ القرار لمن عليه الدور فقط */
export function Leaves() {
  const L = useL(); const route = useRoute()
  const tab = TABS.find(t => t === route.query.tab) ?? 'pending'
  const [q, setQ] = useState(route.query.q ?? '')
  useEffect(() => { const t = setTimeout(() => q !== (route.query.q ?? '') && setQuery({ ...route.query, q: q || undefined }), 300); return () => clearTimeout(t) }, [q])   // eslint-disable-line react-hooks/exhaustive-deps
  const r = useHrLeaves(tab, route.query.q ?? ''); const d = r.data
  return <div className="max-w-[1200px] mx-auto">
    <PageHead title={L('الإجازات والتبريرات', 'Leave & justifications')} sub={L('كل طلب يمر على المدير المباشر ثم الموارد البشرية (والإدارة العليا للإجازات الطويلة). يُحدّث الرصيد تلقائياً عند الاعتماد النهائي.', 'Every request goes to the direct manager, then HR (and the executive for long leave). Balances update on final approval.')} />
    <div className="flex flex-wrap items-end justify-between gap-3">
      <Tabs value={tab} onChange={v => setQuery({ ...route.query, tab: v === 'pending' ? undefined : v })} tabs={[
        { v: 'pending', label: L('بانتظار الاعتماد', 'Pending'), count: d?.counts.pending }, { v: 'justify', label: L('تبريرات التأخير والغياب', 'Justifications'), count: d?.counts.justify },
        { v: 'overtime', label: L('التكليف بالإضافي', 'Overtime'), count: d?.counts.overtime }, { v: 'all', label: L('كل الطلبات', 'All requests') }]} />
      <label className="relative w-full sm:w-64 mb-1"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" />
        <input className="field !ps-9" placeholder={L('بحث بالاسم أو الرقم', 'Search name or code')} value={q} onChange={e => setQ(e.target.value)} /></label>
    </div>
    <Panel pad={false} className="mt-4">{d ? <RequestTable rows={d.data} rejectMin={d.reject_min} empty={tab === 'pending' ? L('لا توجد طلبات معلقة', 'Nothing pending') : L('لا توجد طلبات', 'No requests')} />
      : <Empty title={r.isError ? L('تعذّر التحميل', 'Could not load') : L('جارٍ التحميل…', 'Loading…')} />}</Panel>
  </div>
}
