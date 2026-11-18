import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BadgeCheck, CheckCircle2, HandHeart, Loader2, Search, XCircle } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import { api, ApiError } from '../../lib/api'
import type { Bi } from '../../lib/types'
import type { LookupItem } from '../../lib/codes'
import { Btn, Field, Panel } from '../../ui/kit'

const LEVELS: Record<string, [string, string]> = { junior: ['مبتدئ', 'Junior'], mid: ['متوسط', 'Mid'], senior: ['أول', 'Senior'], expert: ['خبير', 'Expert'] }

/** فرصة تطوع عامة ونموذج التسجيل (FR-VL-012) */
export function VolunteerSignup({ token }: { token: string }) {
  const L = useL(); const lang = useLang()
  const q = useQuery({ queryKey: ['public', 'vol', token], retry: false, queryFn: () => api<{ data: { title: string; title_en: string | null; description: string | null; starts_on: string | null; ends_on: string | null; weekly_hours: number | null; company: { name: Bi } } }>(`/public/volunteer/${token}`).then(r => r.data) })
  const [v, setV] = useState({ name: '', email: '', phone: '', city: '', gender: '', areas: '', emergency_name: '', emergency_phone: '', consent: false })
  const [busy, setBusy] = useState(false); const [err, setErr] = useState<ApiError | null>(null); const [done, setDone] = useState(false)
  if (q.isLoading) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  if (!q.data) return <div className="max-w-[560px] mx-auto py-16 text-center"><XCircle className="mx-auto text-ink-3" size={40} /><p className="mt-3">{L('هذه الفرصة غير متاحة أو أُغلقت.', 'This opportunity is not available or has closed.')}</p></div>
  const o = q.data
  const send = async () => {
    setBusy(true); setErr(null)
    try {
      await api('/public/volunteer/' + token, { json: { name: v.name, email: v.email, phone: v.phone, city: v.city || null, gender: v.gender || null, areas: v.areas ? v.areas.split(/[,،]\s*/) : [],
        emergency: v.emergency_name ? { name: v.emergency_name, phone: v.emergency_phone } : null, consent: v.consent } })
      setDone(true)
    } catch (x) { setErr(x as ApiError) } finally { setBusy(false) }
  }
  return <div className="max-w-[720px] mx-auto px-4 py-10">
    <div className="flex items-center gap-2 text-brand font-semibold"><HandHeart size={18} />{bi(o.company.name, lang)} · {L('فرصة تطوع', 'Volunteering opportunity')}</div>
    <h1 className="text-[28px] font-black mt-2">{lang === 'en' && o.title_en ? o.title_en : o.title}</h1>
    <div className="text-[13.5px] text-ink-3 num">{o.starts_on ? fmtDate(o.starts_on, lang) : ''}{o.ends_on ? ` — ${fmtDate(o.ends_on, lang)}` : ''}{o.weekly_hours ? ` · ${o.weekly_hours} ${L('ساعة أسبوعياً', 'hours a week')}` : ''}</div>
    {o.description && <p className="mt-4 whitespace-pre-line text-[15px] leading-7">{o.description}</p>}
    <Panel className="mt-6" title={done ? L('تم التسجيل', 'Registered') : L('سجّل اهتمامك', 'Register your interest')}>
      {done ? <div className="text-center py-6"><CheckCircle2 size={40} className="mx-auto text-brand" /><p className="mt-3">{L('وصل طلبك. سيتواصل معك منسق التطوع بعد مراجعته.', 'We received your application. The volunteering coordinator will contact you after reviewing it.')}</p></div> :
        <div className="grid sm:grid-cols-2 gap-3">
          <Field label={L('الاسم الكامل', 'Full name')} req error={err?.field('name')}><input className="field" value={v.name} onChange={e => setV({ ...v, name: e.target.value })} /></Field>
          <Field label={L('البريد الإلكتروني', 'Email')} req error={err?.field('email')}><input className="field ltr" type="email" value={v.email} onChange={e => setV({ ...v, email: e.target.value })} /></Field>
          <Field label={L('الهاتف', 'Phone')} req error={err?.field('phone')}><input className="field ltr" value={v.phone} onChange={e => setV({ ...v, phone: e.target.value })} /></Field>
          <Field label={L('المدينة', 'City')}><input className="field" value={v.city} onChange={e => setV({ ...v, city: e.target.value })} /></Field>
          <Field label={L('المجالات التي تفضّلها', 'Preferred areas')} className="sm:col-span-2"><input className="field" value={v.areas} onChange={e => setV({ ...v, areas: e.target.value })} placeholder={L('استقبال، تنظيم فعاليات، ترجمة…', 'Reception, events, translation…')} /></Field>
          <Field label={L('جهة الطوارئ — الاسم', 'Emergency contact — name')}><input className="field" value={v.emergency_name} onChange={e => setV({ ...v, emergency_name: e.target.value })} /></Field>
          <Field label={L('جهة الطوارئ — الهاتف', 'Emergency contact — phone')}><input className="field ltr" value={v.emergency_phone} onChange={e => setV({ ...v, emergency_phone: e.target.value })} /></Field>
          <label className="sm:col-span-2 flex items-start gap-2 text-[13.5px]"><input type="checkbox" className="mt-1" checked={v.consent} onChange={e => setV({ ...v, consent: e.target.checked })} />{L('أوافق على معالجة بياناتي لأغراض التطوع فقط.', 'I agree to my data being processed for volunteering purposes only.')}</label>
          {err?.field('consent') && <p className="sm:col-span-2 text-[13px] text-danger">{err.field('consent')}</p>}
          <div className="sm:col-span-2"><Btn kind="primary" disabled={busy || !v.name || !v.email || !v.phone || !v.consent} onClick={send}>{busy ? <Loader2 size={15} className="animate-spin" /> : null}{L('إرسال', 'Submit')}</Btn></div>
        </div>}
    </Panel>
  </div>
}

