import { useState } from 'react'
import { Loader2, Unlink } from 'lucide-react'
import { useL, useLang } from '../../../lib/store'
import { ApiError } from '../../../lib/api'
import { useLookups } from '../../../lib/onboarding'
import { useLinkCard, useUpdateProfile, type EmpFile, type ProfileInput } from '../../../lib/employees'
import { Btn, Field, Modal, Seg, toast } from '../../../ui/kit'

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

/** ربط/تغيير بطاقة NFC: قارئ USB يكتب الرقم في الحقل (كلوحة مفاتيح)، أو يُكتب يدوياً */
export function CardModal({ e, onClose }: { e: EmpFile; onClose: () => void }) {
  const L = useL()
  const link = useLinkCard(e.id)
  const [uid, setUid] = useState('')
  const err = link.error instanceof ApiError ? link.error : null
  const run = (v: string | null) => link.mutate(v, { onSuccess: () => { toast(v ? L('رُبطت البطاقة', 'Card linked') : L('أُلغي ربط البطاقة', 'Card unlinked')); onClose() } })
  return <Modal open onClose={() => !link.isPending && onClose()} title={e.nfc_uid ? L('تغيير بطاقة الحضور', 'Change attendance card') : L('ربط بطاقة الحضور', 'Link attendance card')} width={460}
    footer={<>{e.nfc_uid && <Btn kind="ghost" icon={<Unlink size={15} />} disabled={link.isPending} onClick={() => run(null)} className="me-auto text-danger">{L('إلغاء الربط', 'Unlink')}</Btn>}
      <Btn onClick={onClose} disabled={link.isPending}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={!uid.trim() || link.isPending} onClick={() => run(uid)}>{L('حفظ', 'Save')}</Btn></>}>
    <form onSubmit={v => { v.preventDefault(); if (uid.trim()) run(uid) }} className="space-y-3">
      {e.nfc_uid && <p className="text-[13px] text-ink-3">{L('البطاقة الحالية', 'Current card')}: <bdi className="ltr num text-ink">{e.nfc_uid}</bdi></p>}
      <Field label={L('رقم البطاقة (UID)', 'Card UID')} error={err?.field('uid') ?? (err && !Object.keys(err.errors).length ? err.message : undefined)}
        hint={L('ضع المؤشر في الحقل ومرّر البطاقة على القارئ، أو اكتب الرقم.', 'Focus the field and tap the card on the reader, or type the number.')}>
        <input className="field ltr text-start num" placeholder="04:A1:B2:C3:D4" value={uid} onChange={v => { setUid(v.target.value); if (err) link.reset() }} autoFocus /></Field>
    </form>
  </Modal>
}
