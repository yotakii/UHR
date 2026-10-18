import { useEffect, useState } from 'react'
import { Plus, ExternalLink, KeyRound, Copy } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useCompanies, useCompanyStatus, useSaveCompany, type PlatformCompany } from '../../lib/platform'
import { Badge, Btn, Empty, Field, Modal, PageHead, Panel, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'
import { CompanyLogo } from '../public/parts'

/** الشركات على المنصة: الإنشاء بمدير أول، الهوية، تفعيل النشر ومراجعة المنصة، التعليق */
export function Companies() {
  const L = useL(); const lang = useLang()
  const { data, isLoading } = useCompanies()
  const status = useCompanyStatus()
  const [form, setForm] = useState<{ open: boolean; c: PlatformCompany | null }>({ open: false, c: null })
  const [suspend, setSuspend] = useState<PlatformCompany | null>(null)
  return <div className="max-w-[1200px] mx-auto">
    <PageHead title={L('الشركات', 'Companies')} sub={L('كل شركة معزولة ببياناتها وتنشر شواغرها على صفحتها وعلى البوابة.', 'Each company is isolated with its own data and publishes on its page and on the portal.')}
      actions={<Btn kind="primary" icon={<Plus size={16} />} onClick={() => setForm({ open: true, c: null })}>{L('شركة جديدة', 'New company')}</Btn>} />
    <Panel pad={false}>
      {isLoading && <div className="h-40 animate-pulse" />}
      {data?.length === 0 && <Empty title={L('لا شركات بعد', 'No companies yet')} />}
      {!!data?.length && <div><table className="tbl w-full"><thead><tr>
        <th>{L('الشركة', 'Company')}</th><th className="hidden sm:table-cell">{L('الحالة', 'Status')}</th><th className="hidden md:table-cell">{L('البوابة العامة', 'Public portal')}</th><th className="hidden lg:table-cell">{L('شواغر مفتوحة', 'Open jobs')}</th><th className="hidden lg:table-cell">{L('بانتظار المراجعة', 'Pending')}</th><th className="hidden lg:table-cell">{L('الطلبات', 'Applications')}</th><th className="hidden lg:table-cell">{L('الموظفون', 'Employees')}</th><th /></tr></thead>
        <tbody>{data.map(c => <tr key={c.id} className="click" onClick={() => setForm({ open: true, c })}>
          <td><div className="flex items-center gap-3"><CompanyLogo c={c} size={40} /><div><div className="font-medium">{bi(c.name, lang)}</div><div className="text-[12px] text-ink-3"><span className="ltr inline-block">/c/{c.slug}</span><span className="lg:hidden num"> · {L(`${c.counts.jobs_open} شواغر · ${c.counts.applications} طلبات`, `${c.counts.jobs_open} jobs · ${c.counts.applications} applications`)}</span></div>
            <div className="sm:hidden mt-1">{c.status === 'active' ? <Badge tone="brand" dot>{L('فعّالة', 'Active')}</Badge> : <Badge tone="danger" dot>{L('معلّقة', 'Suspended')}</Badge>}</div></div></div></td>
          <td className="hidden sm:table-cell">{c.status === 'active' ? <Badge tone="brand" dot>{L('فعّالة', 'Active')}</Badge> : <Badge tone="danger" dot>{L('معلّقة', 'Suspended')}</Badge>}{c.is_platform && <div className="mt-1"><Badge tone="info">{L('مالكة المنصة', 'Platform owner')}</Badge></div>}</td>
          <td className="hidden md:table-cell">{c.portal_enabled ? <div className="flex flex-col items-start gap-1"><Badge tone="brand">{L('مفعّل', 'Enabled')}</Badge><span className="text-[11px] text-ink-3">{c.portal_review ? L('بمراجعة المنصة', 'with review') : L('نشر مباشر', 'direct')}</span></div> : <Badge>{L('غير مفعّل', 'Off')}</Badge>}</td>
          <td className="num hidden lg:table-cell">{c.counts.jobs_open}</td><td className={`num hidden lg:table-cell ${c.counts.jobs_pending ? 'font-semibold text-brass' : 'text-ink-3'}`}>{c.counts.jobs_pending || '·'}</td><td className="num hidden lg:table-cell">{c.counts.applications}</td><td className="num hidden lg:table-cell">{c.counts.employees}</td>
          <td onClick={e => e.stopPropagation()}><div className="flex gap-1 justify-end">
            <a href={`/c/${c.slug}`} target="_blank" rel="noopener" className="h-8 px-2 grid place-items-center rounded-lg hover:bg-sub text-ink-3" title={L('صفحة الشركة', 'Company page')} aria-label={L('صفحة الشركة', 'Company page')}><ExternalLink size={15} /></a>
            {!c.is_platform && (c.status === 'active'
              ? <Btn size="sm" kind="danger" onClick={() => setSuspend(c)}>{L('تعليق', 'Suspend')}</Btn>
              : <Btn size="sm" onClick={() => status.mutate({ id: c.id, status: 'active', reason: '' }, { onSuccess: () => toast(L('أُعيد تفعيل الشركة', 'Company reactivated')) })}>{L('تفعيل', 'Activate')}</Btn>)}
          </div></td>
        </tr>)}</tbody></table></div>}
    </Panel>
    <CompanyForm open={form.open} c={form.c} onClose={() => setForm({ open: false, c: null })} />
    <DecisionDialog open={!!suspend} onClose={() => setSuspend(null)} tone="danger" reasonRequired title={L('تعليق الشركة', 'Suspend company')}
      subject={suspend && <><span className="font-semibold">{bi(suspend.name, lang)}</span><div className="text-[13px] text-ink-2 mt-1">{L('يُمنع دخول مستخدميها، وتختفي شواغرها وصفحتها من البوابة. البيانات تبقى محفوظة.', 'Its users cannot sign in, and its jobs and page disappear from the portal. Data is kept.')}</div></>}
      confirmLabel={L('تأكيد التعليق', 'Confirm suspension')}
      onConfirm={reason => status.mutateAsync({ id: suspend!.id, status: 'suspended', reason }).then(() => toast(L('عُلّقت الشركة', 'Company suspended')))} />
  </div>
}

type Form = { slug: string; name_ar: string; name_en: string; short_ar: string; short_en: string; brand_latin: string; about_ar: string; about_en: string; website: string; portal_enabled: boolean; portal_review: boolean; admin_email: string }
const EMPTY: Form = { slug: '', name_ar: '', name_en: '', short_ar: '', short_en: '', brand_latin: '', about_ar: '', about_en: '', website: '', portal_enabled: true, portal_review: true, admin_email: '' }

function CompanyForm({ open, c, onClose }: { open: boolean; c: PlatformCompany | null; onClose: () => void }) {
  const L = useL()
  const save = useSaveCompany(c?.id ?? null)
  const [v, setV] = useState<Form>(EMPTY)
  const [logo, setLogo] = useState<File | null>(null)
  const [created, setCreated] = useState<{ email: string; password: string | null } | null>(null)
  useEffect(() => {
    if (!open) return
    save.reset(); setLogo(null); setCreated(null)
    setV(c ? { slug: c.slug, name_ar: c.name_ar, name_en: c.name_en, short_ar: c.short_ar ?? '', short_en: c.short_en ?? '', brand_latin: c.brand_latin ?? '', about_ar: c.about_ar ?? '', about_en: c.about_en ?? '', website: c.website ?? '', portal_enabled: c.portal_enabled, portal_review: c.portal_review, admin_email: '' } : EMPTY)
  }, [open, c])
  const e = save.error instanceof ApiError ? save.error : null
  const set = <K extends keyof Form>(k: K, x: Form[K]) => { setV(s => ({ ...s, [k]: x })); if (save.error) save.reset() }
  const submit = () => {
    const fd = new FormData()
    Object.entries(v).forEach(([k, x]) => { if (k === 'admin_email' && c) return; fd.append(k, typeof x === 'boolean' ? (x ? '1' : '0') : x) })
    if (logo) fd.append('logo', logo)
    save.mutate(fd, { onSuccess: r => { if (r.admin) setCreated({ email: r.admin.email, password: r.admin.temporary_password }); else { toast(L('حُفظت بيانات الشركة', 'Company saved')); onClose() } } })
  }
  if (created) return <Modal open={open} onClose={onClose} title={L('أُنشئت الشركة', 'Company created')} footer={<Btn kind="primary" onClick={onClose}>{L('تم', 'Done')}</Btn>}>
    <div className="space-y-3 text-[14px]">
      <p>{L('أُرسل لمدير الشركة بريد بتفعيل الحساب وكلمة المرور المؤقتة، وسيُطلب منه تغييرها عند أول دخول.', 'The company admin was emailed the activation and a temporary password, which must be changed at first sign-in.')}</p>
      <div className="rounded-lg border border-line p-3 space-y-1"><div className="text-ink-3 text-[12px]">{L('البريد', 'Email')}</div><div className="ltr text-start font-semibold">{created.email}</div>
        {created.password && <><div className="text-ink-3 text-[12px] mt-2 flex items-center gap-1"><KeyRound size={13} />{L('كلمة المرور المؤقتة (تظهر مرة واحدة)', 'Temporary password (shown once)')}</div>
          <div className="flex items-center gap-2"><code className="ltr font-bold text-[16px]">{created.password}</code><button className="text-ink-3 hover:text-ink" onClick={() => navigator.clipboard?.writeText(created.password!).then(() => toast(L('نُسخت', 'Copied')))} aria-label={L('نسخ', 'Copy')}><Copy size={15} /></button></div></>}</div>
    </div>
  </Modal>
  return <Modal open={open} onClose={onClose} width={720} title={c ? L('تعديل الشركة', 'Edit company') : L('شركة جديدة', 'New company')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" onClick={submit} disabled={save.isPending}>{save.isPending ? L('جارٍ الحفظ…', 'Saving…') : c ? L('حفظ', 'Save') : L('إنشاء الشركة', 'Create company')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-4">
      <Field label={L('اسم الشركة (عربي)', 'Company name (Arabic)')} req error={e?.field('name_ar')}><input className="field" value={v.name_ar} onChange={x => set('name_ar', x.target.value)} /></Field>
      <Field label={L('اسم الشركة (إنكليزي)', 'Company name (English)')} req error={e?.field('name_en')}><input className="field ltr text-start" value={v.name_en} onChange={x => set('name_en', x.target.value)} /></Field>
      <Field label={L('الاسم المختصر (عربي)', 'Short name (Arabic)')}><input className="field" value={v.short_ar} onChange={x => set('short_ar', x.target.value)} /></Field>
      <Field label={L('الاسم المختصر (إنكليزي)', 'Short name (English)')}><input className="field ltr text-start" value={v.short_en} onChange={x => set('short_en', x.target.value)} /></Field>
      <Field label={L('المعرّف في الرابط', 'URL name')} req error={e?.field('slug')} hint={<span className="ltr">/c/{v.slug || 'company'}</span>}><input className="field ltr text-start" value={v.slug} onChange={x => set('slug', x.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))} /></Field>
      <Field label={L('الشعار اللاتيني', 'Latin tagline')} hint="UNIQUENESS GROUP"><input className="field ltr text-start" value={v.brand_latin} onChange={x => set('brand_latin', x.target.value)} /></Field>
      <Field label={L('نبذة (عربي)', 'About (Arabic)')}><textarea className="field" value={v.about_ar} onChange={x => set('about_ar', x.target.value)} /></Field>
      <Field label={L('نبذة (إنكليزي)', 'About (English)')}><textarea className="field ltr text-start" value={v.about_en} onChange={x => set('about_en', x.target.value)} /></Field>
      <Field label={L('الموقع الإلكتروني', 'Website')} error={e?.field('website')}><input className="field ltr text-start" placeholder="https://" value={v.website} onChange={x => set('website', x.target.value)} /></Field>
      <Field label={L('الشعار', 'Logo')} hint="PNG / JPG / WEBP — 1MB" error={e?.field('logo')}><input type="file" accept=".png,.jpg,.jpeg,.webp" className="field !py-1.5" onChange={x => setLogo(x.target.files?.[0] ?? null)} /></Field>
      {!c && <Field label={L('بريد مدير الشركة', 'Company admin email')} req error={e?.field('admin_email')} hint={L('يصله حساب «مدير النظام» بكلمة مرور مؤقتة', 'Gets a system-admin account with a temporary password')} className="sm:col-span-2"><input className="field ltr text-start" type="email" value={v.admin_email} onChange={x => set('admin_email', x.target.value)} /></Field>}
      <div className="sm:col-span-2 rounded-xl border border-line p-4 space-y-3 bg-canvas/40">
        <label className="flex gap-3 text-[14px]"><input type="checkbox" className="mt-1 accent-[var(--primary)]" checked={v.portal_enabled} onChange={x => set('portal_enabled', x.target.checked)} /><span>{L('السماح بالنشر على بوابة فرص العمل العامة', 'Allow publishing on the public jobs portal')}</span></label>
        <label className="flex gap-3 text-[14px]"><input type="checkbox" className="mt-1 accent-[var(--primary)]" checked={v.portal_review} disabled={!v.portal_enabled} onChange={x => set('portal_review', x.target.checked)} /><span>{L('مراجعة المنصة لكل شاغر قبل ظهوره في البوابة', 'Platform review of every position before it appears on the portal')}</span></label>
      </div>
    </div>
  </Modal>
}
