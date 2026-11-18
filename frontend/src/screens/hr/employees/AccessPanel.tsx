import { useState } from 'react'
import { CreditCard, MapPin, Plus, Trash2, Plane } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../../lib/store'
import { ApiError } from '../../../lib/api'
import { ACCESS_KIND, CRED_STATUS, CRED_TYPE, useAccess, useAddBranchAccess, useAddCredential, useCredentialStatus, useRemoveBranchAccess, type CredentialRow, newCardToken } from '../../../lib/nfc'
import { Badge, Btn, Field, Modal, Panel, Seg, toast } from '../../../ui/kit'
import { DecisionDialog } from '../../../ui/decision'

/**
 * اعتمادات الحضور وصلاحيات الفروع في ملف الموظف (FR-NF-005/007): البطاقات وأرقام البصمة وحالتها (فقد، إيقاف، إعادة تفعيل)،
 * والفروع المصرّح بها (دائم، مؤقت، ندب) والمهمات المعتمدة التي تمنح صلاحية مؤقتة آلياً.
 */
export function AccessPanel({ id }: { id: number }) {
  const L = useL(); const lang = useLang()
  const { data } = useAccess(id)
  const st = useCredentialStatus(); const rm = useRemoveBranchAccess()
  const [add, setAdd] = useState(false); const [br, setBr] = useState(false)
  const [act, setAct] = useState<{ c: CredentialRow; status: 'lost' | 'suspended' | 'revoked' } | null>(null)
  const [tok, setTok] = useState<string | null>(null)
  if (!data) return null
  const edit = data.can.edit
  return <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(320px,1fr))]">
    {tok && <Modal open onClose={() => setTok(null)} width={440} title={L('رمز ذاكرة البطاقة', 'Card memory code')} footer={<Btn kind="primary" onClick={() => setTok(null)}>{L('تم', 'Done')}</Btn>}>
      <p className="text-[13px] text-ink-2">{L('اكتب هذا الرمز على البطاقة (الصفحات 4–7) بأداة الكتابة، ثم فعّل TOKEN=1 في ملف إعداد الجسر. البطاقة المنسوخة بلا الرمز تُرفض حين يكون أمان البطاقة «متوسطاً» أو «مرتفعاً».', 'Write this code to the card (pages 4–7) with the writer tool, then set TOKEN=1 in the bridge settings file. A cloned card without the code is refused when card security is “medium” or “high”.')}</p>
      <div className="mt-3 rounded-xl bg-sub p-3 text-center font-mono text-[18px] ltr select-all">{tok}</div></Modal>}
    <Panel title={<span className="flex items-center gap-2"><CreditCard size={15} />{L('اعتمادات الحضور', 'Attendance credentials')}</span>} action={edit && <Btn size="sm" icon={<Plus size={13} />} onClick={() => setAdd(true)}>{L('إصدار', 'Issue')}</Btn>}>
      {data.credentials.length === 0 ? <p className="text-[13px] text-ink-3">{L('لا توجد بطاقات أو أرقام بصمة مسجّلة.', 'No cards or fingerprint numbers registered.')}</p> :
        <div className="space-y-2">{data.credentials.map(c => <div key={c.id} className={`rounded-lg border border-line p-2.5 ${c.status === 'active' ? '' : 'opacity-70'}`}>
          <div className="flex items-center justify-between gap-2"><span className="font-medium text-[13.5px]">{L(...CRED_TYPE[c.type])} <span className="ltr inline-block font-mono text-[12.5px] text-ink-2">{c.identifier}</span></span>
            <Badge tone={CRED_STATUS[c.status][2]}>{L(CRED_STATUS[c.status][0], CRED_STATUS[c.status][1])}</Badge></div>
          <div className="text-[12px] text-ink-3 num">{c.created_at ? fmtDate(c.created_at, lang) : ''}{c.valid_to ? ` · ${L('حتى', 'until')} ${fmtDate(c.valid_to, lang)}` : ''}{c.reason ? ` · ${c.reason}` : ''}</div>
          {edit && <div className="flex flex-wrap gap-1 mt-1.5">{c.status === 'active' ? <>
            <Btn size="sm" kind="ghost" onClick={() => setAct({ c, status: 'lost' })}>{L('إبلاغ فقد', 'Report lost')}</Btn>
            <Btn size="sm" kind="ghost" onClick={() => setAct({ c, status: 'suspended' })}>{L('إيقاف', 'Suspend')}</Btn>
            {c.type === 'nfc' && <Btn size="sm" kind="ghost" onClick={() => newCardToken(c.id).then(r => setTok(r.token)).catch(x => toast((x as Error).message))}>{c.has_token ? L('رمز ذاكرة جديد', 'New memory code') : L('رمز ذاكرة البطاقة', 'Card memory code')}</Btn>}</>
            : c.status === 'suspended' && <Btn size="sm" kind="ghost" onClick={() => st.mutate({ id: c.id, status: 'active' }, { onSuccess: () => toast(L('أُعيد التفعيل', 'Reactivated')), onError: x => toast((x as Error).message) })}>{L('إعادة تفعيل', 'Reactivate')}</Btn>}</div>}
        </div>)}</div>}
      <p className="mt-3 text-[12px] text-ink-3">{L('البطاقة المفقودة أو الموقوفة تُرفض عند استخدامها وتصل الموارد البشرية بتنبيه. انتهاء الخدمة يعطّل كل الاعتمادات تلقائياً.', 'A lost or suspended card is refused when used and HR is alerted. Ending service disables every credential automatically.')}</p>
    </Panel>
    <Panel title={<span className="flex items-center gap-2"><MapPin size={15} />{L('الفروع المصرّح بها', 'Authorised branches')}</span>} action={edit && <Btn size="sm" icon={<Plus size={13} />} onClick={() => setBr(true)}>{L('إضافة فرع', 'Add branch')}</Btn>}>
      <div className="flex items-center justify-between text-[13.5px] py-1"><span>{data.home ? bi(data.home, lang) : '—'}</span><Badge tone="brand">{L('الفرع الأساسي', 'Home branch')}</Badge></div>
      {data.branches.map(a => <div key={a.id} className={`flex items-center justify-between gap-2 text-[13.5px] py-1 border-t border-line-2 ${a.live ? '' : 'opacity-60'}`}>
        <span>{a.branch ? bi(a.branch, lang) : '—'}<span className="block text-[12px] text-ink-3 num">{L(...ACCESS_KIND[a.kind] as [string, string])}{a.from ? ` · ${fmtDate(a.from, lang)} — ${fmtDate(a.to ?? a.from, lang)}` : ''}{a.note ? ` · ${a.note}` : ''}</span></span>
        {edit && <button className="p-1.5 rounded hover:bg-sub text-ink-3" aria-label={L('إلغاء', 'Remove')} onClick={() => rm.mutate(a.id, { onSuccess: () => toast(L('أُلغيت الصلاحية', 'Access removed')) })}><Trash2 size={14} /></button>}</div>)}
      {data.missions.map(m => <div key={m.id} className="flex items-center justify-between gap-2 text-[13.5px] py-1 border-t border-line-2">
        <span>{m.branches.length ? m.branches.map(b => bi(b, lang)).join('، ') : m.title}<span className="block text-[12px] text-ink-3 num"><span className="ltr inline-block">{m.ref}</span>{m.from ? ` · ${fmtDate(m.from, lang)} — ${fmtDate(m.to ?? m.from, lang)}` : ''}</span></span>
        <Badge tone="info"><Plane size={11} />{L('مهمة', 'Mission')}</Badge></div>)}
      <p className="mt-3 text-[12px] text-ink-3">{L('المهمة المعتمدة إلى فرع آخر تمنح صلاحية مؤقتة لتواريخها تلقائياً، وتُربط البصمة برقم المهمة.', 'An approved mission to another branch grants temporary access for its dates automatically, and the punch is linked to the mission number.')}</p>
    </Panel>
    {add && <AddCredential id={id} onClose={() => setAdd(false)} />}
    {br && <AddBranch id={id} locations={data.locations.filter(l => l.id !== data.home?.id)} onClose={() => setBr(false)} />}
    {act && <DecisionDialog open onClose={() => setAct(null)} tone="danger" reasonRequired minLength={3}
      title={act.status === 'lost' ? L('إبلاغ عن فقد البطاقة', 'Report the card lost') : L('إيقاف الاعتماد', 'Suspend the credential')}
      reasonLabel={L('السبب', 'Reason')} confirmLabel={L('تأكيد', 'Confirm')}
      onConfirm={reason => st.mutateAsync({ id: act.c.id, status: act.status, reason }).then(() => { toast(L('حُفظ', 'Saved')); setAct(null) })} />}
  </div>
}

