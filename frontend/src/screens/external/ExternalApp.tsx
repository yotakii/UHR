import { useState } from 'react'
import { Bell, Check, FileText, HandHeart, Loader2, LogOut, UserRound, Wallet } from 'lucide-react'
import { useL, useLang, bi, fmtDate, money } from '../../lib/store'
import { useLogout, type SessionUser } from '../../lib/session'
import { navigate, useRoute } from '../../lib/router'
import { fileUrl } from '../../lib/console'
import { ASG_STATUS, DLV, PAY, useExtHome, useExtPortalAct, type ExtHome } from '../../lib/externals'
import { BellMenu } from '../console/Shell'
import { Logo, LangToggle, ThemeToggle } from '../../ui/brand'
import { Badge, Btn, Empty, Field, Panel, Tabs, toast } from '../../ui/kit'
import { ConfirmSend } from '../../ui/decision'
import { EngagementCard, FilePick } from '../externals/parts'
import { CertPrint } from '../externals/CertPrint'

/**
 * بوابة المتطوع والخبير (POL-VL-08 / FR-RS-015): واجهة مبسطة منفصلة — المتطوع: مهامي، ساعاتي، وثائقي، الفرص؛
 * الخبير: تكليفاتي ومخرجاتها، مدفوعاتي، بياناتي وتوفري. لا دليل موظفين ولا رواتب ولا تقارير.
 */
export function ExternalApp({ user }: { user: SessionUser }) {
  const L = useL(); const lang = useLang()
  const logout = useLogout()
  const route = useRoute()
  const { data } = useExtHome()
  const [bell, setBell] = useState(false)
  if (route.parts[0] === 'ext' && route.parts[1] === 'cert' && /^\d+$/.test(route.parts[2] ?? '')) return <div className="p-4"><CertPrint id={Number(route.parts[2])} portal /></div>
  const expert = data && (data.me.kind === 'expert' || data.me.kind === 'consultant')
  const tabs = expert ? [{ v: 'home', label: L('تكليفاتي', 'My assignments') }, { v: 'pay', label: L('مدفوعاتي', 'My payments') }, { v: 'me', label: L('بياناتي وتوفري', 'My details') }]
    : [{ v: 'home', label: L('مهامي', 'My tasks') }, { v: 'docs', label: L('وثائقي', 'My documents') }, { v: 'opps', label: L('الفرص', 'Opportunities') }, { v: 'me', label: L('حسابي', 'My account') }]
  const tab = tabs.find(t => t.v === route.query.tab)?.v ?? 'home'
  return <div className="min-h-full bg-canvas">
    <header className="sticky top-0 z-30 bg-surface/90 backdrop-blur border-b border-line">
      <div className="max-w-[1000px] mx-auto px-4 h-14 flex items-center justify-between gap-3">
        <div className="flex items-center gap-2"><Logo /><span className="text-[12px] text-ink-3 border-s border-line ps-2 hidden sm:flex items-center gap-1">{expert ? <UserRound size={13} /> : <HandHeart size={13} />}{expert ? L('بوابة الخبير', 'Expert portal') : L('بوابة المتطوع', 'Volunteer portal')}{data?.company ? ` · ${bi(data.company.name, lang)}` : ''}</span></div>
        <div className="flex items-center gap-2"><span className="text-[13px] text-ink-2 hidden sm:block">{bi(user.name, lang)}</span>
          <div className="relative"><button className="p-2 rounded-lg hover:bg-sub" aria-label={L('الإشعارات', 'Notifications')} onClick={() => setBell(!bell)}><Bell size={17} /></button>{bell && <BellMenu open setOpen={setBell} linkOf={() => '/ext'} />}</div>
          <LangToggle /><span className="hidden sm:inline-flex"><ThemeToggle /></span>
          <Btn size="sm" kind="ghost" icon={<LogOut size={15} />} onClick={() => logout.mutate()}><span className="hidden sm:inline">{L('خروج', 'Sign out')}</span></Btn></div>
      </div>
    </header>
    <main className="max-w-[1000px] mx-auto px-4 py-6">
      {!data ? <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div> : <>
        <h1 className="text-[22px] font-black">{L('أهلاً', 'Welcome')} {bi(data.me, lang)}</h1>
        {data.me.review_requested && <p className="mt-2 rounded-lg bg-brass/10 text-[13.5px] px-3 py-2">{L('يرجى تحديث بياناتك وتوفرك من «بياناتي وتوفري».', 'Please update your details and availability under “My details”.')}</p>}
        <div className="mt-4"><Tabs value={tab} onChange={v => navigate(`/ext${v === 'home' ? '' : `?tab=${v}`}`)} tabs={tabs} /></div>
        <div className="mt-4">{expert ? (tab === 'pay' ? <Payments d={data} /> : tab === 'me' ? <Profile d={data} expert /> : <Assignments d={data} />)
          : tab === 'docs' ? <Docs d={data} /> : tab === 'opps' ? <Opps d={data} /> : tab === 'me' ? <Profile d={data} expert={false} /> : <Volunteer d={data} />}</div>
      </>}
    </main>
  </div>
}

