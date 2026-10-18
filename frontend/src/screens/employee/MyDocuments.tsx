import { useState, type ReactNode } from 'react'
import { Check, Loader2 } from 'lucide-react'
import { useL, useLang, bi, money } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useMe } from '../../lib/session'
import { useMyDocs, useSignDoc, type DocKey, type MyDocs, type Sig } from '../../lib/onboarding'
import { Btn, Empty, Field, PageHead, Panel, SignaturePad, toast } from '../../ui/kit'
import { Paper, SigBox } from '../../ui/docs'

/**
 * «وثائق للتوقيع» (M14 — MyDocs في الديمو): سندات استلام العهد، تعهد السرية F-22، إبراء الذمة الوقائي F-20،
 * وإقرار ميثاق الأخلاق والسلوك المهني (نص الشركة). التوقيع الإلكتروني يُحفظ في ملف الموظف.
 */
export function MyDocuments() {
  const L = useL(); const lang = useLang()
  const { data, isLoading } = useMyDocs()
  const company = useMe().data?.company
  const co = company ? bi(company.name, lang) : ''
  if (isLoading || !data) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const u = data.undertakings; const name = bi(data.employee.name, lang)
  const nothing = !data.pending.length && (!u || (u.f22.signed_at && u.f20.signed_at && u.conduct.signed_at))
  return <div className="max-w-[1000px] mx-auto space-y-5">
    <PageHead title={L('وثائق للتوقيع', 'Documents to sign')} sub={L('التوقيع الإلكتروني هنا له نفس أثر التوقيع الورقي ويُحفظ في ملفك.', 'E-signatures here are stored in your file like paper signatures.')} />
    {nothing && <Panel><Empty title={L('لا توجد وثائق بانتظار توقيعك', 'Nothing waiting for your signature')} /></Panel>}
    {data.pending.map(a => <Paper key={a.id} code="CUS" title={L('سند استلام عهدة', 'Custody receipt')} subject={a.tag}>
      <table className="w-full border border-line text-[14px] mb-4"><tbody>{[[L('الأصل', 'Asset'), bi(a.name, lang)], [L('الرمز', 'Tag'), a.tag], [L('الرقم التسلسلي', 'Serial'), a.serial ?? '—'], [L('القيمة التقديرية', 'Value'), money(a.value, 'USD', lang)]].map(([k, v]) =>
        <tr key={k}><td className="border border-line px-3 py-2 bg-sub w-1/3">{k}</td><td className="border border-line px-3 py-2 num">{v}</td></tr>)}</tbody></table>
      <p className="text-[14px] mb-3 max-w-[70ch]">{L('أقر باستلام الأصل المذكور أعلاه بحالة سليمة، وأتعهد بالمحافظة عليه وإعادته عند الطلب أو عند انتهاء خدمتي، وأتحمل قيمته في حال الفقدان أو التلف الناتج عن سوء الاستخدام.', 'I confirm receipt of the asset in good condition and undertake to keep it and return it on request or at end of service, bearing its value if lost or damaged through misuse.')}</p>
      <SignRow doc="asset" assetId={a.id} label={L('توقيع الاستلام', 'Sign receipt')} done={L('وُقّع سند الاستلام', 'Receipt signed')} />
    </Paper>)}
    {u && <Paper code="F-22" title={L('تعهد المحافظة على سرية العمل', 'Confidentiality undertaking')}>
      <ol className="list-decimal ps-5 space-y-2 text-[14px] max-w-[72ch]">
        <li>{L(`أتعهد بعدم إفشاء أي معلومات أو بيانات أو أسرار عمل خاصة بـ${co}، أثناء الخدمة أو بعدها.`, 'I will not disclose any company information or trade secrets during or after employment.')}</li>
        <li>{L('أوافق على اقتطاع 10٪ شهرياً لمدة 3 سنوات كضمانة لحسن إدارة العهدة والالتزام بالسرية، تُسترد بموافقة الإدارة.', 'I accept a 10% monthly retention for 3 years as a custody and confidentiality guarantee, refundable on management approval.')}</li>
        <li>{L('ألتزم باستخدام قنوات التواصل الرسمية للشركة وعدم استغلال اسمها لأغراض شخصية.', 'I will use official channels only and not use the company name for personal purposes.')}</li>
      </ol>
      <Signed s={u.f22} name={name} fallback={<SignRow doc="f22" label={L('توقيع التعهد', 'Sign')} done={L('وُقّع التعهد', 'Signed')} />} />
    </Paper>}
    {u && <Paper code="F-20" title={L('إقرار إبراء الذمة الوقائي', 'Precautionary clearance declaration')}>
      <p className="text-[14px] max-w-[72ch]">{L(`أقر بكامل أهليتي القانونية بإبراء ذمة ${co} إبراءً شاملاً بعد استلام كافة حقوقي المالية والمكافآت وبدلات الإجازات عند انتهاء خدمتي.`, 'I declare full release of the company upon receiving all my financial dues, bonuses and leave allowances at end of service.')}</p>
      <Signed s={u.f20} name={name} extra={u.f20.mother_name ? <>{L('اسم الأم', "Mother's name")}: {u.f20.mother_name}</> : null} fallback={<SignRow doc="f20" mother label={L('توقيع الإقرار', 'Sign')} done={L('وُقّع الإقرار', 'Signed')} />} />
    </Paper>}
    {u && <Paper code="COC" title={L('إقرار ميثاق الأخلاق والسلوك المهني', 'Code of ethics & conduct acknowledgment')}>
      <Conduct d={data} company={co} />
      <Signed s={u.conduct} name={name} fallback={<SignRow doc="conduct" label={L('توقيع الإقرار', 'Sign')} done={L('وُقّع إقرار الميثاق', 'Acknowledgment signed')} />} />
    </Paper>}
    {data.signed.length > 0 && <Panel title={L('عهد موقّعة', 'Signed custody')} pad={false}><ul className="divide-y divide-line-2">{data.signed.map(a => <li key={a.id} className="flex items-center gap-3 px-4 py-2.5 text-[14px]"><Check size={16} className="text-brand" /><span className="flex-1">{bi(a.name, lang)}</span><span className="ltr num text-ink-3">{a.tag}</span></li>)}</ul></Panel>}
  </div>
}