function AddCredential({ id, onClose }: { id: number; onClose: () => void }) {
  const L = useL()
  const m = useAddCredential()
  const [v, setV] = useState({ type: 'fingerprint' as CredentialRow['type'], identifier: '', label: '', valid_to: '' })
  const err = m.error instanceof ApiError ? m.error : null
  return <Modal open onClose={onClose} width={480} title={L('إصدار اعتماد حضور', 'Issue an attendance credential')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={!v.identifier.trim() || m.isPending} onClick={() => m.mutate({ id, v: { ...v, valid_to: v.valid_to || null } }, { onSuccess: () => { toast(L('صدر الاعتماد', 'Issued')); onClose() } })}>{L('إصدار', 'Issue')}</Btn></>}>
    <div className="space-y-3">
      <Field label={L('النوع', 'Type')} div><Seg value={v.type} onChange={type => setV({ ...v, type })} options={(Object.keys(CRED_TYPE) as CredentialRow['type'][]).map(k => ({ v: k, label: L(CRED_TYPE[k][0], CRED_TYPE[k][1]) }))} /></Field>
      <Field label={v.type === 'nfc' ? L('رقم البطاقة', 'Card number') : v.type === 'fingerprint' ? L('رقم المستخدم في البصّامة', 'User number on the terminal') : L('المعرّف', 'Identifier')} req error={err?.field('identifier')}
        hint={v.type === 'nfc' ? L('لبطاقة الحضور الرئيسية استخدم «ربط بطاقة» في البطاقة العامة — البطاقة السابقة تصبح مستبدلة.', 'For the main attendance card use “Link card” — the previous card becomes replaced.') : undefined}>
        <input className="field ltr font-mono" value={v.identifier} onChange={e => setV({ ...v, identifier: e.target.value })} /></Field>
      <Field label={L('ملاحظة', 'Label')}><input className="field" value={v.label} onChange={e => setV({ ...v, label: e.target.value })} /></Field>
      <Field label={L('صالح حتى (اختياري)', 'Valid until (optional)')}><input type="date" className="field" value={v.valid_to} onChange={e => setV({ ...v, valid_to: e.target.value })} /></Field>
    </div>
  </Modal>
}

