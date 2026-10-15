import { useState } from 'react'
import { Check, Upload, FileText, ArrowRight, ArrowLeft, Copy } from 'lucide-react'
import { useStore, useL, useLang, bi, fmtDate, getState, useRelaxed } from '../lib/store'
import { submitApplication, findDuplicate, login } from '../lib/actions'
import { useNav } from '../lib/nav'
import { Btn, Field, Badge } from '../ui/kit'
import { DEGREES } from '../lib/rules'
import type { Experience } from '../lib/types'

type Form = {
  name: string; nameEn: string; gender: '' | 'M' | 'F'; marital: string; nationality: string; dob: string; pob: string; nationalId: string; blood: string
  mobile: string; mobile2: string; email: string; address: string; linkedin: string
  degree: string; institute: string; major: string; year: string; grade: string; english: string; arabic: string; yearsExp: string
  exp: Experience[]
  refName: string; refJob: string; refPhone: string
  shifts: string; travel: string; publicSector: string; conviction: string; salary: string; availability: string; source: string; refCode: string
  cvName?: string; cvSize?: number; cvData?: string
  password: string; password2: string; agree1: boolean; agree2: boolean
}
const empty: Form = { name: '', nameEn: '', gender: '', marital: '', nationality: 'سورية', dob: '', pob: '', nationalId: '', blood: '', mobile: '+963 ', mobile2: '', email: '', address: '', linkedin: '',
  degree: '2', institute: '', major: '', year: '', grade: '', english: 'good', arabic: 'native', yearsExp: '',
  exp: [{ company: '', position: '', from: '', to: '', tasks: '', reason: '' }], refName: '', refJob: '', refPhone: '',
  shifts: 'no', travel: 'no', publicSector: 'no', conviction: 'no', salary: '', availability: '', source: 'website', refCode: '', password: '', password2: '', agree1: false, agree2: false }

const sample = (): Partial<Form> => {
  const n = Math.floor(Math.random() * 900 + 100)
  return { name: 'زائر المعرض ' + n, nameEn: 'Expo Visitor ' + n, gender: 'M', marital: 'single', dob: '1995-04-12', pob: 'دمشق', nationalId: '0' + (1e9 + Math.floor(Math.random() * 8e9)), blood: 'O+',
    mobile: '+963 944 ' + n + ' 210', email: `visitor${n}@mail.sy`, address: 'دمشق — المزة', degree: '2', institute: 'جامعة دمشق', major: 'هندسة معلوماتية', year: '2018', grade: 'جيد جداً', english: 'good', yearsExp: '6',
    exp: [{ company: 'شركة الشام للتقنية', position: 'مطور برمجيات', from: '2019', to: '2026', tasks: 'تطوير أنظمة داخلية', reason: 'تطور مهني' }], refName: 'م. سليم حداد', refJob: 'مدير سابق', refPhone: '+963 944 120 330', salary: '1400', availability: '' }
}

