import { useEffect, useState } from 'react'
import { Loader2, RotateCcw, Sparkles } from 'lucide-react'
import { useL } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { can, useMe } from '../../lib/session'
import { openAssistant, useAssistantSettings, useSaveAssistantSettings, type ActionKind, type AssistantSettings as Data } from '../../lib/assistant'
import { Btn, Field, PageHead, Panel, Seg, toast } from '../../ui/kit'

/** إعدادات «المساعد» للشركة: التفعيل، الملخصات، الإجراءات المسموحة، الحد اليومي، الاحتفاظ بالمحادثات — مع الاستخدام */
export function AssistantSettings() {
  const L = useL()
  const me = useMe().data
  const q = useAssistantSettings(); const save = useSaveAssistantSettings()
  const [v, setV] = useState<Data | null>(null)
  useEffect(() => { if (q.data && !v) setV(q.data.data) }, [q.data, v])
  if (!q.data || !v) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const edit = !!me && can(me, 'settings', 'edit')
  const e = save.error instanceof ApiError ? save.error : null
  const onOff = (val: boolean, fn: (b: boolean) => void, on = L('مفعّل', 'On'), off = L('متوقف', 'Off')) => <Seg value={val ? 'on' : 'off'} onChange={x => edit && fn(x === 'on')} options={[{ v: 'on', label: on }, { v: 'off', label: off }]} />
  const acts: [ActionKind, string, string][] = [['leave', L('طلب إجازة', 'Leave request'), L('يجهّز الطلب من كلام الموظف (النوع والتواريخ والبديل والسبب) ويفحصه بقواعد الإجازات.', 'Prepares the request from what the employee says and checks it with the leave rules.')],
    ['justify', L('تبرير تأخير أو غياب', 'Justification'), L('للأيام التي ما زالت ضمن مهلة التبرير.', 'For days still within the justification window.')],
    ['certificate', L('طلب شهادة', 'Certificate request'), L('شهادة راتب أو شهادة عمل، ببيانات الجهة.', 'Salary or employment certificate, with the addressee.')],
    ['praise', L('شكر زميل', 'Praise a colleague'), L('ينشر الشكر في الساحة وفق إعدادات الشكر.', 'Posts the praise in the Space under the praise settings.')]]
  const u = q.data.usage
  return <div className="max-w-[900px] mx-auto space-y-5">
    <PageHead title={L('إعدادات المساعد', 'Assistant settings')} sub={L('المساعد يجيب الموظفين من بياناتهم وسياسات الشركة ودليل الاستخدام، ويجهّز طلباتهم ليؤكدوها بأنفسهم.', 'The assistant answers employees from their own data, company policies and the user guide, and prepares requests for them to confirm.')}
      actions={<><Btn icon={<Sparkles size={15} />} onClick={() => openAssistant()}>{L('تجربة المساعد', 'Try the assistant')}</Btn>
        {edit && <><Btn icon={<RotateCcw size={15} />} onClick={() => setV(q.data!.defaults)}>{L('القيم الافتراضية', 'Defaults')}</Btn>
          <Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => toast(L('حُفظت الإعدادات', 'Settings saved')) })}>{L('حفظ', 'Save')}</Btn></>}</>} />
    <Panel title={L('الاستخدام هذا الشهر', 'Usage this month')}>
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(140px,1fr))]">
        {[[L('أسئلة اليوم', 'Questions today'), u.today], [L('أسئلة هذا الشهر', 'Questions this month'), u.month], [L('مستخدمون', 'Users'), u.users], [L('طلبات أُرسلت', 'Requests sent'), u.actions], [L('بلا إجابة', 'Unanswered'), u.unanswered]].map(([l, n]) =>
          <div key={String(l)} className="rounded-xl border border-line px-3 py-2"><div className="text-[12px] text-ink-3">{l}</div><div className="font-display font-bold text-[22px] num">{n}</div></div>)}
      </div>
      {u.unanswered > 0 && <p className="mt-3 text-[12.5px] text-ink-3">{L('الأسئلة بلا إجابة تعني غالباً أن الموضوع غير موجود في مكتبة السياسات — أضف الوثيقة أو الفقرة المناسبة ليجيب المساعد عنها.', 'Unanswered questions usually mean the topic is missing from the policy library — add the document or paragraph so the assistant can answer.')}</p>}
    </Panel>
    <Panel title={L('عام', 'General')}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))]">
        <Field label={L('المساعد', 'Assistant')} div>{onOff(v.enabled, b => setV({ ...v, enabled: b }))}</Field>
        <Field label={L('خلاصة الإجابة من السياسات', 'Summary of policy answers')} hint={q.data.summaries_ready ? L('متاحة الآن: تُكتب خلاصة قصيرة من الفقرات مع أرقام مصادرها.', 'Available now: a short summary is written from the passages with their source numbers.') : L('غير متاحة حالياً على الخادم — تظهر الفقرات وحدها.', 'Not available on the server right now — the passages are shown alone.')} div>{onOff(v.summaries, b => setV({ ...v, summaries: b }))}</Field>
        <Field label={L('الحد اليومي لأسئلة المستخدم', 'Daily questions per user')} error={e?.field('daily_limit')}><input type="number" className="field num ltr text-start" disabled={!edit} value={v.daily_limit} onChange={x => setV({ ...v, daily_limit: Number(x.target.value) })} /></Field>
        <Field label={L('الاحتفاظ بالمحادثات (يوماً)', 'Keep conversations (days)')} error={e?.field('history_days')}><input type="number" className="field num ltr text-start" disabled={!edit} value={v.history_days} onChange={x => setV({ ...v, history_days: Number(x.target.value) })} /></Field>
      </div>
    </Panel>
    <Panel title={L('الإجراءات من المحادثة', 'Actions from the chat')} sub={L('كل إجراء يظهر في بطاقة يراجعها الموظف ويعدّلها، ولا يُرسل إلا بخطوتي تأكيد، وبالقواعد نفسها المطبقة في صفحته.', 'Each action appears as a card the employee reviews and edits; it is sent only after two confirmation steps and under the same rules as its page.')}>
      <ul className="divide-y divide-line-2">{acts.map(([k, t, d]) => <li key={k} className="py-3 flex flex-wrap items-center gap-3">
        <div className="flex-1 min-w-[220px]"><div className="font-semibold text-[14px]">{t}</div><div className="text-[12.5px] text-ink-3">{d}</div></div>
        {onOff(v.actions[k], b => setV({ ...v, actions: { ...v.actions, [k]: b } }), L('مسموح', 'Allowed'), L('غير مسموح', 'Off'))}</li>)}</ul>
    </Panel>
    <Panel title={L('ما لا يفعله المساعد', 'What the assistant never does')}>
      <ul className="text-[13px] text-ink-2 space-y-1.5 list-disc ps-5">
        <li>{L('لا يعرض أي مبلغ راتب — تبقى المبالغ خلف التحقق من الهوية في صفحاتها.', 'It never shows salary amounts — they stay behind identity verification on their pages.')}</li>
        <li>{L('لا يرى إلا ما يراه المستخدم نفسه حسب صلاحياته، ولا بيانات شركة أخرى.', 'It only sees what the user can see under their permissions, never another company’s data.')}</li>
        <li>{L('لا يرسل طلباً ولا ينشر شيئاً دون تأكيد صاحب الحساب.', 'It never sends or posts anything without the account owner’s confirmation.')}</li>
        <li>{L('يعتمد في السياسات على مكتبة السياسات المنشورة ودليل الاستخدام فقط، ويذكر المصدر.', 'For policies it relies only on the published policy library and the user guide, and cites the source.')}</li>
      </ul>
    </Panel>
  </div>
}