function Volunteer({ d }: { d: ExtHome }) {
  const L = useL()
  if (!d.engagement) return <Panel><Empty title={L('لا يوجد تكليف حالياً', 'No current engagement')}>{L('عند اعتماد تكليفك تظهر هنا الاتفاقية والمهام.', 'Once your engagement is approved, the agreement and tasks appear here.')}</Empty></Panel>
  return <div className="space-y-4">
    {d.engagement.status === 'approved' && <p className="rounded-lg bg-info/10 px-3 py-2 text-[13.5px]">{L('اعتُمد تكليفك. اقرأ الاتفاقية ووقّعها لتبدأ — لا تُسجَّل ساعات قبل التوقيع.', 'Your engagement is approved. Read and sign the agreement to start — hours cannot be logged before signing.')}</p>}
    <EngagementCard x={null} g={d.engagement} portal lk={d.pledges ? { cfg: { pledges: d.pledges } } as never : undefined} />
    {d.engagement.tasks && <Panel title={L('المهام', 'Tasks')}><p className="text-[14px] whitespace-pre-line">{d.engagement.tasks}</p></Panel>}
    {d.payments.length > 0 && <Payments d={d} />}
  </div>
}

function Docs({ d }: { d: ExtHome }) {
  const L = useL(); const lang = useLang()
  return <div className="space-y-4">
    <Panel title={L('شهادات التطوع', 'Volunteering certificates')}>{!d.certificates?.length ? <p className="text-[13px] text-ink-3">{L('تصدر الشهادة عند انتهاء التكليف.', 'The certificate is issued when the engagement ends.')}</p> :
      <div className="space-y-2">{d.certificates.map(c => <div key={c.id} className="flex items-center justify-between gap-2"><span>{c.title} <span className="ltr text-[12px] text-ink-3">{c.certificate_no}</span></span>
        <Btn size="sm" icon={<FileText size={13} />} onClick={() => navigate(`/ext/cert/${c.id}`)}>{L('عرض وطباعة', 'View & print')}</Btn></div>)}</div>}</Panel>
    {d.engagement?.signed_at && <Panel title={L('الاتفاقية', 'Agreement')}><p className="text-[13.5px]">{L('وُقّعت في', 'Signed on')} <span className="num">{fmtDate(d.engagement.signed_at, lang)}</span></p>
      <pre className="mt-2 whitespace-pre-wrap font-sans text-[13px] leading-7 max-h-80 overflow-y-auto">{d.engagement.body}</pre></Panel>}
  </div>
}

