import { useEffect, useState } from 'react'
import { CheckCircle2, Loader2, ScanText, XCircle } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { useCvReading, useSaveCvReading, type CvReading as Data } from '../../lib/platform'
import { Badge, Btn, Field, PageHead, Panel, Seg, toast } from '../../ui/kit'

/** المنصة: قراءة السير بالنموذج المحلي على الخادم — مجانية، والبيانات لا تخرج من الخادم. الشركات لا ترى أي إعداد */
export function CvReading() {
  const L = useL(); const lang = useLang()
  const q = useCvReading(); const save = useSaveCvReading()
  const [v, setV] = useState<Data | null>(null)
  useEffect(() => { if (q.data && !v) setV(q.data) }, [q.data, v])
  const d = q.data
  if (!d || !v) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const submit = () => save.mutate({ enabled: v.enabled, monthly_limit: v.monthly_limit, companies: v.companies.map(c => ({ id: c.id, enabled: c.enabled, monthly_limit: c.monthly_limit })) },
    { onSuccess: r => { setV(r); toast(L('حُفظت الإعدادات', 'Settings saved')) }, onError: e => toast(e.message) })
  return <div className="max-w-[1100px] mx-auto space-y-5">
    <PageHead title={L('قراءة السير', 'CV reading')} sub={L('قراءة آلية للسير الذاتية مقابل متطلبات الشاغر بنموذج يعمل على خادمنا — مجانية، والبيانات لا تخرج من الخادم، ولا تُرسل الهوية أو بيانات الاتصال.', 'Automatic CV reading against job requirements by a model running on our server — free, data never leaves the server, and identity or contact details are never sent.')}
      actions={<Btn kind="primary" disabled={save.isPending} onClick={submit}>{L('حفظ', 'Save')}</Btn>} />
    <Panel>
      <div className="flex flex-wrap items-center gap-4">
        <span className="size-12 rounded-xl grid place-items-center bg-brand-soft text-brand"><ScanText size={22} /></span>
        <div className="flex-1 min-w-[220px]">
          <div className="font-semibold flex items-center gap-2">{L('حالة الخدمة', 'Service status')}
            {d.available ? <Badge tone="brand"><CheckCircle2 size={12} />{L('جاهزة', 'Ready')}</Badge> : d.configured ? <Badge tone="brass"><Loader2 size={12} className="animate-spin" />{L('تُحمَّل أو متوقفة', 'Loading or stopped')}</Badge> : <Badge tone="danger"><XCircle size={12} />{L('غير مفعّلة على الخادم', 'Not set up on the server')}</Badge>}</div>
          <div className="text-[13px] text-ink-2 mt-0.5"><span className="ltr inline-block">{d.model}</span> · {L(`في الانتظار: ${d.queue}`, `Waiting: ${d.queue}`)}</div>
        </div>
        <Field label={L('الميزة', 'Feature')} div><Seg value={v.enabled ? 'on' : 'off'} onChange={x => setV({ ...v, enabled: x === 'on' })} options={[{ v: 'on', label: L('مفعّلة', 'On') }, { v: 'off', label: L('متوقفة للجميع', 'Off for all') }]} /></Field>
        <Field label={L('الحد الشهري الافتراضي لكل شركة', 'Default monthly limit per company')}><input type="number" min={0} className="field num !w-32" value={v.monthly_limit} onChange={e => setV({ ...v, monthly_limit: Number(e.target.value) })} /></Field>
      </div>
    </Panel>
    <Panel title={L('الشركات', 'Companies')} sub={L('اترك الحد فارغاً لاستعمال الحد الافتراضي. الاستهلاك هذا الشهر.', 'Leave the limit empty to use the default. Usage is for this month.')} pad={false}>
      <table className="tbl"><thead><tr><th>{L('الشركة', 'Company')}</th><th>{L('القراءة', 'Reading')}</th><th>{L('الحد الشهري', 'Monthly limit')}</th><th className="hidden sm:table-cell">{L('هذا الشهر', 'This month')}</th></tr></thead>
        <tbody>{v.companies.map((c, i) => { const set = (p: Partial<typeof c>) => setV({ ...v, companies: v.companies.map((x, j) => j === i ? { ...x, ...p } : x) })
          return <tr key={c.id}><td className="font-medium">{bi(c, lang)}<div className="sm:hidden text-[12px] text-ink-3 font-normal num">{L('هذا الشهر:', 'This month:')} {c.used}</div></td>
            <td><Seg value={c.enabled ? 'on' : 'off'} onChange={x => set({ enabled: x === 'on' })} options={[{ v: 'on', label: L('تعمل', 'On') }, { v: 'off', label: L('متوقفة', 'Off') }]} /></td>
            <td><input type="number" min={0} className="field num !w-20 sm:!w-28" placeholder={String(v.monthly_limit)} value={c.monthly_limit ?? ''} onChange={e => set({ monthly_limit: e.target.value === '' ? null : Number(e.target.value) })} /></td>
            <td className="num hidden sm:table-cell">{c.used}{c.failed > 0 && <span className="text-[12px] text-danger"> · {L(`تعذّر ${c.failed}`, `${c.failed} failed`)}</span>}</td></tr> })}</tbody></table>
    </Panel>
  </div>
}
