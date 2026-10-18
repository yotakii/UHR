import { useState } from 'react'
import { Check, Loader2 } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../../lib/store'
import { navigate } from '../../../lib/router'
import { ApiError } from '../../../lib/api'
import { useCreateEmployee, useLookups, type NewEmployeeInput } from '../../../lib/onboarding'
import { Btn, Field, Modal, Seg, toast } from '../../../ui/kit'

const today = () => new Date().toISOString().slice(0, 10)
const BLANK: NewEmployeeInput = { name: '', name_en: '', gender: 'M', national_id: '', dob: '', phone: '+963 9', email: '', department_id: '', title_ar: '', title_en: '', location_id: '', manager_id: '', salary: '', currency: 'USD', hire_date: today() }
const STEP_FIELDS: (keyof NewEmployeeInput)[][] = [['name', 'name_en', 'gender', 'national_id', 'dob', 'phone', 'email'], ['department_id', 'title_ar', 'title_en', 'location_id', 'manager_id', 'salary', 'currency', 'hire_date']]

/**
 * معالج إضافة موظف (M06 — AddEmployee في الديمو، نافذة 680px بأربع خطوات): البيانات الشخصية، الوظيفة والراتب، العهد، المراجعة.
 * الخادم ينشئ ملفاً «قيد المباشرة» وحساباً بكلمة مرور مؤقتة تُرسل بالبريد، ثم تُفتح صفحة مباشرته.
 */
