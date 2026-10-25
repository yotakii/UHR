import { useEffect, useState, type ReactNode } from 'react'
import { Loader2, Save, RotateCcw, Eye, Scale, SlidersHorizontal, Timer, Trophy, Settings2, ArrowLeft, ArrowRight } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { can, useMe } from '../../lib/session'
import { ApiError } from '../../lib/api'
import { usePreviewMatch, useRules, useSaveRules, type MatchConfig, type Rules } from '../../lib/candidates'
import { STAGES, type Stage } from '../../lib/recruitment'
import { Badge, Btn, PageHead, Panel, Progress, toast } from '../../ui/kit'

const SLA_STAGES: Stage[] = ['new', 'screening', 'interview', 'technical', 'offer']
type W = keyof MatchConfig['weights']
const W_KEYS: W[] = ['exp', 'deg', 'sal', 'eng', 'file']

/**
 * قواعد التوظيف والمطابقة (M16 لكل شركة): كل رقم تعتمد عليه المطابقة والأتمتة يحدده مدير الشركة —
 * الأوزان، ودرجة كل حالة داخل كل معيار، والنطاقات، والمهل، ونقاط الإحالة، ومدة الاحتفاظ بالسير، وعتبة التجربة.
 * «معاينة» تحسب النسب الجديدة لآخر الطلبات قبل الحفظ، والحفظ يعيد حساب الطلبات المفتوحة.
 */
