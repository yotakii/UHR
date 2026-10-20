import { useState } from 'react'
import { Loader2, Nfc, Unlink } from 'lucide-react'
import { useL, useLang } from '../../../lib/store'
import { ApiError } from '../../../lib/api'
import { useLookups } from '../../../lib/onboarding'
import { useLinkCard, useUpdateProfile, type EmpFile, type ProfileInput } from '../../../lib/employees'
import { Btn, Field, Modal, Seg, toast } from '../../../ui/kit'
import { analyse, normUid, useCardReader } from '../../../lib/cardReader'

/** تعديل الملف الوظيفي (M07): الشخصي والوظيفي — الراتب لا يُعدَّل من هنا (محمي بخطوتين) */
export function EditProfile({ e, onClose }: { e: EmpFile; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const { data: lk } = useLookups()
  const save = useUpdateProfile(e.id)
  const [d, setD] = useState<ProfileInput>({ name: e.name_ar, name_en: e.name_en, gender: e.gender, title_ar: e.title_ar, title_en: e.title_en, department_id: e.department_id, location_id: e.location_id,
    manager_id: e.manager_id, email: e.email, phone: e.phone ?? '', national_id: e.national_id ?? '', dob: e.dob ?? '' })
  const err = save.error instanceof ApiError ? save.error : null
  const x = (k: string) => err?.field(k)
  const set = <K extends keyof ProfileInput>(k: K, v: ProfileInput[K]) => { setD(p => ({ ...p, [k]: v })); if (err) save.reset() }
  const missing = !d.name.trim() || !d.name_en.trim() || !d.title_ar.trim() || !d.title_en.trim() || !/^\S+@\S+\.\S+$/.test(d.email)
  const submit = () => save.mutate({ ...d, national_id: d.national_id.trim(), phone: d.phone.trim() }, {
    onSuccess: () => { toast(L('حُفظت البيانات', 'Details saved')); onClose() },
    onError: y => { if (!(y instanceof ApiError) || !Object.keys(y.errors).length) toast((y as Error).message) },
  })
  return <Modal open onClose={() => !save.isPending && onClose()} title={L('تعديل بيانات الموظف', 'Edit employee details')} width={680}
    footer={<><Btn onClick={onClose} disabled={save.isPending}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={missing || save.isPending} icon={save.isPending ? <Loader2 size={16} className="animate-spin" /> : undefined} onClick={submit}>{L('حفظ', 'Save')}</Btn></>}>
    {!lk ? <div className="grid place-items-center py-10"><Loader2 className="animate-spin text-brand" /></div> : <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('الاسم الكامل', 'Full name')} req error={x('name')}><input className="field" value={d.name} onChange={v => set('name', v.target.value)} /></Field>
      <Field label={L('الاسم بالإنكليزية', 'Name (EN)')} req error={x('name_en')}><input className="field ltr text-start" value={d.name_en} onChange={v => set('name_en', v.target.value)} /></Field>
      <Field label={L('الجنس', 'Gender')} div error={x('gender')}><Seg value={d.gender} onChange={v => set('gender', v)} options={[{ v: 'M', label: L('ذكر', 'Male') }, { v: 'F', label: L('أنثى', 'Female') }]} /></Field>
      <Field label={L('الرقم الوطني', 'National ID')} error={x('national_id')}><input className="field ltr text-start num" inputMode="numeric" value={d.national_id} onChange={v => set('national_id', v.target.value)} /></Field>
      <Field label={L('تاريخ الولادة', 'Birth date')} error={x('dob')}><input type="date" className="field" value={d.dob} onChange={v => set('dob', v.target.value)} /></Field>
      <Field label={L('الجوال', 'Mobile')} error={x('phone')}><input className="field ltr text-start" value={d.phone} onChange={v => set('phone', v.target.value)} /></Field>
      <Field label={L('البريد', 'Email')} req error={x('email')} className="sm:col-span-2" hint={L('البريد هو اسم الدخول للموظف — تغييره يغيّر حسابه أيضاً.', "The email is the employee's sign-in name — changing it changes their account too.")}>
        <input type="email" className="field ltr text-start" value={d.email} onChange={v => set('email', v.target.value)} /></Field>
      <Field label={L('الإدارة', 'Department')} req error={x('department_id')}><select className="field" value={d.department_id} onChange={v => set('department_id', Number(v.target.value))}>
        {lk.departments.map(o => <option key={o.id} value={o.id}>{o[lang]}</option>)}</select></Field>
      <Field label={L('مقر العمل', 'Location')} req error={x('location_id')}><select className="field" value={d.location_id} onChange={v => set('location_id', Number(v.target.value))}>
        {lk.locations.map(o => <option key={o.id} value={o.id}>{o[lang]}</option>)}</select></Field>
      <Field label={L('المسمى الوظيفي', 'Job title')} req error={x('title_ar')}><input className="field" value={d.title_ar} onChange={v => set('title_ar', v.target.value)} /></Field>
      <Field label={L('المسمى بالإنكليزية', 'Job title (EN)')} req error={x('title_en')}><input className="field ltr text-start" value={d.title_en} onChange={v => set('title_en', v.target.value)} /></Field>
      <Field label={L('المدير المباشر', 'Manager')} error={x('manager_id')} className="sm:col-span-2"><select className="field" value={d.manager_id ?? ''} onChange={v => set('manager_id', v.target.value ? Number(v.target.value) : null)}>
        <option value="">{L('بلا مدير (رأس الهيكل)', 'No manager (top of the chart)')}</option>
        {lk.managers.filter(m => m.id !== e.id).map(m => <option key={m.id} value={m.id}>{m[lang]} — {m.code}</option>)}</select></Field>
      {err && !Object.keys(err.errors).length && <div role="alert" className="sm:col-span-2 text-[13px] text-danger bg-danger-soft rounded px-3 py-2">{err.message}</div>}
    </div>}
  </Modal>
}

