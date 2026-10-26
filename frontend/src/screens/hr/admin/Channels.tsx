import { useEffect, useState } from 'react'
import { Loader2, Mail, MessageCircle, MessageSquare, Save } from 'lucide-react'
import { useL, useLang, bi } from '../../../lib/store'
import { can, useMe } from '../../../lib/session'
import { ApiError } from '../../../lib/api'
import { useChannels, useSaveChannel, useSaveTemplate, type ChannelCfg, type Template } from '../../../lib/admin'
import { Badge, Btn, Field, PageHead, Panel, Tabs, toast } from '../../../ui/kit'

const FIELD_LABEL: Record<string, [string, string]> = { from_name: ['اسم المرسل', 'Sender name'], reply_to: ['بريد الرد', 'Reply-to'], provider: ['المزوّد', 'Provider'],
  sender: ['اسم المرسل', 'Sender ID'], api_key: ['مفتاح API', 'API key'], phone_id: ['رقم الحساب', 'Phone number ID'], token: ['رمز الوصول', 'Access token'] }

/** M16 «قنوات الإشعار»: البريد / الرسائل القصيرة / واتساب، وقوالب الرسائل باللغتين لكل قناة */
export function Channels() {
  const L = useL()
  const q = useChannels(); const d = q.data
  const [ch, setCh] = useState<'email' | 'sms' | 'whatsapp'>('email')
  if (!d) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  return <div className="max-w-[1000px] mx-auto space-y-5">
    <PageHead title={L('قنوات الإشعار', 'Notification channels')} sub={L('البريد هو القناة الأساسية. الرسائل القصيرة وواتساب تعمل عند اعتماد مزوّد وإدخال بياناته. الأسرار لا تُعرض بعد الحفظ.', 'Email is the primary channel. SMS and WhatsApp work once a provider is chosen and configured. Secrets are never shown after saving.')} />
    <div className="grid [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))] gap-4">{d.channels.map(c => <ChannelCard key={c.key} c={c} />)}</div>
    <Panel title={L('قوالب الرسائل', 'Message templates')} sub={<>{L('المتغيرات المتاحة', 'Available placeholders')}: <span className="ltr inline-block">{d.tokens.join('  ')}</span></>}>
      <Tabs value={ch} onChange={setCh} tabs={[{ v: 'email', label: L('البريد', 'Email') }, { v: 'sms', label: 'SMS' }, { v: 'whatsapp', label: 'WhatsApp' }]} />
      <div className="mt-4 space-y-4">{d.templates.filter(t => t.channel === ch).map(t => <TemplateRow key={t.id} t={t} />)}</div>
    </Panel>
  </div>
}

function ChannelCard({ c }: { c: ChannelCfg }) {
  const L = useL()
  const editable = can(useMe().data, 'settings', 'edit')
  const save = useSaveChannel()
  const [on, setOn] = useState(c.enabled)
  const [cfg, setCfg] = useState<Record<string, string>>(() => Object.fromEntries(Object.entries(c.config).map(([k, v]) => [k, v.secret ? '' : v.value ?? ''])))
  useEffect(() => setOn(c.enabled), [c.enabled])
  const err = save.error instanceof ApiError ? save.error : null
  const icon = c.key === 'email' ? <Mail size={20} /> : c.key === 'sms' ? <MessageSquare size={20} /> : <MessageCircle size={20} />
  return <Panel title={<span className="flex items-center gap-2">{icon}{c.key === 'email' ? L('البريد الإلكتروني', 'Email') : c.key === 'sms' ? L('رسائل قصيرة SMS', 'SMS') : 'WhatsApp'}</span>}
    action={c.enabled ? <Badge tone="brand">{L('مفعّلة', 'On')}</Badge> : <Badge>{L('متوقفة', 'Off')}</Badge>}>
    <div className="space-y-3">
      {c.key !== 'email' && <label className="flex items-center gap-2 cursor-pointer text-[13.5px] font-semibold"><input type="checkbox" className="accent-[var(--brand)]" disabled={!editable} checked={on} onChange={e => setOn(e.target.checked)} />{L('تفعيل القناة', 'Turn on')}</label>}
      {Object.entries(c.config).map(([k, v]) => <Field key={k} label={L(...(FIELD_LABEL[k] ?? [k, k]))} error={err?.field(`config.${k}`)} hint={v.secret && v.set ? L('محفوظ — اتركه فارغاً للإبقاء عليه', 'Saved — leave empty to keep it') : undefined}>
        <input className="field ltr text-start" type={v.secret ? 'password' : 'text'} disabled={!editable} autoComplete="off" value={cfg[k] ?? ''} placeholder={v.secret && v.set ? '••••••••' : ''} onChange={e => setCfg({ ...cfg, [k]: e.target.value })} /></Field>)}
      {err && !Object.keys(err.errors).length && <div role="alert" className="rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{err.message}</div>}
      {editable && <Btn size="sm" kind="primary" icon={<Save size={14} />} disabled={save.isPending} onClick={() => save.mutate({ key: c.key, enabled: c.key === 'email' ? true : on, config: cfg }, { onSuccess: () => { toast(L('حُفظت القناة', 'Channel saved')); setCfg(x => Object.fromEntries(Object.entries(x).map(([k, v]) => [k, c.config[k]?.secret ? '' : v]))) } })}>{L('حفظ', 'Save')}</Btn>}
    </div>
  </Panel>
}

function TemplateRow({ t }: { t: Template }) {
  const L = useL(); const lang = useLang()
  const editable = can(useMe().data, 'settings', 'edit')
  const save = useSaveTemplate()
  const [ar, setAr] = useState(t.ar); const [en, setEn] = useState(t.en)
  const err = save.error instanceof ApiError ? save.error : null
  const dirty = ar !== t.ar || en !== t.en
  return <div className="rounded-xl border border-line p-3">
    <div className="flex items-center justify-between gap-2 mb-2"><b className="text-[14px]">{bi(t.label, lang)}</b>
      {editable && dirty && <Btn size="sm" kind="primary" disabled={save.isPending} onClick={() => save.mutate({ id: t.id, ar, en }, { onSuccess: () => toast(L('حُفظ القالب', 'Template saved')) })}>{L('حفظ', 'Save')}</Btn>}</div>
    <div className="grid md:grid-cols-2 gap-3">
      <Field label={L('بالعربية', 'Arabic')} error={err?.field('ar')}><textarea className="field" disabled={!editable} value={ar} onChange={e => setAr(e.target.value)} /></Field>
      <Field label={L('بالإنكليزية', 'English')} error={err?.field('en')}><textarea className="field ltr text-start" disabled={!editable} value={en} onChange={e => setEn(e.target.value)} /></Field>
    </div>
  </div>
}