/** التسجيل الذاتي في قائمة الخبراء (FR-RS-005) */
export function ExpertSignup({ slug }: { slug: string }) {
  const L = useL(); const lang = useLang()
  const q = useQuery({ queryKey: ['public', 'expert', slug], retry: false, queryFn: () => api<{ company: { name: Bi }; specialties: LookupItem[]; countries: LookupItem[]; levels: string[] }>(`/public/experts/${slug}`) })
  const [v, setV] = useState({ entity: 'person', name: '', email: '', phone: '', country: 'SY', city: '', specialties: [] as number[], years: '', level: 'senior', languages: 'ar', keywords: '', projects: '', consent: false })
  const [cv, setCv] = useState<File | null>(null)
  const [busy, setBusy] = useState(false); const [err, setErr] = useState<ApiError | null>(null); const [done, setDone] = useState(false)
  if (q.isLoading) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  if (!q.data) return <div className="max-w-[560px] mx-auto py-16 text-center"><XCircle className="mx-auto text-ink-3" size={40} /><p className="mt-3">{L('الرابط غير صالح.', 'This link is not valid.')}</p></div>
  const send = async () => {
    setBusy(true); setErr(null)
    const f = new FormData()
    Object.entries({ entity: v.entity, name: v.name, email: v.email, phone: v.phone, country: v.country, city: v.city, years: v.years, level: v.level, projects: v.projects, consent: v.consent ? '1' : '0' }).forEach(([k, x]) => f.append(k, String(x)))
    v.specialties.forEach(s => f.append('specialties[]', String(s)))
    v.languages.split(/[,،]\s*/).filter(Boolean).forEach(s => f.append('languages[]', s))
    v.keywords.split(/[,،]\s*/).filter(Boolean).forEach(s => f.append('keywords[]', s))
    if (cv) f.append('cv', cv)
    try { await api(`/public/experts/${slug}`, { method: 'POST', body: f }); setDone(true) } catch (x) { setErr(x as ApiError) } finally { setBusy(false) }
  }
  return <div className="max-w-[760px] mx-auto px-4 py-10">
    <div className="text-brand font-semibold">{bi(q.data.company.name, lang)}</div>
    <h1 className="text-[28px] font-black mt-1">{L('الانضمام إلى قائمة الخبراء', 'Join our expert roster')}</h1>
    <p className="text-ink-2 mt-2">{L('سجّل بياناتك وتخصصاتك. يُراجع الطلب ثم يُعتمد إدراجك، ونتواصل معك عند الحاجة لتكليف يناسب خبرتك.', 'Register your details and specialties. After review your listing is approved, and we contact you when an assignment matches your expertise.')}</p>
    <Panel className="mt-6" title={done ? L('وصل طلبك', 'Application received') : L('بيانات الخبير', 'Expert details')}>
      {done ? <div className="text-center py-6"><CheckCircle2 size={40} className="mx-auto text-brand" /><p className="mt-3">{L('شكراً لك. سنراجع بياناتك ونبلغك بالنتيجة.', 'Thank you. We will review your details and let you know.')}</p></div> :
        <div className="grid sm:grid-cols-2 gap-3">
          <Field label={L('الصفة', 'Entity')}><select className="field" value={v.entity} onChange={e => setV({ ...v, entity: e.target.value })}><option value="person">{L('فرد', 'Individual')}</option><option value="firm">{L('شركة استشارية', 'Consulting firm')}</option></select></Field>
          <Field label={L('الاسم', 'Name')} req error={err?.field('name')}><input className="field" value={v.name} onChange={e => setV({ ...v, name: e.target.value })} /></Field>
          <Field label={L('البريد', 'Email')} req error={err?.field('email')}><input className="field ltr" type="email" value={v.email} onChange={e => setV({ ...v, email: e.target.value })} /></Field>
          <Field label={L('الهاتف', 'Phone')} req error={err?.field('phone')}><input className="field ltr" value={v.phone} onChange={e => setV({ ...v, phone: e.target.value })} /></Field>
          <Field label={L('بلد الإقامة', 'Country')}><select className="field" value={v.country} onChange={e => setV({ ...v, country: e.target.value })}>{q.data.countries.map(c => <option key={c.code} value={c.code}>{bi(c, lang)}</option>)}</select></Field>
          <Field label={L('المدينة', 'City')}><input className="field" value={v.city} onChange={e => setV({ ...v, city: e.target.value })} /></Field>
          <Field label={L('التخصصات', 'Specialties')} req className="sm:col-span-2" error={err?.field('specialties')}><div className="grid gap-1 [grid-template-columns:repeat(auto-fill,minmax(200px,1fr))] max-h-48 overflow-y-auto rounded-lg border border-line p-2">
            {q.data.specialties.map(s => <label key={s.id} className={`flex items-center gap-2 text-[13px] ${s.parent_id ? 'ps-4' : 'font-semibold'}`}><input type="checkbox" checked={v.specialties.includes(s.id)} onChange={e => setV({ ...v, specialties: e.target.checked ? [...v.specialties, s.id] : v.specialties.filter(x => x !== s.id) })} />{bi(s, lang)}</label>)}</div></Field>
          <Field label={L('سنوات الخبرة', 'Years of experience')} req error={err?.field('years')}><input className="field num" inputMode="numeric" value={v.years} onChange={e => setV({ ...v, years: e.target.value.replace(/\D/g, '') })} /></Field>
          <Field label={L('المستوى', 'Level')}><select className="field" value={v.level} onChange={e => setV({ ...v, level: e.target.value })}>{q.data.levels.map(k => <option key={k} value={k}>{L(LEVELS[k][0], LEVELS[k][1])}</option>)}</select></Field>
          <Field label={L('اللغات', 'Languages')}><input className="field" value={v.languages} onChange={e => setV({ ...v, languages: e.target.value })} /></Field>
          <Field label={L('كلمات مفتاحية', 'Keywords')}><input className="field" value={v.keywords} onChange={e => setV({ ...v, keywords: e.target.value })} /></Field>
          <Field label={L('أبرز المشاريع والجهات', 'Key projects & clients')} className="sm:col-span-2"><textarea className="field" rows={3} value={v.projects} onChange={e => setV({ ...v, projects: e.target.value })} /></Field>
          <Field label={L('السيرة الذاتية (PDF أو DOCX)', 'CV (PDF or DOCX)')} req error={err?.field('cv')}><input type="file" accept=".pdf,.docx" onChange={e => setCv(e.target.files?.[0] ?? null)} /></Field>
          <label className="sm:col-span-2 flex items-start gap-2 text-[13.5px]"><input type="checkbox" className="mt-1" checked={v.consent} onChange={e => setV({ ...v, consent: e.target.checked })} />{L('أوافق على حفظ بياناتي في قائمة الخبراء لأغراض التكليف فقط.', 'I agree to my data being kept in the expert roster for assignment purposes only.')}</label>
          {err && !Object.keys(err.errors).length && <p className="sm:col-span-2 text-[13px] text-danger">{err.message}</p>}
          <div className="sm:col-span-2"><Btn kind="primary" disabled={busy || !v.name || !v.email || !v.phone || !v.specialties.length || !v.years || !cv || !v.consent} onClick={send}>{L('إرسال الطلب', 'Submit')}</Btn></div>
        </div>}
    </Panel>
  </div>
}