export function Apply({ jobId }: { jobId: string }) {
  const L = useL(); const lang = useLang(); const { go } = useNav()
  const job = useStore(s => s.jobs.find(j => j.id === jobId))!
  const [step, setStep] = useState(0)
  const [f, setF] = useState<Form>(() => { const r = new URLSearchParams(location.search).get('ref'); return r ? { ...empty, source: 'referral', refCode: r } : empty })
  const [errs, setErrs] = useState<Record<string, string>>({})
  const [dupNote, setDupNote] = useState('')
  const set = <K extends keyof Form>(k: K, v: Form[K]) => { setF(x => ({ ...x, [k]: v })); setErrs(e => ({ ...e, [k]: '' })) }
  const steps = [L('البيانات الشخصية', 'Personal details'), L('المؤهلات والخبرات', 'Qualifications'), L('المراجع والأسئلة', 'References & questions'), L('المراجعة والإقرار', 'Review & declaration')]
  const req = L('هذا الحقل مطلوب', 'This field is required')
  const relaxed = useRelaxed()

  const validate = (i: number) => {
    if (relaxed) { setErrs({}); return true }
    const e: Record<string, string> = {}
    if (i === 0) {
      ;(['name', 'gender', 'marital', 'nationality', 'dob', 'nationalId', 'email', 'address'] as const).forEach(k => { if (!String(f[k]).trim()) e[k] = req })
      if (f.mobile.replace(/\D/g, '').length < 10) e.mobile = L('أدخل رقم الجوال كاملاً مع رمز الدولة', 'Enter the full mobile number with country code')
      if (f.email && !/^\S+@\S+\.\S+$/.test(f.email)) e.email = L('صيغة البريد غير صحيحة', 'Invalid email format')
    }
    if (i === 1) { if (!f.institute.trim()) e.institute = req; if (!f.major.trim()) e.major = req; if (f.yearsExp === '') e.yearsExp = req }
    if (i === 2) { if (!f.salary || Number(f.salary) <= 0) e.salary = L('حدد الراتب المتوقع بالدولار', 'Enter expected salary in USD'); if (f.source === 'referral' && f.refCode && !getState().referrals.some(r => r.code.toUpperCase() === f.refCode.trim().toUpperCase())) e.refCode = L('رمز الإحالة غير موجود. تحقق منه مع الموظف.', 'Referral code not found. Check it with the employee.') }
    if (i === 3) {
      if (f.password.length < 6) e.password = L('6 أحرف على الأقل', 'At least 6 characters')
      if (f.password !== f.password2) e.password2 = L('كلمتا المرور غير متطابقتين', 'Passwords do not match')
      if (!f.agree1 || !f.agree2) e.agree = L('يجب الموافقة على الإقرارين لإرسال الطلب', 'You must accept both declarations to submit')
    }
    setErrs(e); return Object.keys(e).length === 0
  }
  const next = () => {
    if (!validate(step)) return
    if (step === 0) { const d = findDuplicate(f.nationalId, f.email); setDupNote(d ? L(`يوجد طلب سابق بنفس الرقم الوطني أو البريد (${d.ref}). سيُلحق هذا الطلب بملفك الحالي.`, `A previous application exists with this ID or email (${d.ref}). This one will be added to your existing file.`) : '') }
    setStep(step + 1); window.scrollTo(0, 0)
  }
  const submit = () => {
    if (!validate(3)) return
    const n = Math.floor(100 + Math.random() * 900)
    const fx = { ...f, name: f.name.trim() || `زائر المعرض ${n}`, nameEn: f.nameEn.trim() || (f.name.trim() ? '' : `Expo Visitor ${n}`), email: f.email.trim() || `visitor${n}@mail.sy`, password: f.password || '123456', nationalId: f.nationalId.trim() || `0${Date.now() % 1e10}`, gender: (f.gender || 'M') as 'M' | 'F', marital: f.marital || 'single', dob: f.dob || '1996-01-01', salary: f.salary || '900', yearsExp: f.yearsExp || '3', institute: f.institute || 'جامعة دمشق', major: f.major || 'إدارة أعمال' }
    const c = submitApplication({
      jobId, name: fx.name.trim(), nameEn: fx.nameEn.trim(), gender: fx.gender as 'M' | 'F', marital: fx.marital, nationality: f.nationality, dob: fx.dob, pob: f.pob, nationalId: fx.nationalId.trim(), blood: f.blood,
      email: fx.email.trim().toLowerCase(), mobile: f.mobile, mobile2: f.mobile2, address: f.address, linkedin: f.linkedin,
      education: [{ phase: DEGREES[Number(f.degree)].ar, institute: fx.institute, major: fx.major, year: f.year, grade: f.grade }],
      experience: f.exp.filter(x => x.company.trim()), refs: f.refName ? [{ name: f.refName, job: f.refJob, phone: f.refPhone }] : [],
      english: f.english, arabic: f.arabic, shifts: f.shifts === 'yes', travel: f.travel === 'yes', publicSector: f.publicSector === 'yes', conviction: f.conviction === 'yes',
      salary: Number(fx.salary), availability: f.availability, source: f.source, yearsExp: Number(fx.yearsExp), degreeLevel: Number(f.degree),
      cvName: f.cvName, cvSize: f.cvSize, cvData: f.cvData, password: fx.password, visitor: true, refCode: f.source === 'referral' ? f.refCode : undefined,
    })
    go('applied', c.id)
  }
  const onFile = (file?: File) => {
    if (!file) return
    if (file.size > 10 * 1024 * 1024) { setErrs(e => ({ ...e, cv: L('الحد الأقصى لحجم الملف 10MB', 'Maximum file size is 10MB') })); return }
    set('cvName', file.name); set('cvSize', file.size)
    if (file.size < 1.2 * 1024 * 1024) { const r = new FileReader(); r.onload = () => set('cvData', String(r.result)); r.readAsDataURL(file) }
  }
  const YN = ({ k, label }: { k: 'shifts' | 'travel' | 'publicSector' | 'conviction'; label: string }) => <div className="flex items-center justify-between gap-3 py-2 border-b border-line-2">
    <span className="text-[14px]">{label}</span>
    <div className="flex gap-1">{(['no', 'yes'] as const).map(v => <button type="button" key={v} onClick={() => set(k, v)} className={`h-8 px-3 rounded-md border text-[13px] ${f[k] === v ? 'bg-ink text-canvas border-ink' : 'border-line bg-surface'}`}>{v === 'yes' ? L('نعم', 'Yes') : L('لا', 'No')}</button>)}</div>
  </div>

  return <div className="max-w-5xl mx-auto px-5 md:px-8 py-6 grid lg:grid-cols-[260px_1fr] gap-6 items-start">
    <aside className="lg:sticky lg:top-6 space-y-4">
      <button onClick={() => go('login')} className="text-[13px] text-ink-3 hover:text-ink flex items-center gap-1">{lang === 'ar' ? <ArrowRight size={14} /> : <ArrowLeft size={14} />}{L('كل الوظائف', 'All positions')}</button>
      <div className="bg-surface border border-line rounded-2xl p-4">
        <div className="text-[12px] text-ink-3 ltr text-start">{job.code}</div>
        <h2 className="font-bold text-[17px] mt-0.5">{bi(job.title, lang)}</h2>
        <div className="text-[13px] text-ink-2 mt-1">{bi(job.city, lang)} — {bi(job.type, lang)}</div>
        <ul className="mt-3 space-y-1.5 text-[13px] text-ink-2">{job.reqs.map((r, i) => <li key={i} className="flex gap-2"><Check size={14} className="text-brand mt-1 shrink-0" />{bi(r, lang)}</li>)}</ul>
      </div>
      <ol className="bg-surface border border-line rounded-2xl p-2">
        {steps.map((s, i) => <li key={i} className={`flex items-center gap-3 px-2 py-2 rounded ${i === step ? 'bg-brand-soft' : ''}`}>
          <span className={`size-6 rounded-full grid place-items-center text-[12px] font-semibold ${i < step ? 'bg-brand text-on-brand' : i === step ? 'border-2 border-brand text-brand' : 'border border-line text-ink-3'}`}>{i < step ? <Check size={13} /> : i + 1}</span>
          <span className={`text-[14px] ${i === step ? 'font-semibold' : 'text-ink-2'}`}>{s}</span></li>)}
      </ol>
      <p className="text-[12px] text-ink-3 px-1">{L('نموذج طلب التوظيف', 'Employment application form')} <span className="ltr">HR-01-F-28</span></p>
    </aside>

    <section className="bg-surface border border-line rounded-2xl">
      <header className="px-6 py-4 border-b border-line-2 flex items-center justify-between gap-3">
        <div><div className="text-[12px] text-ink-3">{L(`الخطوة ${step + 1} من 4`, `Step ${step + 1} of 4`)}</div><h3 className="font-semibold text-[17px]">{steps[step]}</h3></div>
        {step === 0 && <button type="button" onClick={() => setF(x => ({ ...x, ...sample() }))} className="text-[12px] text-ink-3 underline underline-offset-2 hover:text-ink">{L('تعبئة ببيانات تجريبية', 'Fill with sample data')}</button>}
      </header>
      <div className="p-6">
        {step === 0 && <div className="grid sm:grid-cols-2 gap-4">
          <Field label={L('الاسم الثلاثي', 'Full name')} req error={errs.name} className="sm:col-span-2"><input className="field" value={f.name} aria-invalid={!!errs.name} onChange={e => set('name', e.target.value)} /></Field>
          <Field label={L('الاسم بالإنكليزية', 'Name in English')}><input className="field ltr text-start" value={f.nameEn} onChange={e => set('nameEn', e.target.value)} /></Field>
          <Field label={L('الجنس', 'Gender')} req error={errs.gender}><select className="field" value={f.gender} aria-invalid={!!errs.gender} onChange={e => set('gender', e.target.value as Form['gender'])}><option value="">—</option><option value="M">{L('ذكر', 'Male')}</option><option value="F">{L('أنثى', 'Female')}</option></select></Field>
          <Field label={L('الحالة الاجتماعية', 'Marital status')} req error={errs.marital}><select className="field" value={f.marital} aria-invalid={!!errs.marital} onChange={e => set('marital', e.target.value)}><option value="">—</option><option value="single">{L('أعزب/عزباء', 'Single')}</option><option value="married">{L('متزوج/ة', 'Married')}</option><option value="divorced">{L('مطلق/ة', 'Divorced')}</option><option value="widowed">{L('أرمل/ة', 'Widowed')}</option></select></Field>
          <Field label={L('الجنسية', 'Nationality')} req error={errs.nationality}><input className="field" value={f.nationality} onChange={e => set('nationality', e.target.value)} /></Field>
          <Field label={L('تاريخ الولادة', 'Date of birth')} req error={errs.dob}><input type="date" className="field" value={f.dob} aria-invalid={!!errs.dob} onChange={e => set('dob', e.target.value)} /></Field>
          <Field label={L('مكان الولادة', 'Place of birth')}><input className="field" value={f.pob} onChange={e => set('pob', e.target.value)} /></Field>
          <Field label={L('الرقم الوطني', 'National ID')} req error={errs.nationalId} hint={L('يُستخدم للتحقق من عدم تكرار الطلب', 'Used to detect duplicate applications')}><input className="field ltr text-start" value={f.nationalId} aria-invalid={!!errs.nationalId} onChange={e => set('nationalId', e.target.value)} /></Field>
          <Field label={L('زمرة الدم', 'Blood type')}><select className="field" value={f.blood} onChange={e => set('blood', e.target.value)}><option value="">—</option>{['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'].map(b => <option key={b}>{b}</option>)}</select></Field>
          <Field label={L('الجوال', 'Mobile')} req error={errs.mobile}><input className="field ltr text-start" value={f.mobile} aria-invalid={!!errs.mobile} onChange={e => set('mobile', e.target.value)} /></Field>
          <Field label={L('البريد الإلكتروني', 'Email')} req error={errs.email} hint={L('سيكون اسم الدخول لمتابعة طلبك', 'This will be your login to track the application')}><input className="field ltr text-start" type="email" value={f.email} aria-invalid={!!errs.email} onChange={e => set('email', e.target.value)} /></Field>
          <Field label={L('عنوان السكن الحالي', 'Current address')} req error={errs.address} className="sm:col-span-2"><input className="field" value={f.address} aria-invalid={!!errs.address} onChange={e => set('address', e.target.value)} /></Field>
          <Field label="LinkedIn" className="sm:col-span-2"><input className="field ltr text-start" value={f.linkedin} onChange={e => set('linkedin', e.target.value)} /></Field>
        </div>}

        {step === 1 && <div className="space-y-6">
          <div className="grid sm:grid-cols-2 gap-4">
            <Field label={L('أعلى مؤهل علمي', 'Highest qualification')}><select className="field" value={f.degree} onChange={e => set('degree', e.target.value)}>{DEGREES.map((d, i) => <option key={i} value={i}>{d[lang]}</option>)}</select></Field>
            <Field label={L('الجامعة / المعهد', 'University / institute')} req error={errs.institute}><input className="field" value={f.institute} aria-invalid={!!errs.institute} onChange={e => set('institute', e.target.value)} /></Field>
            <Field label={L('الاختصاص', 'Major')} req error={errs.major}><input className="field" value={f.major} aria-invalid={!!errs.major} onChange={e => set('major', e.target.value)} /></Field>
            <div className="grid grid-cols-2 gap-3"><Field label={L('سنة التخرج', 'Graduation year')}><input className="field num" value={f.year} onChange={e => set('year', e.target.value)} /></Field><Field label={L('التقدير', 'Grade')}><input className="field" value={f.grade} onChange={e => set('grade', e.target.value)} /></Field></div>
            <Field label={L('اللغة الإنكليزية', 'English')}><select className="field" value={f.english} onChange={e => set('english', e.target.value)}><option value="excellent">{L('ممتاز', 'Excellent')}</option><option value="good">{L('جيد', 'Good')}</option><option value="fair">{L('مقبول', 'Fair')}</option><option value="weak">{L('ضعيف', 'Weak')}</option></select></Field>
            <Field label={L('مجموع سنوات الخبرة', 'Total years of experience')} req error={errs.yearsExp}><input type="number" min={0} className="field num" value={f.yearsExp} aria-invalid={!!errs.yearsExp} onChange={e => set('yearsExp', e.target.value)} /></Field>
          </div>
          <div>
            <h4 className="font-semibold mb-2">{L('الخبرات السابقة (الأحدث أولاً)', 'Work experience (most recent first)')}</h4>
            {f.exp.map((x, i) => <div key={i} className="grid sm:grid-cols-2 gap-3 border border-line-2 rounded-md p-3 mb-2">
              <Field label={L('الشركة', 'Company')}><input className="field" value={x.company} onChange={e => set('exp', f.exp.map((y, j) => j === i ? { ...y, company: e.target.value } : y))} /></Field>
              <Field label={L('المسمى الوظيفي', 'Position')}><input className="field" value={x.position} onChange={e => set('exp', f.exp.map((y, j) => j === i ? { ...y, position: e.target.value } : y))} /></Field>
              <div className="grid grid-cols-2 gap-3"><Field label={L('من سنة', 'From')}><input className="field num" value={x.from} onChange={e => set('exp', f.exp.map((y, j) => j === i ? { ...y, from: e.target.value } : y))} /></Field><Field label={L('إلى سنة', 'To')}><input className="field num" value={x.to} onChange={e => set('exp', f.exp.map((y, j) => j === i ? { ...y, to: e.target.value } : y))} /></Field></div>
              <Field label={L('سبب ترك العمل', 'Reason for leaving')}><input className="field" value={x.reason} onChange={e => set('exp', f.exp.map((y, j) => j === i ? { ...y, reason: e.target.value } : y))} /></Field>
            </div>)}
            {f.exp.length < 3 && <Btn size="sm" kind="ghost" onClick={() => set('exp', [...f.exp, { company: '', position: '', from: '', to: '', tasks: '', reason: '' }])}>+ {L('إضافة خبرة', 'Add experience')}</Btn>}
          </div>
        </div>}

        {step === 2 && <div className="space-y-6">
          <div><h4 className="font-semibold mb-2">{L('مرجع مهني (من غير الأقارب)', 'Professional reference (not a relative)')}</h4>
            <div className="grid sm:grid-cols-3 gap-3"><Field label={L('الاسم', 'Name')}><input className="field" value={f.refName} onChange={e => set('refName', e.target.value)} /></Field><Field label={L('العمل', 'Occupation')}><input className="field" value={f.refJob} onChange={e => set('refJob', e.target.value)} /></Field><Field label={L('الهاتف', 'Phone')}><input className="field ltr text-start" value={f.refPhone} onChange={e => set('refPhone', e.target.value)} /></Field></div></div>
          <div><h4 className="font-semibold mb-1">{L('أسئلة الأهلية', 'Eligibility questions')}</h4>
            <YN k="shifts" label={L('هل تمانع العمل بنظام الورديات؟', 'Do you mind shift work?')} />
            <YN k="travel" label={L('هل تمانع السفر الداخلي أو الخارجي؟', 'Do you mind travelling?')} />
            <YN k="publicSector" label={L('هل تعمل حالياً في القطاع العام؟', 'Are you employed in the public sector?')} />
            <YN k="conviction" label={L('هل صدر بحقك حكم قضائي؟', 'Have you ever been convicted?')} />
          </div>
          <div className="grid sm:grid-cols-3 gap-4">
            <Field label={L('الراتب الشهري المتوقع (دولار)', 'Expected monthly salary (USD)')} req error={errs.salary}><input type="number" className="field num" value={f.salary} aria-invalid={!!errs.salary} onChange={e => set('salary', e.target.value)} /></Field>
            <Field label={L('تاريخ الجاهزية للمباشرة', 'Available from')}><input type="date" className="field" value={f.availability} onChange={e => set('availability', e.target.value)} /></Field>
            <Field label={L('كيف عرفت بالوظيفة؟', 'How did you hear?')}><select className="field" value={f.source} onChange={e => set('source', e.target.value)}><option value="website">{L('موقع الشركة', 'Company website')}</option><option value="linkedin">LinkedIn</option><option value="referral">{L('ترشيح من موظف', 'Employee referral')}</option><option value="expo">{L('المعرض', 'Exhibition')}</option></select></Field>
            {f.source === 'referral' && <Field label={L('رمز الإحالة', 'Referral code')} hint={L('من رابط الموظف الذي رشّحك، مثل UHR1007-J1', 'From the referring employee’s link, e.g. UHR1007-J1')} error={errs.refCode}><input className="field ltr text-start uppercase" value={f.refCode} onChange={e => set('refCode', e.target.value)} /></Field>}
          </div>
          <div>
            <span className="block text-[13px] font-medium text-ink-2 mb-1">{L('السيرة الذاتية والشهادات', 'CV & certificates')}</span>
            <label className="flex items-center gap-3 border border-dashed border-ink-3 rounded-md p-4 cursor-pointer hover:bg-sub"
              onDragOver={e => e.preventDefault()} onDrop={e => { e.preventDefault(); onFile(e.dataTransfer.files[0]) }}>
              {f.cvName ? <FileText className="text-brand" /> : <Upload className="text-ink-3" />}
              <div className="flex-1 min-w-0">{f.cvName ? <><div className="font-medium truncate ltr text-start">{f.cvName}</div><div className="text-[12px] text-ink-3">{Math.round((f.cvSize ?? 0) / 1024)} KB — {L('اضغط للاستبدال', 'click to replace')}</div></> : <><div className="font-medium">{L('اسحب الملف هنا أو اضغط للاختيار', 'Drop the file here or click to choose')}</div><div className="text-[12px] text-ink-3">PDF / DOCX — {L('حتى 10MB', 'up to 10MB')}</div></>}</div>
              <input type="file" accept=".pdf,.doc,.docx" className="hidden" onChange={e => onFile(e.target.files?.[0])} />
            </label>
            {errs.cv && <span className="text-[12px] text-danger">{errs.cv}</span>}
          </div>
        </div>}

        {step === 3 && <div className="space-y-5">
          {dupNote && <div className="text-[13px] bg-warn-soft text-warn rounded px-3 py-2">{dupNote}</div>}
          <dl className="grid sm:grid-cols-2 gap-x-6 text-[14px]">
            {[[L('الاسم', 'Name'), f.name], [L('الرقم الوطني', 'National ID'), f.nationalId], [L('البريد', 'Email'), f.email], [L('الجوال', 'Mobile'), f.mobile], [L('المؤهل', 'Qualification'), `${DEGREES[Number(f.degree)][lang]} — ${f.major}`], [L('الخبرة', 'Experience'), `${f.yearsExp} ${L('سنوات', 'years')}`], [L('الراتب المتوقع', 'Expected salary'), `$${f.salary}`], [L('السيرة الذاتية', 'CV'), f.cvName ?? L('لم تُرفق', 'Not attached')]].map(([k, v]) =>
              <div key={k} className="flex justify-between gap-3 py-2 border-b border-line-2"><dt className="text-ink-3">{k}</dt><dd className="font-medium text-end">{v}</dd></div>)}
          </dl>
          <div className="grid sm:grid-cols-2 gap-4">
            <Field label={L('أنشئ كلمة مرور لمتابعة طلبك', 'Create a password to track your application')} hint={relaxed ? L('إذا تركتها فارغة تكون 123456', 'Leave empty to use 123456') : undefined} req error={errs.password}><input type="password" className="field" value={f.password} aria-invalid={!!errs.password} onChange={e => set('password', e.target.value)} /></Field>
            <Field label={L('تأكيد كلمة المرور', 'Confirm password')} req error={errs.password2}><input type="password" className="field" value={f.password2} aria-invalid={!!errs.password2} onChange={e => set('password2', e.target.value)} /></Field>
          </div>
          <div className="bg-canvas rounded-md p-4 space-y-3 text-[14px]">
            <label className="flex gap-3"><input type="checkbox" className="mt-1 accent-[var(--primary)]" checked={f.agree1} onChange={e => set('agree1', e.target.checked)} /><span>{L('أقر بأن جميع المعلومات الواردة صحيحة، وأن أي معلومة غير صحيحة أو ناقصة تكون سبباً لإنهاء التعيين في حال تمّ.', 'I confirm that all information is true. Any false or missing information is grounds for termination if hired.')}</span></label>
            <label className="flex gap-3"><input type="checkbox" className="mt-1 accent-[var(--primary)]" checked={f.agree2} onChange={e => set('agree2', e.target.checked)} /><span>{L('أوافق على سياسة الفحص الطبي وأنظمة الشركة الداخلية.', 'I accept the medical examination policy and company regulations.')}</span></label>
            {errs.agree && <div className="text-[12px] text-danger">{errs.agree}</div>}
          </div>
        </div>}
      </div>
      <footer className="px-6 py-4 border-t border-line-2 flex justify-between">
        {step > 0 ? <Btn onClick={() => setStep(step - 1)}>{L('السابق', 'Back')}</Btn> : <span />}
        {step < 3 ? <Btn kind="primary" onClick={next}>{L('التالي', 'Next')}</Btn> : <Btn kind="primary" onClick={submit}>{L('إرسال الطلب', 'Submit application')}</Btn>}
      </footer>
    </section>
  </div>
}

