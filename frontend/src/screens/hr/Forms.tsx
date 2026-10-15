import { useEffect, useState } from 'react'
import { Loader2, Pencil, Save, Lock, Printer } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { useMe, can } from '../../lib/session'
import { useForms, useSaveForm, useSaveTemplate, type FormRow, type PrintTemplate } from '../../lib/console'
import type { ApiError } from '../../lib/api'
import { Badge, Btn, Field, Modal, PageHead, Panel, Seg, toast } from '../../ui/kit'

/** سجل النماذج وقالب الطباعة (M16 — SRS §10.4، §10.5): الرمز المطبوع والإصدار وتاريخه لكل نموذج، والشعار والهوامش */
export function Forms() {
  const L = useL(); const lang = useLang()
  const me = useMe().data
  const editable = can(me, 'settings', 'edit')
  const { data, isLoading } = useForms()
  const [edit, setEdit] = useState<FormRow | null>(null)
  if (isLoading || !data) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  return <div className="max-w-[1200px] mx-auto space-y-5">
    <PageHead title={L('النماذج وقالب الطباعة', 'Forms & print template')} sub={L('كل نموذج يُطبع بشعار الشركة وتذييل ثابت: الرمز · الإصدار · التاريخ. طباعة النماذج الحساسة تُسجَّل.', 'Every form prints with the company logo and a fixed footer: code · issue · date. Printing sensitive forms is logged.')} />
    <TemplatePanel initial={data.template} editable={editable} />
    <Panel title={L('سجل النماذج', 'Forms registry')} sub={L(`${data.data.length} نموذجاً`, `${data.data.length} forms`)} pad={false}>
      <div className="scroll-x"><table className="tbl min-w-[760px]">
        <thead><tr><th>{L('النموذج', 'Form')}</th><th>{L('الرمز المطبوع', 'Printed code')}</th><th>{L('الإصدار', 'Issue')}</th><th>{L('تاريخ الإصدار', 'Issued on')}</th><th>{L('حساس', 'Sensitive')}</th><th /></tr></thead>
        <tbody>{data.data.map(f => <tr key={f.id}>
          <td><div className="font-medium">{bi(f.title, lang)}</div><div className="text-[12px] text-ink-3"><span className="ltr inline-block">{f.code}</span></div></td>
          <td className="whitespace-nowrap font-semibold"><span className="ltr inline-block">{f.ref}</span></td>
          <td className="num">{f.issue}</td>
          <td className="num whitespace-nowrap">{f.issued_on?.split('-').reverse().join('-')}</td>
          <td>{f.sensitive ? <Badge tone="warn"><Lock size={12} />{L('يُسجَّل', 'Logged')}</Badge> : <span className="text-ink-3">—</span>}</td>
          <td className="text-end">{editable && <Btn size="sm" icon={<Pencil size={14} />} onClick={() => setEdit(f)}>{L('تعديل', 'Edit')}</Btn>}</td>
        </tr>)}</tbody>
      </table></div>
    </Panel>
    {edit && <FormEdit f={edit} onClose={() => setEdit(null)} />}
  </div>
}

