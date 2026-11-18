import { useState } from 'react'
import { Check, X } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import { useDecideHours, useVolunteerHours } from '../../lib/externals'
import { Btn, Panel, toast } from '../../ui/kit'

/** المشرف يصادق ساعات المتطوعين تحت إشرافه (POL-VL-06) — من «اعتماداتي» في أي بوابة */
export function VolunteerHours() {
  const L = useL(); const lang = useLang()
  const { data } = useVolunteerHours(); const m = useDecideHours()
  const [sel, setSel] = useState<number[]>([])
  if (!data?.data.length) return null
  const all = data.data.map(r => r.id)
  const go = (approve: boolean) => m.mutate({ ids: sel.length ? sel : all, approve, note: approve ? undefined : L('لم تُؤكَّد', 'Not confirmed') }, { onSuccess: r => { toast(L(`حُفظ القرار لـ ${r.count} سجل`, `Saved for ${r.count} entries`)); setSel([]) } })
  return <Panel title={L('ساعات المتطوعين بانتظار مصادقتك', 'Volunteer hours awaiting your approval')} pad={false}
    action={<div className="flex gap-1"><Btn size="sm" kind="ghost" icon={<X size={13} />} disabled={m.isPending} onClick={() => go(false)}>{L('رفض', 'Reject')}{sel.length ? ` (${sel.length})` : ''}</Btn>
      <Btn size="sm" kind="primary" icon={<Check size={13} />} disabled={m.isPending} onClick={() => go(true)}>{sel.length ? L(`مصادقة ${sel.length}`, `Approve ${sel.length}`) : L('مصادقة الكل', 'Approve all')}</Btn></div>}>
    <table className="tbl"><tbody>{data.data.map(r => <tr key={r.id}>
      <td className="w-8"><input type="checkbox" checked={sel.includes(r.id)} onChange={e => setSel(e.target.checked ? [...sel, r.id] : sel.filter(x => x !== r.id))} aria-label={L('تحديد', 'Select')} /></td>
      <td><div className="font-medium">{bi(r.external, lang)}</div><div className="text-[12px] text-ink-3 num">{fmtDate(r.date, lang)} · <span className="ltr inline-block">{r.ref}</span></div></td>
      <td className="text-[13px]">{r.activity}</td><td className="num font-semibold">{r.hours} {L('س', 'h')}</td></tr>)}</tbody></table>
  </Panel>
}