export function Applied({ candId }: { candId: string }) {
  const L = useL(); const lang = useLang(); const { go } = useNav()
  const c = useStore(s => s.candidates.find(x => x.id === candId))!
  const job = useStore(s => s.jobs.find(j => j.id === c.jobId))!
  return <div className="max-w-xl mx-auto px-5 py-12">
    <div className="bg-surface border border-line rounded-2xl p-8 text-center">
      <div className="size-12 rounded-full bg-brand text-on-brand grid place-items-center mx-auto"><Check /></div>
      <h1 className="text-[20px] font-bold mt-4">{L('استلمنا طلبك', 'We received your application')}</h1>
      <p className="text-ink-2 mt-1">{L(`طلبك على وظيفة ${job.title.ar} قيد المراجعة الآن لدى فريق التوظيف.`, `Your application for ${job.title.en} is now with the recruitment team.`)}</p>
      <div className="mt-6 inline-flex items-center gap-2 border border-line rounded-md px-4 py-2">
        <span className="text-[13px] text-ink-3">{L('رقم المتابعة', 'Reference')}</span><span className="font-bold ltr num">{c.ref}</span>
        <button onClick={() => navigator.clipboard?.writeText(c.ref)} className="text-ink-3 hover:text-ink" aria-label="copy"><Copy size={15} /></button>
      </div>
      <div className="text-[13px] text-ink-3 mt-3">{L('أُرسل تأكيد بالرقم إلى', 'Confirmation sent to')} <span className="ltr">{c.email}</span> — {fmtDate(c.createdAt, lang)}</div>
      <div className="flex justify-center gap-2 mt-6">
        <Btn kind="primary" onClick={() => login(c.email, c.password!)}>{L('متابعة حالة الطلب', 'Track my application')}</Btn>
        <Btn onClick={() => go('login')}>{L('العودة للصفحة الرئيسية', 'Back to home')}</Btn>
      </div>
      <div className="mt-6"><Badge tone="info">{L('الخطوة التالية: الفرز الأولي من الموارد البشرية', 'Next: HR screening')}</Badge></div>
    </div>
  </div>
}
