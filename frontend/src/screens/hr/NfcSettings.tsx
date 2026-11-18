import { useEffect, useState } from 'react'
import { Loader2, RotateCcw } from 'lucide-react'
import { useL } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useNfcSettings, useSaveNfcSettings, type NfcSettings as Data } from '../../lib/nfc'
import { Btn, Field, PageHead, Panel, Seg, toast } from '../../ui/kit'

/** إعدادات بوابة الحضور متعددة الفروع (الدفعة 21): كل قرار مفتوح في الجزء C قابل للضبط مع قيمة افتراضية */
export function NfcSettings() {
  const L = useL()
  const q = useNfcSettings(); const save = useSaveNfcSettings()
  const [v, setV] = useState<Data | null>(null)
  useEffect(() => { if (q.data && !v) setV(q.data.data) }, [q.data, v])
  if (!q.data || !v) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const edit = q.data.can.edit
  const e = save.error instanceof ApiError ? save.error : null
  const num = (k: keyof Data, label: string, hint?: string, unit?: string) => <Field label={label} hint={hint} error={e?.field(k)}>
    <div className="flex items-center gap-2"><input className="field ltr num !w-28" inputMode="numeric" disabled={!edit} value={String(v[k])} onChange={x => setV({ ...v, [k]: Number(x.target.value.replace(/\D/g, '')) || 0 })} />{unit && <span className="text-[13px] text-ink-3">{unit}</span>}</div></Field>
  const onOff = (k: keyof Data, label: string, hint?: string) => <Field label={label} hint={hint} div><Seg value={v[k] ? 'on' : 'off'} onChange={x => edit && setV({ ...v, [k]: x === 'on' })} options={[{ v: 'on', label: L('نعم', 'Yes') }, { v: 'off', label: L('لا', 'No') }]} /></Field>
  const sec = L('ثانية', 's'), min = L('دقيقة', 'min')
  return <div className="max-w-[900px] mx-auto space-y-5">
    <PageHead title={L('إعدادات بوابة الحضور', 'Attendance gate settings')} sub={L('الجهاز يعرّف الشركة والفرع، والبطاقة تُبحث داخل الشركة فقط. هنا تحدد ما يحدث عند البصم في فرع آخر، وتنبيهات الأمان، والحضور بملصق الفرع.', 'The device identifies the company and branch; cards are looked up within the company only. Here you decide what happens at another branch, the security alerts and branch-sticker check-in.')}
      actions={edit && <><Btn icon={<RotateCcw size={15} />} onClick={() => setV(q.data!.defaults)}>{L('القيم الافتراضية', 'Defaults')}</Btn>
        <Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => toast(L('حُفظت الإعدادات', 'Settings saved')) })}>{L('حفظ', 'Save')}</Btn></>} />
    <Panel title={L('صلاحية الفرع', 'Branch access')}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">
        <Field label={L('البصم في فرع غير مصرّح', 'Punch at an unauthorised branch')} div hint={L('«قبول مع تعليم» يسجّل الحضور ويضعه في قائمة المراجعة.', '“Accept & flag” records attendance and puts it on the review list.')}>
          <Seg value={v.off_branch} onChange={x => edit && setV({ ...v, off_branch: x })} options={[{ v: 'flag', label: L('قبول مع تعليم', 'Accept & flag') }, { v: 'reject', label: L('رفض', 'Refuse') }]} /></Field>
        {onOff('mission_access', L('المهمة المعتمدة تمنح صلاحية الفرع', 'An approved mission grants branch access'), L('لتواريخ المهمة فقط، وتُربط البصمة برقمها.', 'For the mission dates only; the punch is linked to it.'))}
        {num('duplicate_seconds', L('تجاهل القراءة المكررة خلال', 'Ignore a repeated read within'), undefined, sec)}
        <Field label={L('الدخول والخروج', 'In and out')} div hint={L('«حسب الجهاز»: جهاز الدخول يسجّل دخولاً وجهاز الخروج خروجاً، وإلا فحسب ترتيب البصمات.', '“By device”: an in-device records in and an out-device out; otherwise by the order of punches.')}>
          <Seg value={v.direction} onChange={x => edit && setV({ ...v, direction: x })} options={[{ v: 'auto', label: L('حسب الجهاز', 'By device') }, { v: 'sequence', label: L('حسب الترتيب', 'By order') }]} /></Field>
      </div>
    </Panel>
    <Panel title={L('الأجهزة والأمان', 'Devices & security')}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">
        <Field label={L('توقيع رسائل الأجهزة', 'Device message signing')} div hint={L('«إلزامي» لكل الأجهزة: الرسالة غير الموقّعة تُرفض. أو اختياري يُحدَّد لكل جهاز.', '“Required” for all devices: unsigned messages are refused. Or optional, set per device.')}>
          <Seg value={v.signature} onChange={x => edit && setV({ ...v, signature: x })} options={[{ v: 'optional', label: L('حسب الجهاز', 'Per device') }, { v: 'required', label: L('إلزامي', 'Required') }]} /></Field>
        {num('activation_hours', L('صلاحية رمز تفعيل الجهاز', 'Device activation code validity'), undefined, L('ساعة', 'h'))}
        {num('offline_minutes', L('تنبيه «جهاز منقطع» بعد', '“Device offline” alert after'), undefined, min)}
        {num('drift_seconds', L('أقصى فرق مقبول في ساعة الجهاز', 'Max accepted device clock drift'), undefined, sec)}
        <Field label={L('مستوى أمان البطاقة', 'Card security level')} div hint={L('يحدد نوع القارئ المطلوب عند شراء الأجهزة: رقم البطاقة، أو رمز في ذاكرتها، أو بطاقات مشفّرة.', 'Decides the reader type when buying devices: card number, a code in its memory, or encrypted cards.')}>
          <Seg value={v.card_security} onChange={x => edit && setV({ ...v, card_security: x })} options={[{ v: 'uid', label: L('أساسي', 'Basic') }, { v: 'memory', label: L('متوسط', 'Medium') }, { v: 'secure', label: L('مرتفع', 'High') }]} /></Field>
        {onOff('auto_disable', L('تعطيل البطاقات تلقائياً عند انتهاء الخدمة', 'Disable cards automatically when service ends'))}
      </div>
    </Panel>
    <Panel title={L('التنبيهات', 'Alerts')}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">
        {num('unknown_alert', L('بطاقة غير معروفة تتكرر في اليوم', 'Unknown card repeated in a day'), L('عند بلوغ العدد تصل الموارد البشرية بتنبيه.', 'HR is alerted when this count is reached.'), L('مرات', 'times'))}
        {num('buddy_seconds', L('بصمتان متلاصقتان خلال', 'Back-to-back punches within'), L('0 = إيقاف هذا الفحص.', '0 = switch this check off.'), sec)}
        {num('buddy_repeat', L('…وتكرر ذلك للزوج نفسه خلال 30 يوماً', '…repeated for the same pair within 30 days'), undefined, L('مرات', 'times'))}
      </div>
    </Panel>
    <Panel title={L('الحضور بملصق الفرع', 'Branch-sticker check-in')}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">
        {onOff('drift_correct', L('تصحيح وقت الحدث بانحراف ساعة الجهاز', 'Correct event times by the device clock drift'), L('حين يتجاوز الانحراف المقبول أعلاه.', 'When the drift exceeds the accepted value above.'))}
        {onOff('externals', L('بطاقات غير الموظفين تسجّل ساعات المشاركة', 'Non-employee cards record participation hours'), L('النقرتان في اليوم ← ساعات بانتظار المشرف.', 'Two taps a day → hours awaiting the supervisor.'))}
        {onOff('sticker', L('السماح بالحضور بمسح ملصق الفرع', 'Allow check-in by scanning the branch sticker'), L('بديل عن القارئ للفروع الصغيرة: ملصق QR أو NFC يمسحه الموظف بهاتفه.', 'An alternative to readers for small branches: a QR or NFC sticker scanned with the phone.'))}
        <Field label={L('ربط الحضور بالهاتف بجهاز مسجّل', 'Bind phone check-in to a registered device')} hint={L('«أول جهاز» يُسجَّل تلقائياً عند أول حضور؛ «مسجّل فقط» يتطلب تسجيلاً مسبقاً. تُلغي الموارد البشرية الربط من ملف الموظف.', '“First device” registers on the first check-in; “registered only” requires prior registration. HR unlinks from the employee file.')} div>
          <Seg value={String(v.sticker_device ?? 'off')} onChange={x => edit && setV({ ...v, sticker_device: x as 'off' })} options={[{ v: 'off', label: L('بلا ربط', 'Off') }, { v: 'bind_first', label: L('أول جهاز', 'First device') }, { v: 'registered', label: L('مسجّل فقط', 'Registered only') }]} /></Field>
        {onOff('sticker_geo', L('اشتراط الموقع الجغرافي ضمن نطاق الفرع', 'Require the location within the branch area'), L('النطاق يُحدد لكل فرع في صفحة الفروع.', 'The radius is set per branch on the branches page.'))}
      </div>
    </Panel>
  </div>
}
