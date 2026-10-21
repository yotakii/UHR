import { useState } from 'react'
import { Paperclip } from 'lucide-react'
import { useL, useLang, fmtDate, fmtDateTime } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { minsLabel } from '../../lib/employees'
import { useJustify, useMyAttendance, type Issue } from '../../lib/requests'
import { Badge, Btn, Empty, Field, Modal, PageHead, Panel, toast } from '../../ui/kit'
import { AttendanceTable } from '../hr/employees/EmployeeFile'
import { LeaveStatus } from './parts'

/** «حضوري» (MyAttendance في الديمو): آخر أسبوعين، والتأخير/الغياب/اليوم الناقص بحاجة لتبرير خلال المهلة (D-18) */
export function MyAttendance() {
  const L = useL(); const lang = useLang()
  const q = useMyAttendance(); const d = q.data
  const justify = useJustify()
  const [open, setOpen] = useState<Issue | null>(null)
  const [reason, setReason] = useState(''); const [file, setFile] = useState<File | null>(null); const [err, setErr] = useState('')
  const what = (i: Issue) => i.status === 'absent' ? L('غياب', 'Absence') : i.status === 'incomplete' ? L('يوم ناقص — بلا خروج', 'Incomplete — no out') : <>{L('تأخير', 'Late')} {minsLabel(i.late, lang)}</>
  const send = () => {
    if (reason.trim().length < 5) { setErr(L('اكتب سبباً واضحاً (5 أحرف على الأقل).', 'Write a clear reason (at least 5 characters).')); return }
    justify.mutateAsync({ date: open!.date, reason: reason.trim(), file }).then(() => { toast(L('أُرسل التبرير لمديرك المباشر', 'Sent to your manager')); setOpen(null) })
      .catch(e => setErr(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : L('تعذّر الإرسال', 'Could not send')))
  }
  return <div className="max-w-[1100px] mx-auto">
    <PageHead title={L('حضوري', 'My attendance')} sub={d ? L(`يُقدَّم تبرير التأخير أو الغياب خلال ${d.justify_hours} ساعة من نهاية اليوم.`, `Justify lateness or absence within ${d.justify_hours} hours after the day.`) : undefined} />
    <Panel title={L('بحاجة لتبرير', 'To justify')} pad={false} className="mb-4">
      {!d ? <Empty title={L('جارٍ التحميل…', 'Loading…')} /> : d.issues.length === 0 ? <Empty title={L('لا توجد حالات بحاجة لتبرير', 'Nothing to justify')} /> :
        <ul className="divide-y divide-line-2">{d.issues.map(i => <li key={i.date} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5">
          <span className="text-[14px] flex-1 min-w-[200px]">{fmtDate(i.date, lang)} — <span className="text-warn num">{what(i)}</span>
            {i.open && <span className="block text-[12px] text-ink-3">{L('المهلة حتى', 'Window until')} <span className="num">{fmtDateTime(i.deadline, lang)}</span></span>}</span>
          {i.justification ? <LeaveStatus s={i.justification.status} /> : i.open ? <Btn size="sm" onClick={() => { setOpen(i); setReason(''); setFile(null); setErr('') }}>{L('تقديم تبرير', 'Justify')}</Btn>
            : i.status === 'justified' ? <Badge tone="brand">{L('مبرر', 'Justified')}</Badge> : <Badge>{L('انتهت المهلة', 'Window closed')}</Badge>}
        </li>)}</ul>}
    </Panel>
    <Panel title={L('آخر أسبوعين', 'Last two weeks')} pad={false}>{d ? <AttendanceTable rows={d.days} /> : <Empty title="…" />}</Panel>
    <Modal open={!!open} onClose={() => setOpen(null)} title={L('تبرير', 'Justification')}
      footer={<><Btn onClick={() => setOpen(null)}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={justify.isPending} onClick={send}>{L('إرسال', 'Send')}</Btn></>}>
      {open && <p className="text-[14px] mb-3">{fmtDate(open.date, lang)} — {what(open)}</p>}
      <div className="space-y-3">
        <Field label={L('السبب', 'Reason')} req error={err}><textarea className="field" value={reason} maxLength={1000} autoFocus onChange={x => { setReason(x.target.value); setErr('') }} /></Field>
        <Field div label={L('مستند داعم (اختياري)', 'Supporting document (optional)')}>
          <label className="flex items-center gap-2 h-10 px-3 rounded-lg border border-dashed border-line cursor-pointer text-[13px] text-ink-2 hover:border-brand/50">
            <Paperclip size={15} /><span className="truncate">{file ? file.name : L('اختر ملفاً (PDF أو صورة)', 'Choose a file (PDF or image)')}</span>
            <input type="file" className="hidden" accept=".pdf,.jpg,.jpeg,.png" onChange={x => setFile(x.target.files?.[0] ?? null)} /></label></Field>
      </div>
    </Modal>
  </div>
}
