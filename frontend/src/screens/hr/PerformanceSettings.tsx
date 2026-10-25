import { useEffect, useState } from 'react'
import { Plus, RotateCcw, Trash2, Eye, ArrowUp } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { previewPerf, usePerfSettings, useSavePerfSettings, type Band, type CompetencyRow, type PerfConfig } from '../../lib/performance'
import { Badge, Btn, Empty, Modal, PageHead, Panel, toast } from '../../ui/kit'

/**
 * إعدادات الأداء (M16 ← M13، D-28): أوزان المقيّمين، السلّم، عدد الزملاء، حدود مستوى الأداء على 9-Box،
 * الكفاءات، وجدول مكافآت التقييم — مع معاينة أثرها على الدورة المفتوحة قبل الحفظ.
 */
export function PerformanceSettings() {
  const L = useL(); const lang = useLang()
  const q = usePerfSettings(); const save = useSavePerfSettings()
  const [cfg, setCfg] = useState<PerfConfig | null>(null); const [comps, setComps] = useState<CompetencyRow[]>([])
  const [prev, setPrev] = useState<Awaited<ReturnType<typeof previewPerf>>['data'] | null>(null)
  useEffect(() => { if (q.data && !cfg) { setCfg(q.data.config); setComps(q.data.competencies) } }, [q.data, cfg])
  const err = save.error instanceof ApiError ? save.error : null
  if (!cfg || !q.data) return <div className="max-w-[1100px] mx-auto"><Panel><Empty title={q.isError ? L('تعذّر التحميل', 'Could not load') : L('جارٍ التحميل…', 'Loading…')} /></Panel></div>
  const sum = cfg.weights.manager + cfg.weights.peers + cfg.weights.self
  const num = (v: number, on: (n: number) => void, step = 1) => <input className="field num !w-24" type="number" step={step} value={v} onChange={e => on(Number(e.target.value))} />
  const setBand = (i: number, p: Partial<Band>) => setCfg({ ...cfg, rewards: cfg.rewards.map((b, j) => j === i ? { ...b, ...p } : b) })
  const fieldErr = (k: string) => err?.errors[k]?.[0]
  const doSave = () => save.mutate({ config: cfg, competencies: comps }, { onSuccess: r => { toast(L(`حُفظت الإعدادات وأُعيد احتساب ${r.recomputed} تقييم مفتوح`, `Saved — ${r.recomputed} open review(s) recalculated`)); q.refetch().then(x => x.data && (setCfg(x.data.config), setComps(x.data.competencies))) } })
  return <div className="max-w-[1100px] mx-auto space-y-4">
    <PageHead title={L('إعدادات الأداء والتقييم', 'Performance settings')} sub={L('تنطبق على الدورات المفتوحة فوراً بعد الحفظ؛ الدورات المعتمدة لا تتغير.', 'Applies to open cycles once saved; approved cycles do not change.')}
      actions={<div className="flex flex-wrap gap-2"><Btn icon={<RotateCcw size={15} />} onClick={() => setCfg(q.data!.defaults)}>{L('القيم الافتراضية', 'Defaults')}</Btn>
        <Btn icon={<Eye size={15} />} onClick={() => previewPerf({ config: cfg }).then(r => setPrev(r.data)).catch(e => toast(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : L('تعذّرت المعاينة', 'Preview failed')))}>{L('معاينة', 'Preview')}</Btn>
        <Btn kind="primary" disabled={save.isPending} onClick={doSave}>{L('حفظ', 'Save')}</Btn></div>} />
    {err && <p role="alert" className="text-[13px] text-danger">{Object.values(err.errors)[0]?.[0] ?? err.message}</p>}
    <div className="grid lg:grid-cols-2 gap-4 items-start">
      <Panel title={L('أوزان المقيّمين', 'Rater weights')} sub={L('النتيجة = متوسط الكفاءات لمجموع (الدرجة × الوزن) — الأدوار الغائبة تُستبعد ويُعاد التوزيع.', 'Score = average over competencies of Σ(score × weight) — missing roles are left out and re-weighted.')}>
        <div className="space-y-2">{([['manager', L('المدير المباشر', 'Direct manager')], ['peers', L('الزملاء', 'Peers')], ['self', L('التقييم الذاتي', 'Self')]] as const).map(([k, t]) =>
          <div key={k} className="flex items-center justify-between gap-3 text-[14px]"><span>{t}</span><span className="flex items-center gap-1">{num(cfg.weights[k], n => setCfg({ ...cfg, weights: { ...cfg.weights, [k]: n } }))}<span className="text-ink-3">%</span></span></div>)}
          <div className={`text-[12.5px] ${sum === 100 ? 'text-ink-3' : 'text-danger'}`}>{L('المجموع', 'Total')}: <span className="num">{sum}</span>%</div></div>
      </Panel>
      <Panel title={L('السلّم وحدود 9-Box', 'Scale and 9-Box thresholds')}>
        <div className="space-y-2 text-[14px]">
          <div className="flex items-center justify-between gap-3"><span>{L('أعلى درجة في النموذج', 'Top score on a form')}</span>{num(cfg.scale, n => setCfg({ ...cfg, scale: n }))}</div>
          <div className="flex items-center justify-between gap-3"><span>{L('عدد الزملاء المقيّمين لكل موظف', 'Peer raters per employee')}</span>{num(cfg.peers, n => setCfg({ ...cfg, peers: n }))}</div>
          <div className="flex items-center justify-between gap-3"><span>{L('أداء «مرتفع» من نتيجة', '«High» performance from')}</span>{num(cfg.high_from, n => setCfg({ ...cfg, high_from: n }), 0.1)}</div>
          <div className="flex items-center justify-between gap-3"><span>{L('أداء «متوسط» من نتيجة', '«Mid» performance from')}</span>{num(cfg.mid_from, n => setCfg({ ...cfg, mid_from: n }), 0.1)}</div>
          {fieldErr('config.high_from') && <p className="text-[12px] text-danger">{fieldErr('config.high_from')}</p>}
          <p className="text-[12px] text-ink-3">{L('الإمكانات يحددها المدير المباشر في نموذجه (منخفضة/متوسطة/مرتفعة)، والمعايرة اليدوية بالسحب تبقى فوق الحساب.', 'Potential comes from the manager’s form (low/mid/high); manual calibration stays above the calculation.')}</p>
        </div>
      </Panel>
    </div>
    <Panel title={L('الكفاءات', 'Competencies')} sub={L('ثلاث كفاءات فعّالة على الأقل. الإيقاف يخفي الكفاءة من النماذج الجديدة دون حذف ما سبق.', 'At least three active. Deactivating hides it from new forms without deleting history.')} pad={false}>
      <ul className="divide-y divide-line-2">{comps.map((c, i) => <li key={c.id ?? `n${i}`} className="p-3 grid grid-cols-[1fr_auto] gap-2 items-center">
        <div className="grid sm:grid-cols-2 gap-2"><input className="field" placeholder={L('بالعربية', 'Arabic')} value={c.name_ar} onChange={e => setComps(comps.map((x, j) => j === i ? { ...x, name_ar: e.target.value } : x))} />
          <input className="field ltr" placeholder="English" value={c.name_en} onChange={e => setComps(comps.map((x, j) => j === i ? { ...x, name_en: e.target.value } : x))} /></div>
        <div className="flex items-center gap-1">
          {i > 0 && <button className="p-2 rounded-lg hover:bg-sub text-ink-3" aria-label={L('لأعلى', 'Up')} onClick={() => { const n = [...comps]; [n[i - 1], n[i]] = [n[i], n[i - 1]]; setComps(n) }}><ArrowUp size={15} /></button>}
          <button className={`h-8 px-2.5 rounded-lg border text-[12px] font-semibold ${c.active ? 'border-brand text-brand bg-brand-soft' : 'border-line text-ink-3'}`} onClick={() => setComps(comps.map((x, j) => j === i ? { ...x, active: !x.active } : x))}>{c.active ? L('فعّالة', 'Active') : L('موقوفة', 'Off')}</button>
          {!c.id && <button className="p-2 rounded-lg hover:bg-sub text-ink-3" aria-label={L('حذف', 'Remove')} onClick={() => setComps(comps.filter((_, j) => j !== i))}><Trash2 size={15} /></button>}
        </div></li>)}</ul>
      <div className="p-3 border-t border-line-2">{comps.length < 15 && <Btn size="sm" icon={<Plus size={14} />} onClick={() => setComps([...comps, { name_ar: '', name_en: '', active: true }])}>{L('كفاءة', 'Competency')}</Btn>}</div>
    </Panel>
    <Panel title={L('جدول مكافآت التقييم (D-28)', 'Review rewards table (D-28)')} sub={L('بند لكل مدى نتيجة: نسبة من الراتب أو مبلغ ثابت يُصرف مع الكشف الشهري، أو نقاط في رصيد المكافآت. النتيجة خارج البنود تُحوَّل للمراجعة.', 'One band per score range: % of salary or a fixed amount paid with the monthly sheet, or points. Scores outside the bands go to review.')} pad={false}>
      {cfg.rewards.length === 0 ? <div className="p-4 text-[13px] text-ink-3">{L('لا بنود — كل نتيجة معتمدة ستُحوَّل للمراجعة اليدوية.', 'No bands — every approved score goes to manual review.')}</div>
        : <ul className="divide-y divide-line-2">{cfg.rewards.map((b, i) => <li key={i} className="p-3 flex flex-wrap items-center gap-2 text-[13px]">
          <span>{L('من', 'From')}</span>{num(b.from, n => setBand(i, { from: n }), 0.1)}<span>{L('إلى', 'to')}</span>{num(b.to, n => setBand(i, { to: n }), 0.1)}
          <select className="field !w-auto" value={b.kind} onChange={e => setBand(i, { kind: e.target.value as Band['kind'] })}><option value="percent">{L('نسبة من الراتب', '% of salary')}</option><option value="amount">{L('مبلغ ثابت', 'Fixed amount')}</option><option value="points">{L('نقاط', 'Points')}</option></select>
          {num(b.value, n => setBand(i, { value: n }), b.kind === 'percent' ? 0.5 : 1)}<span className="text-ink-3">{b.kind === 'percent' ? '%' : b.kind === 'points' ? L('نقطة', 'pts') : ''}</span>
          <span className="flex-1" /><button className="p-2 rounded-lg hover:bg-sub text-ink-3" aria-label={L('حذف', 'Remove')} onClick={() => setCfg({ ...cfg, rewards: cfg.rewards.filter((_, j) => j !== i) })}><Trash2 size={15} /></button>
          {fieldErr(`config.rewards.${i}`) && <p className="basis-full text-[12px] text-danger">{fieldErr(`config.rewards.${i}`)}</p>}</li>)}</ul>}
      <div className="p-3 border-t border-line-2">{cfg.rewards.length < 10 && <Btn size="sm" icon={<Plus size={14} />} onClick={() => { const last = cfg.rewards[cfg.rewards.length - 1]; const from = last ? Math.min(cfg.scale, +(last.to + 0.1).toFixed(1)) : cfg.mid_from
        setCfg({ ...cfg, rewards: [...cfg.rewards, { from, to: cfg.scale, kind: 'percent', value: 5 }] }) }}>{L('بند', 'Band')}</Btn>}</div>
    </Panel>
    {prev && <Modal open onClose={() => setPrev(null)} width={640} title={L('معاينة أثر الإعدادات على الدورة المفتوحة', 'Preview on the open cycle')} footer={<><Btn onClick={() => setPrev(null)}>{L('إغلاق', 'Close')}</Btn><Btn kind="primary" onClick={() => { setPrev(null); doSave() }}>{L('حفظ', 'Save')}</Btn></>}>
      {prev.length === 0 ? <Empty title={L('لا تقييمات مفتوحة بنتيجة', 'No scored open reviews')} /> : <table className="tbl"><thead><tr><th>{L('الموظف', 'Employee')}</th><th>{L('الحالي', 'Current')}</th><th>{L('الجديد', 'New')}</th></tr></thead>
        <tbody>{prev.map(r => <tr key={r.id}><td>{bi(r.name, lang)}{r.calibrated && <span className="ms-1"><Badge>{L('معايرة', 'Calibrated')}</Badge></span>}</td><td className="num">{r.current.score?.toFixed(1) ?? '—'}</td>
          <td className={`num font-semibold ${(r.new.score ?? 0) > (r.current.score ?? 0) ? 'text-brand' : (r.new.score ?? 0) < (r.current.score ?? 0) ? 'text-danger' : ''}`}>{r.new.score?.toFixed(1) ?? '—'}</td></tr>)}</tbody></table>}
    </Modal>}
  </div>
}