/** ربط/تغيير بطاقة NFC (LinkCardButton في الديمو): مرّر البطاقة على قارئ USB (يكتب الرقم كلوحة مفاتيح) أو اكتب الرقم يدوياً */
export function CardModal({ e, onClose }: { e: EmpFile; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const link = useLinkCard(e.id)
  const [got, setGot] = useState<string | null>(null); const [sig, setSig] = useState(''); const [manual, setManual] = useState('')
  useCardReader(raw => { setGot(normUid(raw)); link.reset() }, !got, b => setSig(b))
  const err = link.error instanceof ApiError ? link.error : null
  const msg = err && (err.field('uid') ?? err.message)
  const run = (v: string | null) => link.mutate(v, { onSuccess: () => { toast(v ? L('رُبطت البطاقة', 'Card linked') : L('أُلغي ربط البطاقة', 'Card unlinked')); onClose() } })
  return <Modal open onClose={() => !link.isPending && onClose()} title={e.nfc_uid ? L('تغيير بطاقة الحضور', 'Change attendance card') : L('ربط بطاقة الحضور', 'Link attendance card')} width={440}
    footer={<>{e.nfc_uid && <Btn kind="ghost" icon={<Unlink size={15} />} disabled={link.isPending} onClick={() => run(null)} className="me-auto text-danger">{L('إلغاء الربط', 'Unlink')}</Btn>}
      <Btn onClick={onClose} disabled={link.isPending}>{L('إلغاء', 'Cancel')}</Btn>
      {got && <Btn onClick={() => { setGot(null); link.reset() }} disabled={link.isPending}>{L('إعادة', 'Retry')}</Btn>}
      <Btn kind="primary" disabled={!got || link.isPending} onClick={() => got && run(got)}>{L('ربط البطاقة', 'Link card')}</Btn></>}>
    <div className="text-center">
      <div className="relative mx-auto size-24 grid place-items-center"><span className="absolute inset-0 rounded-full border-2 border-brand/60" />{!got && <span className="absolute inset-0 rounded-full scan-pulse" style={{ animationIterationCount: 'infinite' }} />}<Nfc size={40} className="text-brand" /></div>
      {e.nfc_uid && <p className="mt-3 text-[12.5px] text-ink-3">{L('البطاقة الحالية', 'Current card')}: <bdi className="ltr num text-ink">{e.nfc_uid}</bdi></p>}
      {!got ? <>
        <div className="mt-3 font-display font-bold text-[17px]">{L('مرّر البطاقة الآن', 'Tap the card now')}</div>
        <div className="text-[13px] text-ink-3 mt-1">{L(`ستُربط البطاقة بـ ${e.name.ar}`, `The card will be linked to ${e.name.en}`)}</div>
        <div className={`mt-2 text-[12px] ltr font-mono ${sig ? 'text-brand' : 'text-ink-3'}`}>{sig ? `… ${sig}` : L('بانتظار إشارة من القارئ', 'Waiting for reader')}</div>
        <form className="mt-4 flex gap-2" onSubmit={v => { v.preventDefault(); if (manual.trim()) setGot(normUid(manual)) }}>
          <input data-manual="1" className="field ltr flex-1 num" placeholder={L('أو أدخل رقم البطاقة', 'or type the card number')} value={manual} onChange={v => setManual(v.target.value)} />
          <Btn type="submit" disabled={!manual.trim()}>{L('إدخال', 'Enter')}</Btn></form>
      </> : <>
        <div className="mt-3 font-display font-bold text-[17px]">{L('تمت قراءة البطاقة', 'Card read')}</div>
        <div className="mt-1 ltr num text-[15px] font-semibold">{analyse(got).hex}</div>
        {analyse(got).format === 'DEC' && <div className="text-[11.5px] text-ink-3 ltr">DEC {got}</div>}
      </>}
      {msg && <div role="alert" className="mt-3 text-[13px] text-danger bg-danger-soft rounded px-3 py-2">{msg}</div>}
      <p className="mt-3 text-[12px] text-ink-3">{lang === 'ar' ? 'نفس البطاقة تُعرف من قارئ USB أو من NFC الجوال/Sunmi.' : 'The same card is recognised from a USB reader or phone/Sunmi NFC.'}</p>
    </div>
  </Modal>
}