/** نص إقرار الميثاق (ميثاق السلوك المهني.docx) ببيانات الموظف */
function Conduct({ d, company }: { d: MyDocs; company: string }) {
  const L = useL(); const e = d.employee
  return <div className="text-[14px] leading-7 max-w-[75ch] space-y-3">
    <p>{L(`أنا الموقّع أدناه ${e.name.ar}، الرقم الوطني ${e.national_id ?? '—'}، الرقم الوظيفي ${e.code}، أعمل بالمسمى الوظيفي: ${e.title.ar}، أقر بأنني اطلعت على ميثاق الأخلاق والسلوك المهني الخاص بالشركة، وبأنني ملتزم بالتقيد بكافة السياسات والإجراءات الخاصة بالشركة، وأن أية مخالفة تصدر مني لهذه التعليمات هي على مسؤوليتي وتحت طائلة أية جزاءات تفرضها الإدارة العليا.`,
      `I, ${e.name.en}, national ID ${e.national_id ?? '—'}, employee ID ${e.code}, working as ${e.title.en}, confirm that I have read the company's code of ethics and professional conduct, that I will comply with all company policies and procedures, and that any breach is my responsibility and subject to any sanctions imposed by senior management.`)}</p>
    <p>{L(`وأقر أيضاً بأن ميثاق الأخلاق والسلوك المهني الخاص بـ${company} قابل للتعديل والتطوير والتحديث استجابة للتطور في بيئة العمل وظروف التشغيل أو بهدف التوافق مع أية قوانين وتشريعات صادرة عن جهات الوصاية، وأن هذه التعديلات تُعدّل دون الحاجة لإبلاغي بشكل شخصي، وأنني ملتزم بالتقيد بأية تعليمات معدلة أو جديدة واردة فيه.`,
      'I also acknowledge that the code may be amended and updated in response to changes in the work environment or to comply with laws and regulations, that such amendments apply without notifying me personally, and that I will comply with any amended or new instructions.')}</p>
    <p>{L('وأقر أنني ملتزم بقانون العاملين ضمن الجمهورية العربية السورية، وأنه لا يمكنني الانفكاك عن العمل إلا بموجب إخطار خطي مسبق وتسليم كافة المهام المتعلقة بمنصبي أصولاً.', 'I confirm that I abide by the labour law of the Syrian Arab Republic, and that I may only leave work after prior written notice and a proper handover of all duties of my position.')}</p>
  </div>
}

function Signed({ s, name, fallback, extra }: { s: Sig; name: string; fallback: ReactNode; extra?: ReactNode }) {
  const L = useL()
  if (!s.signed_at) return <div className="mt-4">{fallback}</div>
  return <div className="mt-4 max-w-sm">{extra && <div className="text-[13px] text-ink-2 mb-2">{extra}</div>}<SigBox label={L('توقيعك', 'Your signature')} name={name} sig={s.signature ?? undefined} at={s.signed_at} /></div>
}

function SignRow({ doc, assetId, label, done, mother }: { doc: DocKey; assetId?: number; label: string; done: string; mother?: boolean }) {
  const L = useL()
  const sign = useSignDoc()
  const [sig, setSig] = useState<string>()
  const [m, setM] = useState('')
  const err = sign.error instanceof ApiError ? sign.error : null
  return <div className="space-y-3">
    {mother && <Field label={L('اسم الأم الكامل', "Mother's full name")} req className="max-w-sm" error={err?.field('mother_name')}><input className="field" value={m} onChange={x => { setM(x.target.value); sign.reset() }} /></Field>}
    <div className="grid sm:grid-cols-[1fr_auto] gap-4 items-end"><SignaturePad value={sig} onChange={v => { setSig(v); sign.reset() }} />
      <Btn kind="primary" disabled={!sig || (mother && m.trim().length < 3) || sign.isPending} icon={sign.isPending ? <Loader2 size={16} className="animate-spin" /> : undefined}
        onClick={() => sign.mutate({ doc, signature: sig!, asset_id: assetId, mother_name: mother ? m.trim() : undefined }, { onSuccess: () => toast(done) })}>{label}</Btn></div>
    {err && !err.field('mother_name') && <div role="alert" className="text-[13px] text-danger">{err.field('signature') ?? err.message}</div>}
  </div>
}