function Opps({ d }: { d: ExtHome }) {
  const L = useL(); const lang = useLang()
  if (!d.opportunities?.length) return <Panel><Empty title={L('لا توجد فرص مفتوحة', 'No open opportunities')} /></Panel>
  return <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]">{d.opportunities.map(o => <Panel key={o.id} title={lang === 'en' && o.title_en ? o.title_en : o.title}>
    {o.description && <p className="text-[13px] text-ink-2 line-clamp-4">{o.description}</p>}
    <div className="text-[12.5px] text-ink-3 mt-1 num">{o.starts_on ? fmtDate(o.starts_on, lang) : ''}{o.ends_on ? ` — ${fmtDate(o.ends_on, lang)}` : ''}</div>
    <a className="inline-block mt-2 text-brand text-[13px] font-semibold" href={`/volunteer/${o.token}`}>{L('التفاصيل والتسجيل', 'Details & sign-up')}</a></Panel>)}</div>
}

function Assignments({ d }: { d: ExtHome }) {
  const L = useL(); const lang = useLang()
  const act = useExtPortalAct()
  const [conflict, setConflict] = useState('')
  if (!d.assignments?.length) return <Panel><Empty title={L('لا تكليفات حالياً', 'No assignments yet')}>{L('عند اختيارك لتكليف تظهر هنا شروطه المرجعية ومخرجاته.', 'When you are selected for an assignment, its terms and deliverables appear here.')}</Empty></Panel>
  return <div className="space-y-4">{d.assignments.map(a => <Panel key={a.id} title={<span>{a.title} <span className="ltr text-[12px] text-ink-3">{a.ref}</span></span>} action={<Badge tone={ASG_STATUS[a.status]?.[2] ?? 'neutral'}>{ASG_STATUS[a.status] ? L(ASG_STATUS[a.status][0], ASG_STATUS[a.status][1]) : a.status}</Badge>}>
    <p className="text-[13.5px] whitespace-pre-line">{a.tor.objective}</p>
    {a.total_fee != null && <p className="text-[13px] mt-1">{L('الأتعاب', 'Fee')}: <b className="num">{money(a.total_fee, a.currency, lang)}</b></p>}
    {a.status === 'contracted' && <div className="mt-3 rounded-lg border border-line p-3 space-y-2">
      <div className="font-semibold text-[13.5px]">{L('التعهدات المطلوبة قبل البدء', 'Pledges required before starting')}</div>
      <p className="text-[13px] text-ink-2">{L('أتعهد بالحفاظ على سرية كل ما أطّلع عليه، وأفصح عن أي تضارب في المصالح.', 'I undertake to keep everything I access confidential, and I disclose any conflict of interest.')}</p>
      <Field label={L('الإفصاح عن تضارب مصالح (إن وُجد)', 'Conflict of interest to disclose (if any)')}><input className="field" value={conflict} onChange={e => setConflict(e.target.value)} /></Field>
      <ConfirmSend label={L('التوقيع على التعهدين', 'Sign both pledges')} onConfirm={() => act.mutateAsync({ path: `assignments/${a.id}/pledge`, v: { kinds: ['confidentiality', 'conflict'], conflict: conflict || null } }).then(() => toast(L('وُقّع التعهدان — بدأ التكليف', 'Signed — the assignment has started')))} /></div>}
    {a.deliverables.length > 0 && <table className="tbl mt-3"><tbody>{a.deliverables.map(dl => <tr key={dl.id}>
      <td><div className="font-medium">{dl.title}</div><div className="text-[12px] text-ink-3 num">{dl.due_on ? fmtDate(dl.due_on, lang) : ''}{dl.note ? ` · ${dl.note}` : ''}</div></td>
      <td className="num">{dl.payment != null ? money(dl.payment, a.currency, lang) : ''}</td>
      <td><Badge tone={DLV[dl.status][2]}>{L(DLV[dl.status][0], DLV[dl.status][1])}</Badge></td>
      <td>{['pending', 'returned'].includes(dl.status) && ['in_progress', 'delivered'].includes(a.status) && <FilePick portal label={L('تسليم', 'Submit')} onDone={f => act.mutate({ path: `deliverables/${dl.id}`, v: { file_id: f.id } }, { onSuccess: () => toast(L('سُلّم المخرج', 'Deliverable submitted')) })} />}
        {dl.file_id && <a className="text-brand text-[13px]" href={fileUrl(dl.file_id)} target="_blank" rel="noreferrer">{L('الملف', 'File')}</a>}</td></tr>)}</tbody></table>}
  </Panel>)}</div>
}