export function AddEmployee({ onClose }: { onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const { data: lk } = useLookups()
  const create = useCreateEmployee()
  const [step, setStep] = useState(0)
  const [d, setD] = useState<NewEmployeeInput>(BLANK)
  const [assets, setAssets] = useState<number[]>([])
  const err = create.error instanceof ApiError ? create.error : null
  const e = (k: string) => err?.field(k)
  const set = <K extends keyof NewEmployeeInput>(k: K, v: NewEmployeeInput[K]) => { setD(x => ({ ...x, [k]: v })); if (err) create.reset() }
  const steps = [L('البيانات الشخصية', 'Personal'), L('الوظيفة والراتب', 'Job & pay'), L('العهد', 'Custody'), L('المراجعة', 'Review')]
  const missing = (s: number) => s === 0 ? !d.name.trim() || !d.name_en.trim() || !d.national_id.trim() || !d.dob || d.phone.replace(/\D/g, '').length < 9 || !/^\S+@\S+\.\S+$/.test(d.email)
    : s === 1 ? !d.department_id || !d.title_ar.trim() || !d.title_en.trim() || !d.location_id || d.salary === '' || !d.hire_date : false
  const dept = lk?.departments.find(x => x.id === d.department_id)
  const finish = () => create.mutate({ ...d, asset_ids: assets }, {
    onSuccess: r => { toast(L(`أُنشئ ملف الموظف ${r.code} وبدأت المباشرة`, `Employee ${r.code} created — onboarding started`)); onClose(); navigate(`/hr/onboarding/${r.id}`) },
    onError: x => { if (x instanceof ApiError) { const bad = STEP_FIELDS.findIndex(fs => fs.some(f => x.field(f))); if (bad >= 0) setStep(bad); else toast(x.message) } },
  })
  return <Modal open onClose={() => !create.isPending && onClose()} title={L('إضافة موظف جديد', 'Add new employee')} width={680}
    footer={<>{step > 0 && <Btn onClick={() => setStep(step - 1)} disabled={create.isPending}>{L('السابق', 'Back')}</Btn>}
      {step < 3 ? <Btn kind="primary" disabled={missing(step)} onClick={() => setStep(step + 1)}>{L('التالي', 'Next')}</Btn>
        : <Btn kind="primary" disabled={create.isPending} icon={create.isPending ? <Loader2 size={16} className="animate-spin" /> : undefined} onClick={finish}>{L('إنشاء الملف', 'Create file')}</Btn>}</>}>
    <ol className="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-5">{steps.map((x, i) => <li key={i} className={`rounded-lg px-2 py-1.5 text-[12px] font-semibold text-center border ${i === step ? 'border-brand bg-brand-soft text-brand' : i < step ? 'border-brand/30 text-brand' : 'border-line text-ink-3'}`}>{i + 1}. {x}</li>)}</ol>
    {!lk ? <div className="grid place-items-center py-10"><Loader2 className="animate-spin text-brand" /></div> : <>
      {step === 0 && <div className="grid sm:grid-cols-2 gap-3">
        <Field label={L('الاسم الكامل', 'Full name')} req error={e('name')}><input className="field" value={d.name} onChange={x => set('name', x.target.value)} autoFocus /></Field>
        <Field label={L('الاسم بالإنكليزية', 'Name (EN)')} req error={e('name_en')}><input className="field ltr text-start" value={d.name_en} onChange={x => set('name_en', x.target.value)} /></Field>
        <Field label={L('الجنس', 'Gender')} div error={e('gender')}><Seg value={d.gender} onChange={v => set('gender', v)} options={[{ v: 'M', label: L('ذكر', 'Male') }, { v: 'F', label: L('أنثى', 'Female') }]} /></Field>
        <Field label={L('الرقم الوطني', 'National ID')} req error={e('national_id')}><input className="field ltr text-start num" inputMode="numeric" value={d.national_id} onChange={x => set('national_id', x.target.value)} /></Field>
        <Field label={L('تاريخ الولادة', 'Birth date')} req error={e('dob')}><input type="date" className="field" value={d.dob} onChange={x => set('dob', x.target.value)} /></Field>
        <Field label={L('الجوال', 'Mobile')} req error={e('phone')}><input className="field ltr text-start" value={d.phone} onChange={x => set('phone', x.target.value)} /></Field>
        <Field label={L('البريد', 'Email')} req error={e('email')} className="sm:col-span-2" hint={L('يصله عليه حساب الدخول وكلمة مرور مؤقتة.', 'The sign-in account and a temporary password are sent here.')}><input type="email" className="field ltr text-start" value={d.email} onChange={x => set('email', x.target.value)} /></Field>
      </div>}
      {step === 1 && <div className="grid sm:grid-cols-2 gap-3">
        <Field label={L('الإدارة', 'Department')} req error={e('department_id')}><select className="field" value={d.department_id} onChange={x => { set('department_id', x.target.value ? Number(x.target.value) : ''); set('manager_id', '') }}>
          <option value="">{L('اختر…', 'Choose…')}</option>{lk.departments.map(x => <option key={x.id} value={x.id}>{x[lang]}</option>)}</select></Field>
        <Field label={L('مقر العمل', 'Location')} req error={e('location_id')}><select className="field" value={d.location_id} onChange={x => set('location_id', x.target.value ? Number(x.target.value) : '')}>
          <option value="">{L('اختر…', 'Choose…')}</option>{lk.locations.map(x => <option key={x.id} value={x.id}>{x[lang]}</option>)}</select></Field>
        <Field label={L('المسمى الوظيفي', 'Job title')} req error={e('title_ar')}><input className="field" value={d.title_ar} onChange={x => set('title_ar', x.target.value)} /></Field>
        <Field label={L('المسمى بالإنكليزية', 'Job title (EN)')} req error={e('title_en')}><input className="field ltr text-start" value={d.title_en} onChange={x => set('title_en', x.target.value)} /></Field>
        <Field label={L('المدير المباشر', 'Manager')} error={e('manager_id')} className="sm:col-span-2"><select className="field" value={d.manager_id} onChange={x => set('manager_id', x.target.value ? Number(x.target.value) : '')}>
          <option value="">{dept?.head ? L(`رئيس الإدارة تلقائياً — ${dept.head.ar}`, `Department head — ${dept.head.en}`) : L('رئيس الإدارة تلقائياً', 'Department head')}</option>
          {lk.managers.filter(m => !d.department_id || m.department_id === d.department_id).map(m => <option key={m.id} value={m.id}>{m[lang]} — {m.code}</option>)}</select></Field>
        <Field label={L('الراتب الشامل', 'Total pay')} req error={e('salary')}><div className="flex gap-2"><input type="number" min={0} className="field num flex-1" value={d.salary} onChange={x => set('salary', x.target.value === '' ? '' : Number(x.target.value))} />
          <select className="field !w-24" value={d.currency} onChange={x => set('currency', x.target.value)} aria-label={L('العملة', 'Currency')}>{lk.currencies.map(c => <option key={c}>{c}</option>)}</select></div></Field>
        <Field label={L('تاريخ المباشرة', 'Start date')} req error={e('hire_date')}><input type="date" className="field" value={d.hire_date} onChange={x => set('hire_date', x.target.value)} /></Field>
      </div>}
      {step === 2 && (lk.assets.length === 0 ? <p className="text-[14px] text-ink-3 py-6 text-center">{L('لا توجد عهد متاحة في المستودع — يمكن التسليم لاحقاً من صفحة المباشرة.', 'No assets in stock — you can issue later from the onboarding page.')}</p> :
        <div className="grid sm:grid-cols-2 gap-2 max-h-[340px] overflow-y-auto">{lk.assets.map(a => { const on = assets.includes(a.id)
          return <button key={a.id} type="button" aria-pressed={on} onClick={() => setAssets(on ? assets.filter(x => x !== a.id) : [...assets, a.id])} className={`flex items-center gap-3 rounded-xl border p-3 text-start ${on ? 'border-brand bg-brand-soft' : 'border-line'}`}>
            <span className={`size-5 rounded border grid place-items-center shrink-0 ${on ? 'bg-brand border-brand text-on-brand' : 'border-ink-3'}`}>{on && <Check size={13} />}</span>
            <span className="min-w-0"><span className="block text-[13px] font-semibold">{bi(a.name, lang)}</span><span className="block text-[11px] text-ink-3"><span className="ltr inline-block">{a.tag}</span> · {bi(a.category, lang)}</span></span></button> })}</div>)}
      {step === 3 && <div className="space-y-1.5 text-[14px]">{[[L('الاسم', 'Name'), lang === 'ar' ? d.name : d.name_en], [L('البريد', 'Email'), <span className="ltr inline-block">{d.email}</span>], [L('الإدارة', 'Department'), dept?.[lang]], [L('المسمى', 'Title'), lang === 'ar' ? d.title_ar : d.title_en],
        [L('الراتب', 'Pay'), <span className="num">{d.salary} {d.currency}</span>], [L('المباشرة', 'Start'), fmtDate(d.hire_date, lang)], [L('العهد', 'Assets'), assets.length || '—'], [L('كلمة مرور الدخول', 'Login password'), L('مؤقتة — تُرسل بالبريد', 'Temporary — sent by email')]].map(([k, v], i) =>
        <div key={i} className="flex justify-between gap-3 border-b border-line-2 py-1.5"><span className="text-ink-3">{k}</span><span className="font-semibold text-end">{v}</span></div>)}
        <p className="text-[12px] text-ink-3 pt-2">{L('يُنشأ الملف بحالة «قيد المباشرة» مع قائمة F-19، وتصل العهد للموظف ليوقّع استلامها.', 'The file starts in onboarding with the F-19 checklist; assets go to the employee for signature.')}</p>
        {err && !STEP_FIELDS.flat().some(f => err.field(f)) && <div role="alert" className="text-[13px] text-danger bg-danger-soft rounded px-3 py-2">{err.message}</div>}
      </div>}
    </>}
  </Modal>
}
