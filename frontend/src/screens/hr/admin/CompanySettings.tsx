import { useEffect, useState } from 'react'
import { Loader2, RotateCcw, Save, Upload } from 'lucide-react'
import { useL } from '../../../lib/store'
import { can, useMe } from '../../../lib/session'
import { ApiError } from '../../../lib/api'
import { useCompanyProfile, useSaveCompany, type CompanyProfile } from '../../../lib/admin'
import { Badge, Btn, Field, PageHead, Panel, toast } from '../../../ui/kit'

/** M16 «بيانات الشركة»: الهوية والشعار، بيانات النماذج، نصوص الصفحة العامة، بادئات الترقيم */
export function CompanySettings() {
  const L = useL()
  const editable = can(useMe().data, 'settings', 'edit')
  const { data } = useCompanyProfile()
  const save = useSaveCompany()
  const [c, setC] = useState<CompanyProfile | null>(null)
  const [logo, setLogo] = useState<File | null>(null)
  useEffect(() => { if (data) setC(data.data) }, [data])
  if (!data || !c) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const err = save.error instanceof ApiError ? save.error : null
  const dirty = !!logo || JSON.stringify(c) !== JSON.stringify(data.data)
  const set = (k: keyof CompanyProfile) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setC({ ...c, [k]: e.target.value })
  const txt = (k: keyof CompanyProfile, label: string, o: { ltr?: boolean; area?: boolean; req?: boolean } = {}) => <Field label={label} req={o.req} error={err?.field(k)}>
    {o.area ? <textarea className="field" disabled={!editable} value={(c[k] as string) ?? ''} onChange={set(k)} /> : <input className={`field ${o.ltr ? 'ltr text-start' : ''}`} disabled={!editable} value={(c[k] as string) ?? ''} onChange={set(k)} />}</Field>
  const site = (k: keyof CompanyProfile['site'], label: string, area = false) => <Field label={label} req error={err?.field(`site.${k}`)}>
    {area ? <textarea className="field" disabled={!editable} value={c.site[k]} onChange={e => setC({ ...c, site: { ...c.site, [k]: e.target.value } })} />
      : <input className="field" disabled={!editable} value={c.site[k]} onChange={e => setC({ ...c, site: { ...c.site, [k]: e.target.value } })} />}</Field>
  const prefixes: [keyof CompanyProfile['prefixes'], string, string][] = [['employee', L('الرقم الوظيفي', 'Employee code'), '-1033'], ['candidate', L('رقم المتقدم', 'Applicant ref'), '-2026-APP-48001'],
    ['decision', L('قرار التعيين', 'Appointment decision'), '-2026-001'], ['asset', L('رقم العهدة', 'Asset tag'), '-0075'], ['job', L('رمز الشاغر', 'Job code'), '-2026-041']]
  const submit = () => save.mutate({ v: c, logo }, { onSuccess: () => { setLogo(null); toast(L('حُفظت بيانات الشركة', 'Company data saved')) }, onError: x => toast((x as Error).message) })

  return <div className="max-w-[1000px] mx-auto space-y-5">
    <PageHead title={L('بيانات الشركة', 'Company data')} sub={L('الهوية والشعار وبيانات النماذج ونصوص الصفحة العامة وبادئات الترقيم — تنعكس فوراً على الواجهات والنماذج.', 'Identity, logo, form details, public page texts and numbering prefixes — applied at once across screens and forms.')}
      actions={editable && <><Btn icon={<RotateCcw size={16} />} onClick={() => setC({ ...c, site: data.defaults.site, prefixes: data.defaults.prefixes })}>{L('النصوص والبادئات الافتراضية', 'Default texts & prefixes')}</Btn>
        <Btn kind="primary" icon={save.isPending ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />} disabled={!dirty || save.isPending} onClick={submit}>{L('حفظ', 'Save')}</Btn></>} />
    {!editable && <Badge>{L('عرض فقط — التعديل لمدير الإعدادات', 'Read only — settings managers can edit')}</Badge>}
    <Panel title={L('الهوية', 'Identity')} sub={<span>{L('الرابط على بوابة الوظائف', 'Jobs portal link')}: <span className="ltr inline-block">/c/{c.slug}</span></span>}>
      <div className="grid sm:grid-cols-2 gap-4">
        {txt('name_ar', L('الاسم الرسمي بالعربية', 'Legal name (Arabic)'), { req: true })}{txt('name_en', L('الاسم الرسمي بالإنكليزية', 'Legal name (English)'), { ltr: true, req: true })}
        {txt('short_ar', L('الاسم المختصر بالعربية', 'Short name (Arabic)'))}{txt('short_en', L('الاسم المختصر بالإنكليزية', 'Short name (English)'), { ltr: true })}
        {txt('brand_latin', L('الاسم اللاتيني على الشعار', 'Latin brand line'), { ltr: true })}{txt('website', L('الموقع الإلكتروني', 'Website'), { ltr: true })}
        {txt('about_ar', L('نبذة بالعربية (صفحة الشركة)', 'About (Arabic, company page)'), { area: true })}{txt('about_en', L('نبذة بالإنكليزية', 'About (English)'), { area: true })}
        <Field label={L('الشعار', 'Logo')} hint="PNG / JPG / WEBP ≤ 2MB" error={err?.field('logo')} div>
          <div className="flex items-center gap-3">
            <span className="h-14 w-36 rounded-xl border border-line bg-white grid place-items-center overflow-hidden">{logo ? <img src={URL.createObjectURL(logo)} className="max-h-12 max-w-32" alt="" /> : c.logo ? <img src={c.logo} className="max-h-12 max-w-32" alt="" /> : <span className="text-[12px] text-ink-3">—</span>}</span>
            {editable && <label className="inline-flex items-center gap-2 h-9 px-3 rounded-lg border border-line bg-surface hover:bg-sub cursor-pointer text-[13px] font-semibold"><Upload size={15} />{L('رفع شعار', 'Upload logo')}
              <input type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={e => setLogo(e.target.files?.[0] ?? null)} /></label>}
          </div></Field>
      </div>
    </Panel>
    <Panel title={L('النماذج والمراسلات', 'Forms & correspondence')}>
      <div className="grid sm:grid-cols-2 gap-4">
        {txt('form_prefix', L('بادئة رمز النموذج (تذييل الطباعة)', 'Form code prefix (print footer)'), { ltr: true, req: true })}
        {txt('domain', L('نطاق بريد الشركة', 'Company email domain'), { ltr: true })}
        {txt('hr_dept_ar', L('اسم إدارة الموارد البشرية بالعربية', 'HR department name (Arabic)'), { req: true })}{txt('hr_dept_en', L('اسم إدارة الموارد البشرية بالإنكليزية', 'HR department name (English)'), { ltr: true, req: true })}
        {txt('careers_url', L('رابط صفحة التوظيف', 'Careers page link'), { ltr: true })}
      </div>
    </Panel>
    <Panel title={L('الصفحة العامة', 'Public page')} sub={L('العنوان والنص الرئيسي في صفحة الدخول.', 'The headline and intro on the sign-in page.')}>
      <div className="grid sm:grid-cols-2 gap-4">
        {site('hero_title_ar', L('العنوان بالعربية', 'Headline (Arabic)'))}{site('hero_title_en', L('العنوان بالإنكليزية', 'Headline (English)'))}
        {site('hero_text_ar', L('النص بالعربية', 'Text (Arabic)'), true)}{site('hero_text_en', L('النص بالإنكليزية', 'Text (English)'), true)}
      </div>
    </Panel>
    <Panel title={L('بادئات الترقيم', 'Numbering prefixes')} sub={L('2–6 أحرف إنكليزية كبيرة. تسري على الأرقام الجديدة فقط.', '2–6 capital letters. Applies to new numbers only.')}>
      <div className="grid [grid-template-columns:repeat(auto-fill,minmax(170px,1fr))] gap-4">{prefixes.map(([k, label, sample]) => <Field key={k} label={label} hint={<span className="ltr inline-block num">{c.prefixes[k]}{sample}</span>} error={err?.field(`prefixes.${k}`)}>
        <input className="field ltr text-start num uppercase" disabled={!editable} maxLength={6} value={c.prefixes[k]} onChange={e => setC({ ...c, prefixes: { ...c.prefixes, [k]: e.target.value.toUpperCase().replace(/[^A-Z]/g, '') } })} /></Field>)}</div>
    </Panel>
  </div>
}