export function RecruitmentSettings() {
  const L = useL(); const lang = useLang()
  const editable = can(useMe().data, 'settings', 'edit')
  const { data } = useRules()
  const save = useSaveRules(); const preview = usePreviewMatch()
  const [r, setR] = useState<Rules | null>(null)
  const [sla, setSla] = useState<Record<string, number>>({})
  const [m, setM] = useState<MatchConfig | null>(null)
  useEffect(() => { if (data) { setR(data.rules); setSla(data.stage_sla); setM(data.match) } }, [data])
  if (!data || !r || !m) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>

  const sum = Object.values(m.weights).reduce((a, b) => a + (Number(b) || 0), 0)
  const dirty = JSON.stringify({ r, sla, m }) !== JSON.stringify({ r: data.rules, sla: data.stage_sla, m: data.match })
  const err = save.error instanceof ApiError ? save.error : preview.error instanceof ApiError ? preview.error : null
  const setW = (k: W, v: number) => setM({ ...m, weights: { ...m.weights, [k]: v } })
  const setG = <G extends Exclude<keyof MatchConfig, 'weights' | 'file'>>(g: G, k: keyof MatchConfig[G], v: number) => setM({ ...m, [g]: { ...m[g], [k]: v } })
  const Arrow = lang === 'ar' ? ArrowLeft : ArrowRight
  const P = lang === 'ar' ? '٪' : '%'

  const num = (value: number, on: (v: number) => void, label: string, o: { max?: number; step?: number; suffix?: string } = {}) =>
    <label className="flex items-center justify-between gap-3 py-1.5 text-[13.5px]"><span className="min-w-0">{label}</span>
      <span className="flex items-center gap-1.5 shrink-0"><input type="number" min={0} max={o.max ?? 100} step={o.step ?? 1} disabled={!editable} value={value} onChange={e => on(Number(e.target.value))} className="field !h-9 !w-20 num text-center" />
        <span className="text-[12px] text-ink-3 w-8">{o.suffix ?? P}</span></span></label>
  const tog = (k: 'auto_screen' | 'auto_reject' | 'sla_alerts' | 'whatsapp_nudge' | 'internal_no_probation', label: string, hint: string) => <button type="button" disabled={!editable} onClick={() => setR({ ...r, [k]: !r[k] })} aria-pressed={r[k]}
    className="w-full flex items-center justify-between gap-4 py-2.5 border-b border-line-2 last:border-0 text-start disabled:cursor-default">
    <span><span className="block text-[14px] font-semibold">{label}</span><span className="block text-[12px] text-ink-3">{hint}</span></span>
    <span className={`w-11 h-6 shrink-0 rounded-full p-0.5 transition-colors ${r[k] ? 'bg-brand' : 'bg-sub border border-line'}`}><span className={`block size-5 rounded-full bg-white transition-transform ${r[k] ? 'translate-x-5 rtl:-translate-x-5' : ''}`} /></span></button>
  const card = (title: ReactNode, children: ReactNode, hint?: ReactNode) => <div className="rounded-xl border border-line p-4 min-w-0"><div className="font-semibold text-[14px] mb-1">{title}</div>{hint && <div className="text-[12px] text-ink-3 mb-2">{hint}</div>}{children}</div>

  const submit = () => save.mutate({ rules: r, stage_sla: sla, match: m }, {
    onSuccess: d => toast(L(`حُفظت القواعد — أُعيد حساب ${d.recomputed} طلباً مفتوحاً`, `Rules saved — ${d.recomputed} open applications re-scored`)), onError: x => toast((x as ApiError).message) })
  const W_LABEL: Record<W, string> = { exp: L('سنوات الخبرة', 'Experience'), deg: L('المؤهل العلمي', 'Qualification'), sal: L('الراتب المتوقع', 'Salary expectation'), eng: L('اللغة الإنكليزية', 'English'), file: L('اكتمال الملف', 'File completeness') }

  return <div className="max-w-[1200px] mx-auto space-y-5">
    <PageHead title={L('قواعد التوظيف والمطابقة', 'Recruitment & matching rules')} sub={L('كل رقم تعتمد عليه المطابقة والأتمتة تحدده شركتك. الحفظ يعيد حساب نسب الطلبات المفتوحة ولا ينقل أحداً بين المراحل.', 'Every number behind matching and automation is set by your company. Saving re-scores open applications without moving anyone between stages.')}
      actions={editable && <>
        <Btn icon={<RotateCcw size={16} />} onClick={() => { setR(data.defaults.rules); setSla(data.defaults.stage_sla); setM(data.defaults.match) }}>{L('القيم الافتراضية', 'Defaults')}</Btn>
        <Btn icon={preview.isPending ? <Loader2 size={16} className="animate-spin" /> : <Eye size={16} />} disabled={preview.isPending || sum !== 100} onClick={() => preview.mutate(m, { onError: x => toast((x as ApiError).message) })}>{L('معاينة', 'Preview')}</Btn>
        <Btn kind="primary" icon={save.isPending ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />} disabled={!dirty || save.isPending || sum !== 100} onClick={submit}>{L('حفظ', 'Save')}</Btn></>} />
    {!editable && <Badge>{L('عرض فقط — التعديل لمدير الإعدادات', 'Read only — settings managers can edit')}</Badge>}

    <Panel title={<span className="flex items-center gap-2"><Scale size={17} className="text-brand" />{L('أوزان المطابقة', 'Match weights')}</span>}
      sub={L('نصيب كل معيار من الـ 100. وزن 0 يلغي المعيار. ويمكن لكل شاغر أوزان خاصة من نموذج الشاغر.', 'Each criterion\'s share of 100. A weight of 0 turns it off. A position can override these in its form.')}
      action={<Badge tone={sum === 100 ? 'brand' : 'danger'}>{L('المجموع', 'Total')} <span className="num">{sum}</span>/100</Badge>}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(200px,1fr))]">{W_KEYS.map(k => <div key={k} className="rounded-xl bg-sub/60 p-3">
        <label className="flex items-center justify-between gap-2 text-[13.5px] font-semibold"><span>{W_LABEL[k]}</span>
          <input type="number" min={0} max={100} step={5} disabled={!editable} value={m.weights[k]} onChange={e => setW(k, Number(e.target.value))} className="field !h-9 !w-20 num text-center font-bold text-brand" /></label>
        <div className="mt-2"><Progress value={m.weights[k]} tone={m.weights[k] ? 'brand' : 'info'} /></div>
      </div>)}</div>
      {err?.field('match.weights') && <div className="text-[13px] text-danger mt-2">{err.field('match.weights')}</div>}
    </Panel>

    <Panel title={<span className="flex items-center gap-2"><SlidersHorizontal size={17} className="text-brand" />{L('كيف يُحسب كل معيار', 'How each criterion is scored')}</span>} sub={L('درجة المتقدم داخل المعيار (من 100٪) حسب حالته، ثم تُضرب بوزن المعيار.', 'The applicant\'s score within a criterion (out of 100%) by case, then multiplied by its weight.')}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]">
        {card(W_LABEL.exp, <>
          {num(m.exp.meets, v => setG('exp', 'meets', v), L('يستوفي الحد الأدنى بالضبط', 'Exactly meets the minimum'))}
          {num(m.exp.bonus_years, v => setG('exp', 'bonus_years', v), L('سنوات إضافية حتى 100٪', 'Extra years to reach 100%'), { max: 20, suffix: L('سنة', 'yrs') })}
          {num(m.exp.below_max, v => setG('exp', 'below_max', v), L('أعلى درجة لمن هو دون الحد', 'Best score when below the minimum'))}</>,
          L('دون الحد: بالتناسب مع السنوات حتى الدرجة المحددة.', 'Below the minimum: proportional to the years, up to this score.'))}
        {card(W_LABEL.deg, <>
          {num(m.deg.above, v => setG('deg', 'above', v), L('أعلى من المطلوب', 'Above the required'))}
          {num(m.deg.equal, v => setG('deg', 'equal', v), L('مساوٍ للمطلوب', 'Equal to the required'))}
          {num(m.deg.one_below, v => setG('deg', 'one_below', v), L('أدنى بدرجة واحدة', 'One level below'))}
          {num(m.deg.lower, v => setG('deg', 'lower', v), L('أدنى من ذلك', 'Lower than that'))}</>)}
        {card(W_LABEL.sal, <>
          {num(m.sal.to_mid, v => setG('sal', 'to_mid', v), L('حتى منتصف نطاق الشاغر', 'Up to the middle of the band'))}
          {num(m.sal.to_max, v => setG('sal', 'to_max', v), L('حتى أعلى النطاق', 'Up to the top of the band'))}
          {num(m.sal.over, v => setG('sal', 'over', v), L('فوق النطاق تبدأ من', 'Above the band, starts at'))}
          {num(m.sal.over_penalty, v => setG('sal', 'over_penalty', v), L('خصم لكل 1٪ زيادة', 'Deduction per 1% above'), { max: 10, step: 0.5, suffix: L('نقطة', 'pts') })}</>)}
        {card(W_LABEL.eng, <>{(['excellent', 'good', 'fair', 'weak'] as const).map(k => <div key={k}>{num(m.eng[k], v => setG('eng', k, v), { excellent: L('ممتاز', 'Excellent'), good: L('جيد', 'Good'), fair: L('مقبول', 'Fair'), weak: L('ضعيف', 'Weak') }[k])}</div>)}</>)}
        {card(W_LABEL.file, <div className="space-y-1.5">{(['cv', 'education', 'experience', 'refs', 'linkedin'] as const).map(k => <label key={k} className="flex items-center gap-2 text-[13.5px]">
          <input type="checkbox" disabled={!editable} checked={m.file[k]} onChange={e => setM({ ...m, file: { ...m.file, [k]: e.target.checked } })} className="size-4 accent-[var(--primary)]" />
          {{ cv: L('السيرة الذاتية مرفوعة', 'CV uploaded'), education: L('المؤهل العلمي معبّأ', 'Education filled'), experience: L('خبرة سابقة واحدة على الأقل', 'At least one past job'), refs: L('مرجع مهني', 'A professional reference'), linkedin: L('رابط LinkedIn', 'LinkedIn link') }[k]}</label>)}
          {err?.field('match.file') && <div className="text-[12px] text-danger">{err.field('match.file')}</div>}</div>,
          L('النسبة = البنود المتوفرة ÷ البنود المختارة.', 'Score = items present ÷ items selected.'))}
      </div>
    </Panel>

    {preview.data && <Panel title={<span className="flex items-center gap-2"><Eye size={17} className="text-brand" />{L('معاينة قبل الحفظ', 'Preview before saving')}</span>} sub={L('آخر الطلبات المفتوحة: النسبة الحالية ← النسبة بالمعايرة الجديدة.', 'Latest open applications: current score → score with the new calibration.')} pad={false}>
      <ul className="divide-y divide-line-2">{preview.data.map(x => { const d = x.new - x.current
        return <li key={x.id} className="px-4 py-2.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[14px]">
          <span className="flex-1 min-w-[180px]"><span className="font-medium">{bi(x.name, lang)}</span> <span className="text-ink-3 text-[12.5px]">· {bi(x.job, lang)}</span></span>
          <span className="flex items-center gap-2 num"><span className="text-ink-3">{x.current}{P}</span><Arrow size={14} className="text-ink-3" /><span className="font-bold">{x.new}{P}</span>
            <span className={`text-[12px] w-12 text-end ${d > 0 ? 'text-brand' : d < 0 ? 'text-danger' : 'text-ink-3'}`}>{d > 0 ? '+' : ''}{d}</span></span></li> })}</ul>
    </Panel>}

    <div className="grid gap-5 [grid-template-columns:repeat(auto-fill,minmax(340px,1fr))]">
      <Panel title={<span className="flex items-center gap-2"><Settings2 size={17} className="text-brand" />{L('نطاقات المطابقة والأتمتة', 'Match bands & automation')}</span>}>
        {num(r.match_threshold, v => setR({ ...r, match_threshold: v }), L('حد الفرز التلقائي (≥)', 'Auto-screen at or above'))}
        {num(r.review_min, v => setR({ ...r, review_min: v }), L('بداية «مطابقة منخفضة — للمراجعة»', 'Low match — review from'))}
        {num(r.reject_max, v => setR({ ...r, reject_max: v }), L('حد الرفض التلقائي (≤)', 'Auto-decline at or below'))}
        {err?.field('rules.review_min') && <div className="text-[12px] text-danger">{err.field('rules.review_min')}</div>}
        <div className="text-[12px] text-ink-3 my-2">{L(`≥ ${r.match_threshold}٪ فرز · ${r.review_min}–${r.match_threshold - 1}٪ مراجعة · ≤ ${r.reject_max}٪ رفض بإشعار مهذب (إذا مفعّل).`, `≥ ${r.match_threshold}% screened · ${r.review_min}–${r.match_threshold - 1}% review · ≤ ${r.reject_max}% declined with a courteous notice (if on).`)}</div>
        {tog('auto_screen', L('فرز تلقائي للطلبات الجديدة', 'Auto-screen new applications'), L('تطبيق حد المطابقة لحظة استلام الطلب', 'Apply the match gate on arrival'))}
        {tog('auto_reject', L('رفض تلقائي دون الحد الأدنى', 'Auto-decline below the minimum'), L('وإلا تذهب الطلبات للمراجعة', 'Otherwise they go to review'))}
        {tog('sla_alerts', L('تنبيه تجاوز مهلة المرحلة', 'Stage SLA alerts'), L('إشعار عند بقاء المتقدم في مرحلة أكثر من المهلة', 'Notify when an applicant overstays a stage'))}
        {tog('whatsapp_nudge', L('رسائل واتساب تلقائية', 'Automatic WhatsApp messages'), L('حسب القنوات المفعّلة', 'Per the enabled channels'))}
      </Panel>
      <div className="space-y-5 min-w-0">
        <Panel title={<span className="flex items-center gap-2"><Timer size={17} className="text-brand" />{L('مهل المراحل (أيام)', 'Stage limits (days)')}</span>}>
          {SLA_STAGES.map(s => <div key={s}>{num(sla[s] ?? 0, v => setSla({ ...sla, [s]: v }), STAGES[s][lang], { max: 60, suffix: L('يوم', 'd') })}</div>)}
        </Panel>
        <Panel title={<span className="flex items-center gap-2"><Trophy size={17} className="text-brand" />{L('نقاط الإحالة المرحلية', 'Staged referral points')}</span>}>
          {[L('تجاوز الفرز الأولي', 'Passed screening'), L('التأهل للمقابلة', 'Qualified for interview'), L('توقيع العرض والمباشرة', 'Offer signed & started'), L('اجتياز التجربة', 'Passed probation')].map((t, i) =>
            <div key={i}>{num(r.referral_stages[i] ?? 0, v => setR({ ...r, referral_stages: r.referral_stages.map((x, j) => j === i ? v : x) }), `${i + 1}. ${t}`, { max: 10000, suffix: L('نقطة', 'pts') })}</div>)}
          {num(r.hard_to_fill_x, v => setR({ ...r, hard_to_fill_x: v }), L('مضاعف الوظائف الحرجة', 'Hard-to-fill multiplier'), { max: 5, suffix: '×' })}
        </Panel>
        <Panel title={L('أخرى', 'Other')}>
          {num(r.cv_retention_months, v => setR({ ...r, cv_retention_months: v }), L('مدة الاحتفاظ بالسيرة قبل الأرشفة', 'Keep CVs before archiving'), { max: 120, suffix: L('شهر', 'mo') })}
          {num(r.probation_at, v => setR({ ...r, probation_at: v }), L('بنود F-19 المنجزة لبدء فترة التجربة', 'F-19 items done to start probation'), { max: 28, suffix: L('بند', 'items') })}
        </Panel>
        <Panel title={L('الترشيح الداخلي (F-27)', 'Internal applications (F-27)')}>
          {num(r.internal_min_months, v => setR({ ...r, internal_min_months: v }), L('أقل مدة خدمة للترشيح', 'Minimum service to apply'), { max: 120, suffix: L('شهر', 'mo') })}
          {num(r.internal_clean_months, v => setR({ ...r, internal_clean_months: v }), L('بلا جزاء خلال (0 = لا شرط)', 'No penalty within (0 = off)'), { max: 60, suffix: L('شهر', 'mo') })}
          {tog('internal_no_probation', L('خارج فترة التجربة', 'Past probation'), L('من هو في التجربة لا يرشّح نفسه', 'Employees on probation cannot apply'))}
        </Panel>
      </div>
    </div>
  </div>
}