/** التحقق العام من شهادة تطوع برمزها */
export function VerifyCertificate({ code: initial }: { code?: string }) {
  const L = useL(); const lang = useLang()
  const [code, setCode] = useState(initial ?? '')
  const [q, setQ] = useState(initial ?? '')
  const r = useQuery({ queryKey: ['public', 'verify', q], enabled: !!q, retry: false, queryFn: () => api<{ data: { no: string; name: Bi; title: string; from: string; to: string; hours: number; company: Bi } }>(`/public/verify/${encodeURIComponent(q)}`).then(x => x.data) })
  return <div className="max-w-[560px] mx-auto px-4 py-12">
    <h1 className="text-[24px] font-black">{L('التحقق من شهادة تطوع', 'Verify a volunteering certificate')}</h1>
    <div className="flex gap-2 mt-4"><input className="field ltr font-mono" value={code} onChange={e => setCode(e.target.value)} placeholder="ABCD1234EF" onKeyDown={e => e.key === 'Enter' && setQ(code.trim())} />
      <Btn kind="primary" icon={<Search size={15} />} onClick={() => setQ(code.trim())}>{L('تحقق', 'Verify')}</Btn></div>
    {r.isLoading && q && <Loader2 className="animate-spin text-brand mx-auto mt-6" />}
    {r.data && <Panel className="mt-6"><div className="flex items-center gap-2 text-brand font-semibold"><BadgeCheck size={18} />{L('شهادة صحيحة', 'Valid certificate')}</div>
      <div className="mt-2 text-[15px]"><b>{bi(r.data.name, lang)}</b> · {bi(r.data.company, lang)}</div>
      <div className="text-[13.5px] text-ink-2">{r.data.title} · <span className="num">{fmtDate(r.data.from, lang)} — {fmtDate(r.data.to, lang)}</span> · <span className="num">{r.data.hours}</span> {L('ساعة', 'hours')}</div>
      <div className="text-[12px] text-ink-3 ltr">{r.data.no}</div></Panel>}
    {r.isError && <Panel className="mt-6"><div className="flex items-center gap-2 text-danger"><XCircle size={18} />{L('لا توجد شهادة بهذا الرمز.', 'No certificate has this code.')}</div></Panel>}
  </div>
}
