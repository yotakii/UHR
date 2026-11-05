import { useState } from 'react'
import { AlertTriangle, ArrowDown, ArrowUp, CheckCircle2, FileSpreadsheet, Loader2, Lock, Plus, Trash2 } from 'lucide-react'
import { useL, useLang, bi, fmtDate, money } from '../../../lib/store'
import { ApiError } from '../../../lib/api'
import { openVault } from '../../../lib/vault'
import { downloadSheet, useDeleteRate, useRates, useSavePaySettings, useSaveRate, useSetPay, type CheckCode, type PayrollPage, type ReportKind } from '../../../lib/payroll'
import { Badge, Btn, Empty, Field, Modal, Panel, toast } from '../../../ui/kit'

type Page = PayrollPage
type Sheet = NonNullable<Page['sheet']>

/** شريط عملة الصرف: العملة، تاريخ الصرف، السعر المستخدم لكل عملة، والأسعار الناقصة */
export function PayStrip({ d, onEdit }: { d: Page; onEdit: () => void }) {
  const L = useL(); const lang = useLang()
  const s = d.sheet!
  return <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-line px-3 py-2.5 text-[13px]">
    <span><span className="text-ink-3">{L('عملة الصرف', 'Pay currency')}: </span><b>{s.pay_currency ?? L('عملة راتب كل موظف', "Each employee's salary currency")}</b></span>
    <span><span className="text-ink-3">{L('تاريخ الصرف', 'Pay date')}: </span><b className="num">{fmtDate(s.pay_date ?? undefined, lang)}</b></span>
    {s.pay_currency && Object.entries(s.rates ?? {}).filter(([c]) => c !== s.pay_currency).map(([c, r]) => <span key={c} className={r ? '' : 'text-danger font-semibold'}>
      {r ? <><span className="ltr inline-block num"><span className="text-ink-3">1 {c} = </span><b>{r.rate.toLocaleString('en-US', { maximumFractionDigits: 6 })}</b> {s.pay_currency}</span> <span className="text-ink-3 num">({fmtDate(r.date, lang)})</span></> : L(`لا يوجد سعر ${c} → ${s.pay_currency}`, `No ${c} → ${s.pay_currency} rate`)}</span>)}
    <span className="flex-1" />
    <button className="text-brand font-semibold" onClick={onEdit}>{L('العملة وأسعار الصرف', 'Currency & rates')}</button>
  </div>
}