function AddBranch({ id, locations, onClose }: { id: number; locations: { id: number; ar: string; en: string }[]; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const m = useAddBranchAccess()
  const [v, setV] = useState({ location_id: locations[0]?.id ?? 0, kind: 'temporary' as 'permanent' | 'temporary' | 'secondment', from: '', to: '', note: '' })
  const err = m.error instanceof ApiError ? m.error : null
  return <Modal open onClose={onClose} width={480} title={L('صلاحية فرع إضافي', 'Extra branch access')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={!v.location_id || m.isPending} onClick={() => m.mutate({ id, v: { ...v, from: v.from || null, to: v.to || null } }, { onSuccess: () => { toast(L('أُضيفت الصلاحية', 'Access added')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="space-y-3">
      <Field label={L('الفرع', 'Branch')} req error={err?.field('location_id')}><select className="field" value={v.location_id} onChange={e => setV({ ...v, location_id: Number(e.target.value) })}>{locations.map(l => <option key={l.id} value={l.id}>{bi(l, lang)}</option>)}</select></Field>
      <Field label={L('النوع', 'Kind')} div><Seg value={v.kind} onChange={kind => setV({ ...v, kind })} options={(['permanent', 'temporary', 'secondment'] as const).map(k => ({ v: k, label: L(ACCESS_KIND[k][0], ACCESS_KIND[k][1]) }))} /></Field>
      {v.kind !== 'permanent' && <div className="grid grid-cols-2 gap-2">
        <Field label={L('من', 'From')} req error={err?.field('from')}><input type="date" className="field" value={v.from} onChange={e => setV({ ...v, from: e.target.value })} /></Field>
        <Field label={L('إلى', 'To')} req error={err?.field('to')}><input type="date" className="field" value={v.to} min={v.from} onChange={e => setV({ ...v, to: e.target.value })} /></Field></div>}
      <Field label={L('ملاحظة', 'Note')}><input className="field" value={v.note} onChange={e => setV({ ...v, note: e.target.value })} placeholder={L('مثال: مشرف يتنقل بين الفروع', 'e.g. supervisor moving between branches')} /></Field>
    </div>
  </Modal>
}
