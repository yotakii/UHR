import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Loader2, Send } from 'lucide-react'
import { useL } from '../../lib/store'
import { api, ApiError } from '../../lib/api'
import { queryClient } from '../../lib/session'
import { Badge, Btn, Field, PageHead, Panel, Seg, toast } from '../../ui/kit'

type PushInfo = { enabled: boolean; configured: boolean; project_id: string | null; devices: number; users: number }

/** المنصة: إشعارات تطبيق الجوال — مفتاح حساب الخدمة من مشروع Firebase يُلصق مرة واحدة (يُحفظ مشفّراً ولا يُعرض) */
export function PushSettings() {
  const L = useL()
  const q = useQuery({ queryKey: ['platform', 'push'], queryFn: () => api<{ data: PushInfo }>('/platform/push').then(r => r.data) })
  const save = useMutation({ mutationFn: (v: { enabled: boolean; credentials?: string }) => api<{ data: PushInfo }>('/platform/push', { method: 'PUT', json: v }).then(r => r.data),
    onSuccess: d => { queryClient.setQueryData(['platform', 'push'], d); setKey(''); toast(L('حُفظ', 'Saved')) } })
  const test = useMutation({ mutationFn: () => api<{ sent: number }>('/platform/push/test', { json: {} }) })
  const [key, setKey] = useState('')
  const d = q.data
  if (!d) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const err = save.error instanceof ApiError ? save.error : null
  return <div className="max-w-[900px] mx-auto space-y-5">
    <PageHead title={L('إشعارات الجوال', 'Mobile notifications')} sub={L('إشعارات تطبيق الموظفين على الجوال حتى عندما يكون التطبيق مغلقاً.', 'Employee app notifications, even when the app is closed.')} />
    <Panel>
      <div className="flex flex-wrap items-center gap-3 text-[13.5px]">
        {d.configured ? <Badge tone="brand">{L('المفتاح محفوظ', 'Key saved')} · <span className="ltr">{d.project_id ?? '—'}</span></Badge> : <Badge tone="brass">{L('لم يُضف المفتاح بعد', 'No key yet')}</Badge>}
        <span className="text-ink-3">{L('الأجهزة المسجّلة', 'Registered devices')}: <span className="num">{d.devices}</span> · {L('المستخدمون', 'Users')}: <span className="num">{d.users}</span></span>
      </div>
    </Panel>
    <Panel title={L('الإعداد', 'Settings')}>
      <div className="space-y-4">
        <Field label={L('الإشعارات', 'Notifications')} div><Seg value={d.enabled ? 'on' : 'off'} onChange={x => save.mutate({ enabled: x === 'on' })} options={[{ v: 'on', label: L('مفعّلة', 'On') }, { v: 'off', label: L('متوقفة', 'Off') }]} /></Field>
        <Field label={L('مفتاح حساب الخدمة (JSON)', 'Service account key (JSON)')} error={err?.field('credentials') ?? (err && !err.field('credentials') ? err.message : undefined)}
          hint={L('من Firebase: إعدادات المشروع ← حسابات الخدمة ← «إنشاء مفتاح خاص جديد»، ثم الصق محتوى الملف هنا. يُحفظ مشفّراً ولا يُعرض مرة أخرى.', 'In Firebase: Project settings → Service accounts → “Generate new private key”, then paste the file content here. Stored encrypted and never shown again.')}>
          <textarea className="field ltr text-start font-mono text-[12px] min-h-[140px]" value={key} onChange={e => setKey(e.target.value)} placeholder='{"type": "service_account", ...}' /></Field>
        <div className="flex flex-wrap gap-2">
          <Btn kind="primary" disabled={!key.trim() || save.isPending} onClick={() => save.mutate({ enabled: d.enabled, credentials: key })}>{L('حفظ المفتاح', 'Save key')}</Btn>
          <Btn icon={<Send size={15} />} disabled={!d.configured || test.isPending} onClick={() => test.mutate(undefined, {
            onSuccess: r => toast(r.sent ? L(`أُرسل إلى ${r.sent} جهاز`, `Sent to ${r.sent} device(s)`) : L('لا يوجد جهاز مسجّل لحسابك — ادخل من التطبيق أولاً.', 'No device registered for your account — sign in from the app first.')),
            onError: e => toast(e instanceof ApiError ? e.message : String(e)) })}>{L('إشعار تجريبي لأجهزتي', 'Test to my devices')}</Btn>
        </div>
      </div>
    </Panel>
  </div>
}