function TemplatePanel({ initial, editable }: { initial: PrintTemplate; editable: boolean }) {
  const L = useL(); const lang = useLang()
  const me = useMe().data
  const save = useSaveTemplate()
  const [t, setT] = useState(initial)
  useEffect(() => setT(initial), [initial])
  const dirty = JSON.stringify(t) !== JSON.stringify(initial)
  const align = t.align === 'center' ? 'justify-center text-center' : t.align === 'end' ? 'justify-end text-end' : 'justify-start text-start'
  const set = (p: Partial<PrintTemplate>) => setT(x => ({ ...x, ...p }))
  return <Panel title={<span className="flex items-center gap-2"><Printer size={17} className="text-brand" />{L('قالب الطباعة', 'Print template')}</span>}
    action={editable && <Btn kind="primary" size="sm" icon={save.isPending ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />} disabled={!dirty || save.isPending}
      onClick={() => save.mutate(t, { onSuccess: () => toast(L('حُفظ قالب الطباعة', 'Print template saved')), onError: e => toast((e as ApiError).message) })}>{L('حفظ القالب', 'Save template')}</Btn>}>
    <div className="grid lg:grid-cols-[1fr_1.2fr] gap-5">
      <fieldset disabled={!editable} className="space-y-4">
        <Field label={L('الشعار', 'Logo')} div><Seg value={t.logo ? 'on' : 'off'} onChange={v => set({ logo: v === 'on' })} options={[{ v: 'on', label: L('يُطبع', 'Printed') }, { v: 'off', label: L('بدون شعار', 'No logo') }]} /></Field>
        <Field label={L('المحاذاة', 'Alignment')} div><Seg value={t.align} onChange={v => set({ align: v })} options={[{ v: 'start', label: L('البداية', 'Start') }, { v: 'center', label: L('الوسط', 'Center') }, { v: 'end', label: L('النهاية', 'End') }]} /></Field>
        <Field label={<span>{L('ارتفاع الشعار', 'Logo height')} <span className="num text-ink-3">({t.logo_height}px)</span></span>}><input type="range" min={24} max={120} step={2} value={t.logo_height} onChange={e => set({ logo_height: +e.target.value })} className="w-full accent-[var(--primary)]" /></Field>
        <Field label={<span>{L('هامش الصفحة', 'Page margin')} <span className="num text-ink-3">({t.margin_mm}mm)</span></span>}><input type="range" min={5} max={30} value={t.margin_mm} onChange={e => set({ margin_mm: +e.target.value })} className="w-full accent-[var(--primary)]" /></Field>
      </fieldset>
      <div>
        <div className="text-[12px] text-ink-3 mb-1.5">{L('معاينة', 'Preview')}</div>
        <div className="rounded-xl border border-line bg-white text-[#17242f] shadow-sm overflow-hidden" style={{ padding: `${t.margin_mm / 2}px` }}>
          <div className="border-b-2 border-[#17242f] px-4 pt-4 pb-3">
            <div className={`flex items-center gap-3 ${align}`}>
              {t.logo && me?.company?.logo && <img src={me.company.logo} alt="" className="w-auto object-contain" style={{ height: t.logo_height * 0.7 }} />}
              <div><div className="font-bold text-[13px]">{me?.company ? bi(me.company.name, lang) : ''}</div><div className="text-[11px] text-[#6b7680]">{L('إدارة الموارد البشرية', 'Human Resources Department')}</div></div>
            </div>
            <div className="text-center font-bold text-[15px] mt-3">{L('طلب إجازة / تبرير غياب', 'Leave request / absence justification')}</div>
          </div>
          <div className="px-4 py-5 space-y-2">{[90, 70, 80].map(w => <div key={w} className="h-2 rounded bg-[#e5e9ec]" style={{ width: `${w}%` }} />)}</div>
          <div className="border-t border-[#cfd6dc] px-4 py-1.5 text-[10.5px] text-[#6b7680] flex justify-between ltr" dir="ltr"><span className="font-semibold text-[#3b4652]">Uniqueness-HR.01-01-F-03</span><span>Issue:01</span><span>Date: 01-04-2025</span></div>
        </div>
      </div>
    </div>
  </Panel>
}

function FormEdit({ f, onClose }: { f: FormRow; onClose: () => void }) {
  const L = useL()
  const save = useSaveForm()
  const [v, setV] = useState({ ref: f.ref, title_ar: f.title.ar, title_en: f.title.en, issue: f.issue, issued_on: f.issued_on ?? '', sensitive: f.sensitive })
  const err = save.error as ApiError | null
  const set = (p: Partial<typeof v>) => setV(x => ({ ...x, ...p }))
  return <Modal open onClose={onClose} title={<span>{L('تعديل نموذج', 'Edit form')} <span className="ltr text-ink-3 text-[13px]">{f.code}</span></span>}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} icon={save.isPending ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
      onClick={() => save.mutate({ id: f.id, ...v }, { onSuccess: () => { toast(L('حُفظ النموذج', 'Form saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-4">
      <Field label={L('العنوان بالعربية', 'Arabic title')} req error={err?.field('title_ar')}><input className="field" value={v.title_ar} onChange={e => set({ title_ar: e.target.value })} /></Field>
      <Field label={L('العنوان بالإنكليزية', 'English title')} req error={err?.field('title_en')}><input className="field ltr" value={v.title_en} onChange={e => set({ title_en: e.target.value })} /></Field>
      <Field label={L('الرمز المطبوع', 'Printed code')} req error={err?.field('ref')} className="sm:col-span-2"><input className="field ltr" value={v.ref} onChange={e => set({ ref: e.target.value })} /></Field>
      <Field label={L('رقم الإصدار', 'Issue')} req error={err?.field('issue')}><input className="field ltr num" value={v.issue} onChange={e => set({ issue: e.target.value })} /></Field>
      <Field label={L('تاريخ الإصدار', 'Issued on')} req error={err?.field('issued_on')}><input type="date" className="field ltr" value={v.issued_on} onChange={e => set({ issued_on: e.target.value })} /></Field>
      <label className="sm:col-span-2 flex items-center gap-2 text-[14px]"><input type="checkbox" checked={v.sensitive} onChange={e => set({ sensitive: e.target.checked })} className="size-4 accent-[var(--primary)]" />
        {L('نموذج حساس — تُسجَّل كل طباعة له في سجل التدقيق', 'Sensitive — every print is recorded in the audit log')}</label>
    </div>
  </Modal>
}
