import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Copy, Plus, RotateCcw, X } from 'lucide-react'
import { api, ApiError } from '../../lib/api'
import { queryClient } from '../../lib/session'
import { useL, useLang, bi } from '../../lib/store'
import type { Bi } from '../../lib/types'
import { Btn, Field, Modal, Seg, toast } from '../../ui/kit'

type Cfg = { enabled: boolean; sites: string[]; limit: number; theme: 'light' | 'dark'; show_logo: boolean; show_filters: boolean; color: string }
type Resp = { data: Cfg; defaults: Cfg; slug: string; urls: { script: string; page: string }; departments: (Bi & { code: string })[]; can: { edit: boolean } }

/** كود تضمين الشواغر في موقع الشركة (FEATURES 05b): الإعداد، المواقع المسموحة، الكود الجاهز للنسخ، ومعاينة حية */
export function JobsEmbed({ onClose }: { onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const q = useQuery({ queryKey: ['hr', 'jobs-embed'], queryFn: () => api<Resp>('/hr/jobs/embed') })
  const save = useMutation({ mutationFn: (v: Cfg) => api<Resp>('/hr/jobs/embed', { method: 'PUT', json: v }), onSuccess: d => { queryClient.setQueryData(['hr', 'jobs-embed'], d); toast(L('حُفظ إعداد التضمين', 'Embed settings saved')) } })
  const [v, setV] = useState<Cfg | null>(null)
  const [opt, setOpt] = useState({ lang: 'ar', dept: '' })
  useEffect(() => { if (q.data && !v) setV({ ...q.data.data, sites: q.data.data.sites.length ? q.data.data.sites : [''] }) }, [q.data, v])
  const d = q.data
  const code = useMemo(() => d ? `<div data-uhr-jobs="${d.slug}"${opt.lang === 'en' ? ' data-lang="en"' : ''}${opt.dept ? ` data-dept="${opt.dept}"` : ''}></div>\n<script src="${d.urls.script}" async></script>` : '', [d, opt])
  const preview = d ? `${d.urls.page.replace(/^https?:\/\/[^/]+/, '')}?lang=${opt.lang}${opt.dept ? `&dept=${opt.dept}` : ''}&v=${d.data.limit}${d.data.theme}${d.data.color}${d.data.show_filters}${d.data.show_logo}` : ''
  const e = save.error instanceof ApiError ? save.error : null
  const edit = !!d?.can.edit
  return <Modal open onClose={onClose} width={900} title={L('تضمين الشواغر في موقع الشركة', 'Embed jobs on the company website')}
    footer={edit && v ? <><Btn icon={<RotateCcw size={15} />} onClick={() => setV({ ...d!.defaults, sites: [''] })}>{L('القيم الافتراضية', 'Defaults')}</Btn>
      <Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate({ ...v, sites: v.sites.filter(s => s.trim()) })}>{L('حفظ', 'Save')}</Btn></> : undefined}>
    {!d || !v ? null : <div className="grid gap-5 lg:grid-cols-[1fr_1.1fr]">
      <div className="space-y-4 min-w-0">
        <p className="text-[13px] text-ink-3">{L('ضع هذا الكود في صفحة «الوظائف» بموقع شركتك؛ تظهر الشواغر المنشورة على صفحة الشركة وتتحدّث تلقائياً، وزر التقديم يفتح بوابة التوظيف.', 'Paste this code on the careers page of your website; the jobs published on your company page appear and update automatically, and «apply» opens the careers portal.')}</p>
        <div className="grid grid-cols-2 gap-2">
          <Field label={L('لغة العرض', 'Language')}><select className="field" value={opt.lang} onChange={x => setOpt({ ...opt, lang: x.target.value })}><option value="ar">العربية</option><option value="en">English</option></select></Field>
          <Field label={L('قسم محدد', 'One department')}><select className="field" value={opt.dept} onChange={x => setOpt({ ...opt, dept: x.target.value })}><option value="">{L('كل الأقسام', 'All departments')}</option>{d.departments.map(x => <option key={x.code} value={x.code}>{bi(x, lang)}</option>)}</select></Field>
        </div>
        <Field label={L('الكود', 'Code')} div>
          <pre className="ltr text-start text-[12px] bg-sub rounded-xl p-3 whitespace-pre-wrap break-all">{code}</pre>
          <Btn size="sm" className="mt-2" icon={<Copy size={14} />} disabled={!d.data.enabled} onClick={() => navigator.clipboard?.writeText(code).then(() => toast(L('نُسخ الكود', 'Code copied')))}>{L('نسخ الكود', 'Copy code')}</Btn>
        </Field>
        <fieldset disabled={!edit} className="space-y-3 rounded-xl border border-line p-3">
          <label className="flex items-center gap-2 text-[13.5px]"><input type="checkbox" checked={v.enabled} onChange={x => setV({ ...v, enabled: x.target.checked })} />{L('التضمين مفعّل', 'Embedding enabled')}</label>
          <Field label={L('المواقع المسموح لها بعرض الشواغر', 'Websites allowed to show the jobs')} hint={L('اتركها فارغة للسماح لأي موقع. اكتب النطاق فقط مثل company.com (يشمل نطاقاته الفرعية).', 'Leave empty to allow any site. Enter the domain only, e.g. company.com (sub-domains included).')} div>
            <div className="space-y-1.5">{v.sites.map((s, i) => <div key={i} className="flex gap-2"><input className="field ltr" value={s} placeholder="company.com" onChange={x => setV({ ...v, sites: v.sites.map((y, j) => j === i ? x.target.value : y) })} aria-label={L('الموقع', 'Site')} />
              <button type="button" className="text-ink-3 hover:text-danger" onClick={() => setV({ ...v, sites: v.sites.filter((_, j) => j !== i) })} aria-label={L('حذف', 'Remove')}><X size={15} /></button></div>)}
              {e && Object.keys(e.errors).filter(k => k.startsWith('sites')).map(k => <div key={k} className="text-[12px] text-danger">{e.field(k)}</div>)}
              <Btn size="sm" type="button" icon={<Plus size={14} />} onClick={() => setV({ ...v, sites: [...v.sites, ''] })}>{L('موقع', 'Site')}</Btn></div></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label={L('المظهر', 'Theme')} div><Seg value={v.theme} onChange={x => setV({ ...v, theme: x })} options={[{ v: 'light', label: L('فاتح', 'Light') }, { v: 'dark', label: L('داكن', 'Dark') }]} /></Field>
            <Field label={L('عدد الشواغر المعروضة', 'Jobs shown')} error={e?.field('limit')}><input type="number" min={1} max={100} className="field num" value={v.limit} onChange={x => setV({ ...v, limit: Number(x.target.value) || 1 })} /></Field>
            <Field label={L('لون الأزرار', 'Accent colour')} hint={L('فارغ = لون الشركة', 'Empty = company colour')} error={e?.field('color')}><input className="field ltr" placeholder="#2f6f5e" value={v.color} onChange={x => setV({ ...v, color: x.target.value.trim() })} /></Field>
            <div className="space-y-1.5 pt-6"><label className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={v.show_logo} onChange={x => setV({ ...v, show_logo: x.target.checked })} />{L('إظهار الشعار', 'Show the logo')}</label>
              <label className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={v.show_filters} onChange={x => setV({ ...v, show_filters: x.target.checked })} />{L('بحث وتصفية', 'Search & filter')}</label></div>
          </div>
        </fieldset>
        {save.error && !e?.errors && <div className="text-[13px] text-danger">{(save.error as Error).message}</div>}
      </div>
      <div className="min-w-0"><div className="text-[13px] font-semibold mb-2">{L('معاينة', 'Preview')}</div>
        {d.data.enabled ? <iframe key={preview} title={L('معاينة', 'Preview')} src={preview} className="w-full h-[520px] rounded-xl border border-line bg-white" />
          : <div className="h-[200px] grid place-items-center rounded-xl border border-dashed border-line text-[13px] text-ink-3">{L('التضمين متوقف — لن تظهر الشواغر في أي موقع.', 'Embedding is off — no website will show the jobs.')}</div>}
        <div className="text-[12px] text-ink-3 mt-2">{L('تُعرض الشواغر المنشورة على صفحة الشركة أو البوابة فقط؛ الشواغر الداخلية لا تظهر.', 'Only jobs published on the company page or the portal appear; internal jobs never do.')}</div></div>
    </div>}
  </Modal>
}
