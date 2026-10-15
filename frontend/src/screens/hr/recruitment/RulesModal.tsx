import { useEffect, useState } from 'react'
import { useL, useLang } from '../../../lib/store'
import { can, useMe } from '../../../lib/session'
import { useRules, useSaveRules, type Rules } from '../../../lib/candidates'
import { STAGES, type Stage } from '../../../lib/recruitment'
import type { ApiError } from '../../../lib/api'
import { Btn, Modal, toast } from '../../../ui/kit'

const SLA_STAGES: Stage[] = ['new', 'screening', 'interview', 'technical', 'offer']

/** قواعد الأتمتة (RulesModal في الديمو) بنطاقات v3 ومهل المراحل — بدون «وضع العرض». التعديل لصلاحية الإعدادات */
export function RulesModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const me = useMe().data
  const editable = can(me, 'settings', 'edit')
  const { data } = useRules()
  const save = useSaveRules()
  const [r, setR] = useState<Rules | null>(null); const [sla, setSla] = useState<Record<string, number>>({})
  useEffect(() => { if (open && data) { setR(data.rules); setSla(data.stage_sla) } }, [open, data])
  if (!r) return null
  const tog = (k: 'auto_screen' | 'auto_reject' | 'sla_alerts' | 'whatsapp_nudge', label: string, hint: string) => <button type="button" disabled={!editable} onClick={() => setR({ ...r, [k]: !r[k] })} className="w-full flex items-center justify-between gap-4 py-3 border-b border-line-2 text-start disabled:cursor-default" aria-pressed={r[k]}>
    <span><span className="block text-[14px] font-semibold">{label}</span><span className="block text-[12px] text-ink-3">{hint}</span></span>
    <span className={`w-11 h-6 shrink-0 rounded-full p-0.5 transition-colors ${r[k] ? 'bg-brand' : 'bg-sub border border-line'}`}><span className={`block size-5 rounded-full bg-white transition-transform ${r[k] ? 'translate-x-5 rtl:-translate-x-5' : ''}`} /></span></button>
  const num = (k: 'match_threshold' | 'review_min' | 'reject_max', label: string) => <label className="flex items-center justify-between gap-3 text-[13px]"><span>{label}</span>
    <input type="number" disabled={!editable} className="field !w-20 num text-center" value={r[k]} onChange={e => setR({ ...r, [k]: Number(e.target.value) })} /></label>
  const submit = () => save.mutate({ rules: r, stage_sla: sla }, { onSuccess: () => { onClose(); toast(L('حُفظت القواعد', 'Rules saved')) }, onError: e => toast((e as ApiError).message) })
  return <Modal open={open} onClose={onClose} title={L('قواعد الأتمتة', 'Automation rules')} width={580}
    footer={editable ? <><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} onClick={submit}>{L('حفظ', 'Save')}</Btn></> : <Btn onClick={onClose}>{L('إغلاق', 'Close')}</Btn>}>
    <div className="rounded-xl bg-sub p-4">
      <div className="flex justify-between"><span className="font-semibold text-[14px]">{L('حد المطابقة للانتقال التلقائي للفرز', 'Match gate for auto-screening')}</span><span className="font-black font-display text-brand num text-[20px]">{r.match_threshold}%</span></div>
      <input type="range" min={45} max={95} step={5} disabled={!editable} value={r.match_threshold} onChange={e => setR({ ...r, match_threshold: Number(e.target.value) })} className="w-full mt-3 accent-[var(--primary)]" />
      <div className="grid sm:grid-cols-2 gap-x-6 gap-y-2 mt-3">
        {num('review_min', L('بداية «مطابقة منخفضة — للمراجعة»', 'Low match — review from'))}
        {num('reject_max', L('حد الرفض التلقائي (≤)', 'Auto-decline at or below'))}
      </div>
      <div className="text-[12px] text-ink-3 mt-2">{L(`≥ ${r.match_threshold}٪ فرز تلقائي · ${r.review_min}–${r.match_threshold - 1}٪ للمراجعة · ≤ ${r.reject_max}٪ رفض تلقائي بإشعار مهذب (إذا مفعّل).`, `≥ ${r.match_threshold}% auto-screened · ${r.review_min}–${r.match_threshold - 1}% for review · ≤ ${r.reject_max}% auto-declined with a courteous notice (if on).`)}</div>
    </div>
    {tog('auto_screen', L('فرز تلقائي للطلبات الجديدة', 'Auto-screen new applications'), L('تطبيق حد المطابقة لحظة استلام الطلب', 'Apply the match gate on arrival'))}
    {tog('auto_reject', L('رفض تلقائي دون الحد الأدنى', 'Auto-decline below the minimum'), L('الطلبات ضمن نطاق الرفض تُغلق فوراً برسالة مهذبة للمتقدم', 'Applications in the decline band close at once with a courteous message'))}
    {tog('sla_alerts', L('تنبيه تجاوز مهلة المرحلة', 'Stage SLA alerts'), L('إشعار عند بقاء المتقدم في مرحلة أكثر من المهلة', 'Notify when an applicant overstays a stage'))}
    {tog('whatsapp_nudge', L('رسائل واتساب تلقائية', 'Automatic WhatsApp messages'), L('دعوة المقابلة وتذكير المرشح — حسب القنوات المفعّلة في الإعدادات', 'Interview invites and reminders — per the channels enabled in settings'))}
    <div className="py-3 border-b border-line-2">
      <div className="text-[14px] font-semibold mb-2">{L('مهلة كل مرحلة (أيام)', 'Stage limits (days)')}</div>
      <div className="grid grid-cols-2 sm:grid-cols-5 gap-2">{SLA_STAGES.map(s => <label key={s} className="text-[12px] text-ink-3"><span className="block truncate mb-1">{STAGES[s][lang]}</span>
        <input type="number" min={0} max={60} disabled={!editable} className="field num text-center" value={sla[s] ?? 0} onChange={e => setSla({ ...sla, [s]: Number(e.target.value) })} /></label>)}</div>
    </div>
    <div className="flex items-center justify-between py-3"><span className="text-[14px] font-semibold">{L('نقاط الإحالة عند التعيين', 'Referral points per hire')}</span>
      <input type="number" step={50} disabled={!editable} className="field !w-28 num" value={r.referral_points} onChange={e => setR({ ...r, referral_points: Number(e.target.value) })} /></div>
  </Modal>
}