function Payments({ d }: { d: ExtHome }) {
  const L = useL(); const lang = useLang()
  if (!d.payments.length) return <Panel><Empty title={L('لا مدفوعات بعد', 'No payments yet')} /></Panel>
  return <Panel title={<span className="flex items-center gap-2"><Wallet size={15} />{L('طلبات الدفع', 'Payment requests')}</span>} pad={false}><table className="tbl"><tbody>{d.payments.map(p => <tr key={p.id}>
    <td><div className="font-medium">{p.description}</div><div className="text-[12px] text-ink-3"><span className="ltr inline-block">{p.ref}</span> · {fmtDate(p.created_at, lang)}</div></td>
    <td className="num font-semibold">{money(p.amount, p.currency, lang)}</td><td><Badge tone={PAY[p.status][2]}>{L(PAY[p.status][0], PAY[p.status][1])}</Badge></td></tr>)}</tbody></table></Panel>
}

function Profile({ d, expert }: { d: ExtHome; expert: boolean }) {
  const L = useL()
  const act = useExtPortalAct()
  const [v, setV] = useState({ phone: d.me.phone ?? '', city: d.me.city ?? '', available: d.me.available, available_from: d.me.available_from ?? '', keywords: d.me.keywords.join('، '), projects: d.me.projects ?? '' })
  const save = () => act.mutate({ path: 'profile', method: 'PUT', v: { phone: v.phone, city: v.city, ...(expert ? { available: v.available, available_from: v.available === 'from' ? v.available_from : null, keywords: v.keywords.split(/[,،]\s*/).filter(Boolean), projects: v.projects } : {}) } }, { onSuccess: () => toast(L('حُفظت البيانات', 'Saved')) })
  return <Panel title={expert ? L('بياناتي وتوفري', 'My details & availability') : L('حسابي', 'My account')}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('الهاتف', 'Phone')}><input className="field ltr" value={v.phone} onChange={e => setV({ ...v, phone: e.target.value })} /></Field>
      <Field label={L('المدينة', 'City')}><input className="field" value={v.city} onChange={e => setV({ ...v, city: e.target.value })} /></Field>
      {expert && <><Field label={L('التوفر', 'Availability')}><select className="field" value={v.available} onChange={e => setV({ ...v, available: e.target.value })}><option value="now">{L('متاح الآن', 'Available now')}</option><option value="from">{L('متاح من تاريخ', 'Available from')}</option><option value="no">{L('غير متاح', 'Not available')}</option></select></Field>
        {v.available === 'from' && <Field label={L('من', 'From')}><input type="date" className="field" value={v.available_from} onChange={e => setV({ ...v, available_from: e.target.value })} /></Field>}
        <Field label={L('كلمات مفتاحية', 'Keywords')} className="sm:col-span-2"><input className="field" value={v.keywords} onChange={e => setV({ ...v, keywords: e.target.value })} /></Field>
        <Field label={L('أبرز المشاريع', 'Key projects')} className="sm:col-span-2"><textarea className="field" rows={3} value={v.projects} onChange={e => setV({ ...v, projects: e.target.value })} /></Field></>}
    </div>
    <Btn className="mt-3" kind="primary" icon={<Check size={15} />} disabled={act.isPending} onClick={save}>{L('حفظ', 'Save')}</Btn>
  </Panel>
}
