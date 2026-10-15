import { useState, type ReactNode } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Check, Upload, FileText, ArrowRight, ArrowLeft } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { api, ApiError } from '../../lib/api'
import { go } from '../../lib/router'
import { useJob } from '../../lib/public'
import { DEGREES } from '../../lib/recruitment'
import { Btn, Field, Empty } from '../../ui/kit'
import { PasswordFields } from '../../ui/password'
import { emptyForm, emptyExperience, validateStep, toFormData, stepOf, CV_MAX, type ApplyForm, type Errors, type YesNo } from './form'
import { setSubmission, type Submission } from './submission'

interface DupCheck { duplicate: boolean; blocked: boolean; account?: boolean; code?: string; field?: string; message: string | null }

/** M02 — معالج طلب التوظيف F-28 بأربع خطوات (منقول من Apply.tsx في الديمو) */
export function Apply({ code, refCode }: { code: string; refCode?: string }) {
  const L = useL(); const lang = useLang()
  const { data: job, isLoading, error } = useJob(code)
  const [step, setStep] = useState(0)
  const [f, setF] = useState<ApplyForm>(() => emptyForm(refCode))
  const [errs, setErrs] = useState<Errors>({})
  const [banner, setBanner] = useState('')
  const [dup, setDup] = useState<{ note: string; account: boolean }>({ note: '', account: false })
  const [checking, setChecking] = useState(false)

  const set = <K extends keyof ApplyForm>(k: K, v: ApplyForm[K]) => { setF(x => ({ ...x, [k]: v })); setErrs(e => ({ ...e, [k]: '' })); setBanner('') }
  const setExp = (i: number, k: keyof ApplyForm['experience'][number], v: string) => set('experience', f.experience.map((y, j) => j === i ? { ...y, [k]: v } : y))
  const steps = [L('البيانات الشخصية', 'Personal details'), L('المؤهلات والخبرات', 'Qualifications'), L('المراجع والأسئلة', 'References & questions'), L('المراجعة والإقرار', 'Review & declaration')]
  const goStep = (i: number) => { setStep(i); window.scrollTo(0, 0) }

  const submit = useMutation({
    mutationFn: () => api<{ data: Omit<Submission, 'password'> }>('/public/applications', { method: 'POST', body: toFormData(code, f) }),
    onSuccess: r => { setSubmission({ ...r.data, password: f.password }); go('applied') },
    onError: (e: Error) => {
      if (!(e instanceof ApiError)) { setBanner(L('تعذّر الاتصال بالخادم. تحقق من الشبكة وحاول مجدداً.', 'Could not reach the server. Check your connection and try again.')); return }
      const fields = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k === 'agree_info' ? 'agree_medical' : k.startsWith('experience.') ? 'experience' : k, v[0]]))
      setErrs(fields)
      if (e.code === 'existing_account') setDup(d => ({ ...d, account: true }))
      const keys = Object.keys(fields)
      if (keys.length) goStep(Math.min(...keys.map(stepOf)))
      if (!keys.length || e.code !== 'validation') setBanner(e.message)
    },
  })

  const next = async () => {
    const e = validateStep(step, f, L, dup.account); setErrs(e)
    if (Object.keys(e).length) return
    if (step === 0) {
      setChecking(true)
      try {
        const r = await api<DupCheck>('/public/applications/check-duplicate', { json: { national_id: f.national_id.trim(), email: f.email.trim() } })
        if (r.blocked) { if (r.field) setErrs({ [r.field]: r.message ?? '' }); else setBanner(r.message ?? ''); return }
        setDup({ note: r.message ?? '', account: !!r.account })
      } catch { /* الفحص المسبق اختياري — الخادم يعيد الفحص عند الإرسال */ } finally { setChecking(false) }
    }
    goStep(step + 1)
  }
  const send = () => { const e = validateStep(3, f, L, dup.account); setErrs(e); if (!Object.keys(e).length) submit.mutate() }

  const onFile = (file?: File) => {
    if (!file) return
    if (file.size > CV_MAX) { setErrs(e => ({ ...e, cv: L('الحد الأقصى لحجم الملف 10MB', 'Maximum file size is 10MB') })); return }
    set('cv', file)
  }

  if (isLoading) return <div className="max-w-5xl mx-auto px-5 md:px-8 py-6"><div className="rounded-2xl border border-line bg-surface h-72 animate-pulse" /></div>
  if (error || !job) return <div className="max-w-xl mx-auto px-5 py-10"><div className="rounded-2xl border border-line bg-surface"><Empty title={L('هذه الوظيفة غير متاحة', 'This position is not available')} action={<Btn onClick={() => go('jobs')}>{L('عرض الوظائف الشاغرة', 'See open positions')}</Btn>}>{error?.message}</Empty></div></div>

  const YN = ({ k, label }: { k: 'shifts' | 'travel' | 'public_sector' | 'conviction'; label: string }) => <div className="flex items-center justify-between gap-3 py-2 border-b border-line-2">
    <span className="text-[14px]">{label}</span>
    <div className="flex gap-1">{(['no', 'yes'] as YesNo[]).map(v => <button type="button" key={v} onClick={() => set(k, v)} className={`h-8 px-3 rounded-md border text-[13px] ${f[k] === v ? 'bg-ink text-canvas border-ink' : 'border-line bg-surface'}`}>{v === 'yes' ? L('نعم', 'Yes') : L('لا', 'No')}</button>)}</div>
  </div>
  const input = (k: keyof ApplyForm, extra: Partial<React.InputHTMLAttributes<HTMLInputElement>> = {}) =>
    <input {...extra} className={`field ${extra.className ?? ''}`} value={String(f[k] ?? '')} aria-invalid={!!errs[k]} onChange={e => set(k, e.target.value as never)} />

  return <div className="max-w-5xl mx-auto px-5 md:px-8 py-6 grid lg:grid-cols-[260px_1fr] gap-6 items-start">
    <aside className="lg:sticky lg:top-24 space-y-4">
      <button onClick={() => go('')} className="text-[13px] text-ink-3 hover:text-ink flex items-center gap-1">{lang === 'ar' ? <ArrowRight size={14} /> : <ArrowLeft size={14} />}{L('كل الوظائف', 'All positions')}</button>
      <div className="bg-surface border border-line rounded-2xl p-4">
        <div className="text-[12px] text-ink-3 ltr text-start">{job.code}</div>
        <h2 className="font-bold text-[17px] mt-0.5">{bi(job.title, lang)}</h2>
        <div className="text-[13px] text-ink-2 mt-1">{bi(job.city, lang)} — {bi(job.type, lang)}</div>
        <ul className="mt-3 space-y-1.5 text-[13px] text-ink-2">{job.requirements?.map((r, i) => <li key={i} className="flex gap-2"><Check size={14} className="text-brand mt-1 shrink-0" />{bi(r, lang)}</li>)}</ul>
      </div>
      <ol className="bg-surface border border-line rounded-2xl p-2">
        {steps.map((s, i) => <li key={i} className={`flex items-center gap-3 px-2 py-2 rounded ${i === step ? 'bg-brand-soft' : ''}`}>
          <span className={`size-6 rounded-full grid place-items-center text-[12px] font-semibold ${i < step ? 'bg-brand text-on-brand' : i === step ? 'border-2 border-brand text-brand' : 'border border-line text-ink-3'}`}>{i < step ? <Check size={13} /> : i + 1}</span>
          <span className={`text-[14px] ${i === step ? 'font-semibold' : 'text-ink-2'}`}>{s}</span></li>)}
      </ol>
      <p className="text-[12px] text-ink-3 px-1">{L('نموذج طلب التوظيف', 'Employment application form')} <span className="ltr">HR-01-F-28</span></p>
    </aside>

    <section className="bg-surface border border-line rounded-2xl min-w-0">
      <header className="px-6 py-4 border-b border-line-2">
        <div className="text-[12px] text-ink-3">{L(`الخطوة ${step + 1} من 4`, `Step ${step + 1} of 4`)}</div><h3 className="font-semibold text-[17px]">{steps[step]}</h3>
      </header>
      <div className="p-6">
        {banner && <div role="alert" className="mb-4 text-[13px] bg-danger-soft text-danger rounded px-3 py-2">{banner}</div>}

        {step === 0 && <div className="grid sm:grid-cols-2 gap-4">
          <Field label={L('الاسم الثلاثي', 'Full name')} req error={errs.name} className="sm:col-span-2">{input('name', { autoComplete: 'name' })}</Field>
          <Field label={L('الاسم بالإنكليزية', 'Name in English')} error={errs.name_en}>{input('name_en', { className: 'ltr text-start' })}</Field>
          <Field label={L('الجنس', 'Gender')} req error={errs.gender}><select className="field" value={f.gender} aria-invalid={!!errs.gender} onChange={e => set('gender', e.target.value as ApplyForm['gender'])}><option value="">—</option><option value="M">{L('ذكر', 'Male')}</option><option value="F">{L('أنثى', 'Female')}</option></select></Field>
          <Field label={L('الحالة الاجتماعية', 'Marital status')} req error={errs.marital}><select className="field" value={f.marital} aria-invalid={!!errs.marital} onChange={e => set('marital', e.target.value)}><option value="">—</option><option value="single">{L('أعزب/عزباء', 'Single')}</option><option value="married">{L('متزوج/ة', 'Married')}</option><option value="divorced">{L('مطلق/ة', 'Divorced')}</option><option value="widowed">{L('أرمل/ة', 'Widowed')}</option></select></Field>
          <Field label={L('الجنسية', 'Nationality')} req error={errs.nationality}>{input('nationality')}</Field>
          <Field label={L('تاريخ الولادة', 'Date of birth')} req error={errs.dob}>{input('dob', { type: 'date' })}</Field>
          <Field label={L('مكان الولادة', 'Place of birth')} error={errs.pob}>{input('pob')}</Field>
          <Field label={L('الرقم الوطني', 'National ID')} req error={errs.national_id} hint={L('يُستخدم للتحقق من عدم تكرار الطلب', 'Used to detect duplicate applications')}>{input('national_id', { className: 'ltr text-start', inputMode: 'numeric' })}</Field>
          <Field label={L('زمرة الدم', 'Blood type')} error={errs.blood}><select className="field" value={f.blood} onChange={e => set('blood', e.target.value)}><option value="">—</option>{['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'].map(b => <option key={b}>{b}</option>)}</select></Field>
          <Field label={L('الجوال', 'Mobile')} req error={errs.mobile}>{input('mobile', { className: 'ltr text-start', type: 'tel', autoComplete: 'tel' })}</Field>
          <Field label={L('البريد الإلكتروني', 'Email')} req error={errs.email} hint={L('سيكون اسم الدخول لمتابعة طلبك', 'This will be your login to track the application')}>{input('email', { className: 'ltr text-start', type: 'email', autoComplete: 'email' })}</Field>
          <Field label={L('عنوان السكن الحالي', 'Current address')} req error={errs.address} className="sm:col-span-2">{input('address')}</Field>
          <Field label="LinkedIn" className="sm:col-span-2" error={errs.linkedin}>{input('linkedin', { className: 'ltr text-start' })}</Field>
        </div>}

        {step === 1 && <div className="space-y-6">
          <div className="grid sm:grid-cols-2 gap-4">
            <Field label={L('أعلى مؤهل علمي', 'Highest qualification')}><select className="field" value={f.degree} onChange={e => set('degree', e.target.value)}>{DEGREES.map((d, i) => <option key={i} value={i}>{d[lang]}</option>)}</select></Field>
            <Field label={L('الجامعة / المعهد', 'University / institute')} req error={errs.institute}>{input('institute')}</Field>
            <Field label={L('الاختصاص', 'Major')} req error={errs.major}>{input('major')}</Field>
            <div className="grid grid-cols-2 gap-3"><Field label={L('سنة التخرج', 'Graduation year')} error={errs.grad_year}>{input('grad_year', { className: 'num', inputMode: 'numeric' })}</Field><Field label={L('التقدير', 'Grade')} error={errs.grade}>{input('grade')}</Field></div>
            <Field label={L('اللغة الإنكليزية', 'English')}><select className="field" value={f.english} onChange={e => set('english', e.target.value)}><option value="excellent">{L('ممتاز', 'Excellent')}</option><option value="good">{L('جيد', 'Good')}</option><option value="fair">{L('مقبول', 'Fair')}</option><option value="weak">{L('ضعيف', 'Weak')}</option></select></Field>
            <Field label={L('مجموع سنوات الخبرة', 'Total years of experience')} req error={errs.years_exp}>{input('years_exp', { type: 'number', min: 0, className: 'num' })}</Field>
          </div>
          <div>
            <h4 className="font-semibold mb-2">{L('الخبرات السابقة (الأحدث أولاً)', 'Work experience (most recent first)')}</h4>
            {f.experience.map((x, i) => <div key={i} className="grid sm:grid-cols-2 gap-3 border border-line-2 rounded-md p-3 mb-2">
              <Field label={L('الشركة', 'Company')}><input className="field" value={x.company} onChange={e => setExp(i, 'company', e.target.value)} /></Field>
              <Field label={L('المسمى الوظيفي', 'Position')}><input className="field" value={x.position} onChange={e => setExp(i, 'position', e.target.value)} /></Field>
              <div className="grid grid-cols-2 gap-3"><Field label={L('من سنة', 'From')}><input className="field num" inputMode="numeric" value={x.from} onChange={e => setExp(i, 'from', e.target.value)} /></Field><Field label={L('إلى سنة', 'To')}><input className="field num" inputMode="numeric" value={x.to} onChange={e => setExp(i, 'to', e.target.value)} /></Field></div>
              <Field label={L('سبب ترك العمل', 'Reason for leaving')}><input className="field" value={x.reason} onChange={e => setExp(i, 'reason', e.target.value)} /></Field>
            </div>)}
            {errs.experience && <span className="block text-[12px] text-danger mb-2">{errs.experience}</span>}
            {f.experience.length < 3 && <Btn size="sm" kind="ghost" onClick={() => set('experience', [...f.experience, emptyExperience()])}>+ {L('إضافة خبرة', 'Add experience')}</Btn>}
          </div>
        </div>}

        {step === 2 && <div className="space-y-6">
          <div><h4 className="font-semibold mb-2">{L('مرجع مهني (من غير الأقارب)', 'Professional reference (not a relative)')}</h4>
            <div className="grid sm:grid-cols-3 gap-3"><Field label={L('الاسم', 'Name')}>{input('ref_name')}</Field><Field label={L('العمل', 'Occupation')}>{input('ref_job')}</Field><Field label={L('الهاتف', 'Phone')}>{input('ref_phone', { className: 'ltr text-start', type: 'tel' })}</Field></div></div>
          <div><h4 className="font-semibold mb-1">{L('أسئلة الأهلية', 'Eligibility questions')}</h4>
            <YN k="shifts" label={L('هل تمانع العمل بنظام الورديات؟', 'Do you mind shift work?')} />
            <YN k="travel" label={L('هل تمانع السفر الداخلي أو الخارجي؟', 'Do you mind travelling?')} />
            <YN k="public_sector" label={L('هل تعمل حالياً في القطاع العام؟', 'Are you employed in the public sector?')} />
            <YN k="conviction" label={L('هل صدر بحقك حكم قضائي؟', 'Have you ever been convicted?')} />
          </div>
          <div className="grid sm:grid-cols-3 gap-4">
            <Field label={L('الراتب الشهري المتوقع (دولار)', 'Expected monthly salary (USD)')} req error={errs.salary}>{input('salary', { type: 'number', min: 1, className: 'num' })}</Field>
            <Field label={L('تاريخ الجاهزية للمباشرة', 'Available from')} error={errs.availability}>{input('availability', { type: 'date' })}</Field>
            <Field label={L('كيف عرفت بالوظيفة؟', 'How did you hear?')} error={errs.source}><select className="field" value={f.source} onChange={e => set('source', e.target.value)}><option value="website">{L('موقع الشركة', 'Company website')}</option><option value="linkedin">LinkedIn</option><option value="referral">{L('ترشيح من موظف', 'Employee referral')}</option><option value="expo">{L('المعرض', 'Exhibition')}</option></select></Field>
            {f.source === 'referral' && <Field label={L('رمز الإحالة', 'Referral code')} hint={L('من رابط الموظف الذي رشّحك، مثل UHR1007-J1', 'From the referring employee’s link, e.g. UHR1007-J1')} error={errs.ref_code}>{input('ref_code', { className: 'ltr text-start uppercase' })}</Field>}
          </div>
          <div>
            <span className="block text-[13px] font-medium text-ink-2 mb-1">{L('السيرة الذاتية والشهادات', 'CV & certificates')}</span>
            <label className="flex items-center gap-3 border border-dashed border-ink-3 rounded-md p-4 cursor-pointer hover:bg-sub"
              onDragOver={e => e.preventDefault()} onDrop={e => { e.preventDefault(); onFile(e.dataTransfer.files[0]) }}>
              {f.cv ? <FileText className="text-brand shrink-0" /> : <Upload className="text-ink-3 shrink-0" />}
              <div className="flex-1 min-w-0">{f.cv ? <><div className="font-medium truncate ltr text-start">{f.cv.name}</div><div className="text-[12px] text-ink-3">{Math.round(f.cv.size / 1024)} KB — {L('اضغط للاستبدال', 'click to replace')}</div></> : <><div className="font-medium">{L('اسحب الملف هنا أو اضغط للاختيار', 'Drop the file here or click to choose')}</div><div className="text-[12px] text-ink-3">PDF / DOC / DOCX — {L('حتى 10MB', 'up to 10MB')}</div></>}</div>
              <input type="file" accept=".pdf,.doc,.docx" className="hidden" onChange={e => onFile(e.target.files?.[0])} />
            </label>
            {errs.cv && <span className="text-[12px] text-danger">{errs.cv}</span>}
          </div>
        </div>}

        {step === 3 && <div className="space-y-5">
          {dup.note && <div className="text-[13px] bg-warn-soft text-warn rounded px-3 py-2">{dup.note}</div>}
          <dl className="grid sm:grid-cols-2 gap-x-6 text-[14px]">
            {([[L('الاسم', 'Name'), f.name], [L('الرقم الوطني', 'National ID'), f.national_id], [L('البريد', 'Email'), <span className="ltr">{f.email}</span>], [L('الجوال', 'Mobile'), <span className="ltr">{f.mobile}</span>], [L('المؤهل', 'Qualification'), `${DEGREES[Number(f.degree)][lang]} — ${f.major}`], [L('الخبرة', 'Experience'), `${f.years_exp} ${L('سنوات', 'years')}`], [L('الراتب المتوقع', 'Expected salary'), `$${f.salary}`], [L('السيرة الذاتية', 'CV'), f.cv?.name ?? L('لم تُرفق', 'Not attached')]] as [string, ReactNode][]).map(([k, v]) =>
              <div key={k} className="flex justify-between gap-3 py-2 border-b border-line-2 min-w-0"><dt className="text-ink-3 shrink-0">{k}</dt><dd className="font-medium text-end truncate">{v}</dd></div>)}
          </dl>
          {dup.account
            ? <div className="grid sm:grid-cols-2 gap-4"><Field label={L('كلمة مرور حسابك الحالي', 'Your current account password')} req error={errs.password} hint={<a className="underline" href="#/forgot">{L('نسيت كلمة المرور؟', 'Forgot your password?')}</a>}><input type="password" className="field ltr text-start" value={f.password} aria-invalid={!!errs.password} onChange={e => set('password', e.target.value)} autoComplete="current-password" /></Field></div>
            : <div className="grid sm:grid-cols-2 gap-4 items-start"><PasswordFields label={L('أنشئ كلمة مرور لمتابعة طلبك', 'Create a password to track your application')} v={f} set={(k, x) => set(k, x)} errs={{ password: errs.password, password_confirmation: errs.password_confirmation }} /></div>}
          <div className="bg-canvas rounded-md p-4 space-y-3 text-[14px]">
            <label className="flex gap-3"><input type="checkbox" className="mt-1 accent-[var(--primary)]" checked={f.agree_info} onChange={e => set('agree_info', e.target.checked)} /><span>{L('أقر بأن جميع المعلومات الواردة صحيحة، وأن أي معلومة غير صحيحة أو ناقصة تكون سبباً لإنهاء التعيين في حال تمّ.', 'I confirm that all information is true. Any false or missing information is grounds for termination if hired.')}</span></label>
            <label className="flex gap-3"><input type="checkbox" className="mt-1 accent-[var(--primary)]" checked={f.agree_medical} onChange={e => set('agree_medical', e.target.checked)} /><span>{L('أوافق على سياسة الفحص الطبي وأنظمة الشركة الداخلية.', 'I accept the medical examination policy and company regulations.')}</span></label>
            {errs.agree_medical && <div className="text-[12px] text-danger">{errs.agree_medical}</div>}
          </div>
        </div>}
      </div>
      <footer className="px-6 py-4 border-t border-line-2 flex justify-between">
        {step > 0 ? <Btn onClick={() => goStep(step - 1)} disabled={submit.isPending}>{L('السابق', 'Back')}</Btn> : <span />}
        {step < 3 ? <Btn kind="primary" onClick={next} disabled={checking}>{L('التالي', 'Next')}</Btn>
          : <Btn kind="primary" onClick={send} disabled={submit.isPending}>{submit.isPending ? L('جارٍ الإرسال…', 'Submitting…') : L('إرسال الطلب', 'Submit application')}</Btn>}
      </footer>
    </section>
  </div>
}