/** نافذة العملة: عملة الكشف وتاريخ صرفه، أسعار الصرف المؤرخة، وإعداد الشركة الافتراضي */
export function CurrencyModal({ d, onClose }: { d: Page; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const s = d.sheet as Sheet
  const q = useRates(); const r = q.data
  const setPay = useSetPay(); const saveRate = useSaveRate(); const del = useDeleteRate(); const saveCfg = useSavePaySettings()
  const editable = d.can.edit && ['draft', 'calculated'].includes(s.status)
  const [cur, setCur] = useState(s.pay_currency ?? '')
  const [date, setDate] = useState(s.pay_date ?? '')
  const used = r?.used ?? []
  const [nr, setNr] = useState({ date: s.pay_date ?? '', from: used.find(c => c !== (s.pay_currency ?? '')) ?? 'USD', to: s.pay_currency ?? 'SYP', rate: '', note: '' })
  const [cfg, setCfg] = useState<{ currency: string; pay_day: number; diff_alert_pct: number } | null>(null)
  const c = cfg ?? r?.settings ?? null
  const err = (e: unknown) => toast(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : L('تعذّر الحفظ', 'Could not save'))
  const curs = r?.currencies ?? ['USD', 'SYP']
  return <Modal open onClose={onClose} title={L('عملة الصرف وأسعار الصرف', 'Pay currency & exchange rates')} width={720}>
    <div className="space-y-5">
      <section className="space-y-3">
        <h4 className="font-semibold text-[14px]">{L('هذا الكشف', 'This sheet')}</h4>
        <p className="text-[12.5px] text-ink-3">{L('تُحوَّل مبالغ كل موظف من عملة راتبه إلى عملة الصرف بآخر سعر مسجّل في تاريخ الصرف أو قبله. يُحفظ السعر المستخدم مع الكشف، ولا يُعتمد الكشف إن نقص سعر.', "Each employee's amounts are converted from their salary currency to the pay currency at the latest rate on or before the pay date. The rate used is saved with the sheet; the sheet cannot be approved while a rate is missing.")}</p>
        <div className="grid sm:grid-cols-3 gap-3 items-end">
          <Field label={L('عملة الصرف', 'Pay currency')}><select className="field" disabled={!editable} value={cur} onChange={e => setCur(e.target.value)}>
            <option value="">{L('عملة راتب كل موظف', 'Salary currency')}</option>{curs.map(x => <option key={x} value={x}>{x}</option>)}</select></Field>
          <Field label={L('تاريخ الصرف', 'Pay date')}><input type="date" className="field" disabled={!editable} value={date} onChange={e => setDate(e.target.value)} /></Field>
          {editable && <Btn kind="primary" disabled={!date || setPay.isPending} icon={setPay.isPending ? <Loader2 size={15} className="animate-spin" /> : undefined}
            onClick={() => setPay.mutateAsync({ id: s.id, currency: cur || null, pay_date: date }).then(x => toast(x.sheet?.missing_rates.length ? L('حُفظ — ينقص سعر صرف', 'Saved — a rate is missing') : L('حُوّل الكشف', 'Sheet converted'))).catch(err)}>{L('تطبيق', 'Apply')}</Btn>}
        </div>
        {!editable && <p className="text-[12.5px] text-ink-3">{L('الكشف معتمد أو مغلق — العملة والسعر ثابتان.', 'The sheet is approved or closed — currency and rate are fixed.')}</p>}
      </section>

      <section className="space-y-2">
        <h4 className="font-semibold text-[14px]">{L('أسعار الصرف', 'Exchange rates')}</h4>
        {d.can.edit && <div className="grid grid-cols-2 sm:grid-cols-[1fr_90px_90px_1fr_auto] gap-2 items-end">
          <Field label={L('التاريخ', 'Date')}><input type="date" className="field" value={nr.date} onChange={e => setNr({ ...nr, date: e.target.value })} /></Field>
          <Field label={L('من', 'From')}><select className="field" value={nr.from} onChange={e => setNr({ ...nr, from: e.target.value })}>{curs.map(x => <option key={x}>{x}</option>)}</select></Field>
          <Field label={L('إلى', 'To')}><select className="field" value={nr.to} onChange={e => setNr({ ...nr, to: e.target.value })}>{curs.map(x => <option key={x}>{x}</option>)}</select></Field>
          <Field label={L(`1 ${nr.from} =`, `1 ${nr.from} =`)}><input className="field num ltr text-start" inputMode="decimal" value={nr.rate} onChange={e => setNr({ ...nr, rate: e.target.value })} /></Field>
          <Btn icon={<Plus size={15} />} disabled={!nr.date || !(Number(nr.rate) > 0) || nr.from === nr.to || saveRate.isPending}
            onClick={() => saveRate.mutateAsync({ date: nr.date, from: nr.from, to: nr.to, rate: Number(nr.rate), note: nr.note || undefined }).then(() => { setNr({ ...nr, rate: '' }); toast(L('حُفظ السعر — طبّقه على الكشف', 'Rate saved — apply it to the sheet')) }).catch(err)}>{L('إضافة', 'Add')}</Btn>
        </div>}
        {!r ? <Loader2 className="animate-spin text-brand" /> : r.data.length === 0 ? <p className="text-[13px] text-ink-3">{L('لا أسعار بعد.', 'No rates yet.')}</p> :
          <div className="border border-line rounded-xl max-h-[32vh] overflow-y-auto"><table className="tbl w-full text-[13px]"><thead><tr><th>{L('التاريخ', 'Date')}</th><th>{L('السعر', 'Rate')}</th><th className="hidden sm:table-cell">{L('أدخله', 'By')}</th><th /></tr></thead>
            <tbody>{r.data.map(x => <tr key={x.id}><td className="num">{fmtDate(x.date, lang)}</td><td className="num"><span className="ltr inline-block">1 {x.from} = {x.rate.toLocaleString('en-US', { maximumFractionDigits: 6 })} {x.to}</span></td>
              <td className="hidden sm:table-cell text-ink-3">{x.by ? bi(x.by, lang) : '—'}</td>
              <td className="text-end">{d.can.edit && <button className="p-1.5 rounded-lg hover:bg-sub text-ink-3" aria-label={L('حذف', 'Delete')} onClick={() => del.mutateAsync(x.id).catch(err)}><Trash2 size={15} /></button>}</td></tr>)}</tbody></table></div>}
      </section>

      {d.can.settings && c && <section className="space-y-2 border-t border-line pt-4">
        <h4 className="font-semibold text-[14px]">{L('الإعداد الافتراضي للكشوف الجديدة', 'Default for new sheets')}</h4>
        <div className="grid sm:grid-cols-4 gap-3 items-end">
          <Field label={L('عملة الصرف', 'Pay currency')}><select className="field" value={c.currency} onChange={e => setCfg({ ...c, currency: e.target.value })}>
            <option value="">{L('عملة الراتب', 'Salary currency')}</option>{curs.map(x => <option key={x}>{x}</option>)}</select></Field>
          <Field label={L('يوم الصرف (0 = آخر يوم)', 'Pay day (0 = last day)')}><input type="number" min={0} max={31} className="field num" value={c.pay_day} onChange={e => setCfg({ ...c, pay_day: Number(e.target.value) })} /></Field>
          <Field label={L('تنبيه الفرق عن الشهر السابق %', 'Change alert %')}><input type="number" min={1} max={100} className="field num" value={c.diff_alert_pct} onChange={e => setCfg({ ...c, diff_alert_pct: Number(e.target.value) })} /></Field>
          <div className="flex gap-2"><Btn kind="primary" disabled={!cfg || saveCfg.isPending} onClick={() => cfg && saveCfg.mutateAsync(cfg).then(() => { setCfg(null); toast(L('حُفظ الإعداد', 'Saved')) }).catch(err)}>{L('حفظ', 'Save')}</Btn>
            {r && <Btn onClick={() => setCfg(r.defaults)}>{L('الافتراضي', 'Defaults')}</Btn>}</div>
        </div>
      </section>}
    </div>
  </Modal>
}

const KINDS: { k: ReportKind; ar: string; en: string }[] = [
  { k: 'bank', ar: 'كشف البنك', en: 'Bank sheet' }, { k: 'insurance', ar: 'كشف التأمينات', en: 'Insurance sheet' },
  { k: 'departments', ar: 'ملخص الإدارات', en: 'By department' }, { k: 'deductions', ar: 'الاقتطاعات', en: 'Deductions' }, { k: 'diff', ar: 'الفروقات', en: 'Changes' },
]

/** قوالب التقارير الجاهزة (ملاحظة 75) — تحتاج التحقق وإذن الاطلاع على كل الرواتب */
export function Reports({ d }: { d: Page }) {
  const L = useL()
  const s = d.sheet!
  const [busy, setBusy] = useState<ReportKind | null>(null)
  if (!d.can.export) return null
  const go = (k: ReportKind) => { if (d.masked) return openVault('others'); setBusy(k); downloadSheet(s.id, s.month, k).catch(() => toast(L('تعذّر التنزيل', 'Download failed'))).finally(() => setBusy(null)) }
  return <Panel title={L('قوالب التقارير', 'Report templates')} sub={L('ملفات Excel جاهزة للبنك والتأمينات والإدارة المالية — بعملة الصرف إن حُدّدت.', 'Ready Excel files for the bank, insurance and finance — in the pay currency when set.')}>
    <div className="flex flex-wrap gap-2">{KINDS.map(x => <Btn key={x.k} size="sm" icon={busy === x.k ? <Loader2 size={14} className="animate-spin" /> : d.masked ? <Lock size={14} /> : <FileSpreadsheet size={14} />} onClick={() => go(x.k)}>{L(x.ar, x.en)}</Btn>)}</div>
  </Panel>
}

const COMP: Record<string, [string, string]> = { base: ['الراتب', 'Salary'], ot_pay: ['الإضافي', 'Overtime'], bonus: ['المكافأة', 'Reward'], late: ['حسم التأخير', 'Lateness'], absent: ['حسم الغياب', 'Absence'],
  leave: ['بلا أجر', 'Unpaid leave'], insurance: ['التأمينات', 'Insurance'], advance: ['السلفة', 'Advance'], discipline: ['المخالفات', 'Discipline'] }

/** الفروقات عن الكشف السابق: التنبيهات أولاً (فرق ≥ النسبة، جديد، غير موجود) */
export function DiffView({ d }: { d: Page }) {
  const L = useL(); const lang = useLang()
  const [all, setAll] = useState(false)
  const df = d.diff
  if (!df?.prev) return <Panel><Empty title={L('لا يوجد كشف سابق للمقارنة', 'No previous sheet to compare')} /></Panel>
  const rows = df.rows.filter(r => all || r.alert).sort((a, b) => Number(b.alert) - Number(a.alert) || Math.abs(b.delta ?? 0) - Math.abs(a.delta ?? 0))
  const st = { new: L('جديد', 'New'), left: L('غير موجود هذا الشهر', 'Not in this sheet'), currency: L('تغيّرت العملة', 'Currency changed'), same: '' }
  return <Panel pad={false} title={L(`مقارنة مع كشف ${df.prev}`, `Compared with ${df.prev}`)} sub={L(`تنبيه عند فرق ±${df.alert_pct}% أو أكثر في الصافي.`, `Flagged at a ±${df.alert_pct}% change in net or more.`)}
    action={<label className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={all} onChange={e => setAll(e.target.checked)} className="size-4 accent-[var(--primary)]" />{L('كل الموظفين', 'All employees')}</label>}>
    {rows.length === 0 ? <Empty title={L('لا فروقات تستحق المراجعة', 'No changes need review')} /> :
      <table className="tbl"><thead><tr><th>{L('الموظف', 'Employee')}</th><th className="hidden sm:table-cell">{L('السابق', 'Previous')}</th><th>{L('الحالي', 'Current')}</th><th>{L('الفرق', 'Change')}</th><th className="hidden md:table-cell">{L('البنود المتغيرة', 'What changed')}</th></tr></thead>
        <tbody>{rows.map(r => { const cur = r.currency ?? 'USD'
          return <tr key={r.employee_id}>
            <td><div className="font-medium">{r.employee ? bi(r.employee.name, lang) : '—'}</div><div className="text-[12px] text-ink-3"><span className="ltr inline-block">{r.employee?.code}</span></div>
              {r.status !== 'same' && <Badge tone={r.status === 'left' ? 'danger' : 'info'}>{st[r.status]}</Badge>}</td>
            <td className="num hidden sm:table-cell">{r.prev == null ? '—' : money(r.prev, cur, lang)}</td>
            <td className="num">{r.net == null ? (d.masked ? <Lock size={14} className="text-ink-3" /> : '—') : money(r.net, cur, lang)}</td>
            <td className="num">{r.delta == null ? '—' : <span className={`inline-flex items-center gap-1 font-semibold ${r.delta > 0 ? 'text-brand' : r.delta < 0 ? 'text-danger' : ''}`}>
              {r.delta > 0 ? <ArrowUp size={13} /> : r.delta < 0 ? <ArrowDown size={13} /> : null}{money(Math.abs(r.delta), cur, lang)}{r.pct != null && <span className="text-[12px] font-normal text-ink-3"> ({r.pct > 0 ? '+' : ''}{r.pct}%)</span>}</span>}</td>
            <td className="hidden md:table-cell"><div className="flex flex-wrap gap-1">{Object.entries(r.changes).map(([k, v]) => <span key={k} className="text-[12px] rounded-md bg-sub px-1.5 py-0.5">{COMP[k] ? L(...COMP[k]) : k} <span className={`num ${v > 0 ? 'text-brand' : 'text-danger'}`}>{v > 0 ? '+' : '−'}{money(Math.abs(v), cur, lang)}</span></span>)}</div></td>
          </tr> })}</tbody></table>}
  </Panel>
}

/** التحقق الآلي قبل الاعتماد (ملاحظة 72): مجمّع حسب نوع الملاحظة */
export function ChecksView({ d }: { d: Page }) {
  const L = useL(); const lang = useLang()
  const label: Record<CheckCode, [string, string, string, string]> = {
    rate_missing: ['لا يوجد سعر صرف بتاريخ الصرف', 'No exchange rate on the pay date', 'أضف السعر من «العملة وأسعار الصرف» ثم طبّقه.', 'Add the rate under "Currency & rates" and apply it.'],
    net_zero: ['الصافي صفر أو سالب', 'Net is zero or negative', 'راجع الحسومات والسلفة.', 'Check deductions and advances.'],
    incomplete: ['أيام ناقصة بلا خروج', 'Incomplete days', 'صحّحها من سجل الحضور ثم أعد الاحتساب.', 'Correct them in attendance and recalculate.'],
    no_bank: ['لا يوجد رقم حساب بنكي', 'No bank account', 'أضفه من ملف الموظف ← تعديل البيانات.', 'Add it in the employee file → Edit details.'],
    big_change: ['فرق كبير عن الشهر السابق', 'Large change from last month', 'راجع تبويب «الفروقات».', 'See the "Changes" tab.'],
    new: ['موظف جديد في الكشف', 'New in this sheet', 'تأكد من تاريخ المباشرة والراتب.', 'Check the start date and salary.'],
    left: ['موظف لم يعد في الكشف', 'No longer in the sheet', 'تأكد من إنهاء خدمته.', 'Confirm the exit.'],
  }
  const groups = Object.entries(d.checks.reduce<Record<string, typeof d.checks>>((a, c) => { (a[c.code] ??= []).push(c); return a }, {})) as [CheckCode, typeof d.checks][]
  if (!groups.length) return <Panel><Empty title={<span className="inline-flex items-center gap-2 text-brand"><CheckCircle2 size={18} />{L('لا ملاحظات — الكشف جاهز للمراجعة', 'No issues — ready for review')}</span>} /></Panel>
  return <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(300px,1fr))]">
    {groups.map(([code, list]) => <Panel key={code} title={<span className={`flex items-center gap-2 ${['rate_missing', 'net_zero', 'incomplete'].includes(code) ? 'text-danger' : ''}`}><AlertTriangle size={15} />{L(label[code][0], label[code][1])} <span className="num text-ink-3 font-normal">({list.length})</span></span>}>
      <p className="text-[12.5px] text-ink-3 mb-2">{L(label[code][2], label[code][3])}</p>
      <ul className="space-y-1 text-[13px] max-h-48 overflow-y-auto">{list.map((c, i) => <li key={i} className="flex justify-between gap-2">
        {c.employee ? <a className="hover:text-brand" href={`/hr/employees/${c.employee.id}`}>{bi(c.employee.name, lang)} <span className="text-ink-3 ltr inline-block">{c.employee.code}</span></a> : <span>{String(c.value ?? '')}</span>}
        {c.employee && c.value != null && <span className="num text-ink-3">{code === 'big_change' ? `${c.value}%` : c.value}</span>}</li>)}</ul>
    </Panel>)}
  </div>
}
