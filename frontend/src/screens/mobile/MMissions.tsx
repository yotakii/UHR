import { useRef, useState } from 'react'
import { Camera, ChevronLeft, ChevronRight, Loader2, Send } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import { navigate } from '../../lib/router'
import { STATUS, uploadMissionFile, useMission, useMissionAct, useMissions } from '../../lib/missions'
import { Badge, Btn, Empty, toast } from '../../ui/kit'

/** FR-TM-030: واجهة الهاتف للمنفّذ — مهماتي، تحديث المراحل، تعليق، ورفع صورة فاتورة أو إثبات */
export function MMissions({ id }: { id?: number }) {
  const L = useL(); const lang = useLang()
  const { data } = useMissions({ view: 'mine', status: 'open' })
  if (id) return <MMission id={id} />
  if (!data) return <div className="py-16 grid place-items-center"><Loader2 className="animate-spin text-brand" /></div>
  if (!data.data.length) return <Empty title={L('لا مهمات مفتوحة', 'No open missions')} />
  return <div className="space-y-2">{data.data.map(m => <button key={m.id} onClick={() => navigate(`/m/missions/${m.id}`)} className="w-full text-start rounded-2xl border border-line bg-surface p-4">
    <div className="flex items-start gap-2"><span className="flex-1 font-semibold">{m.title}</span><Badge tone={STATUS[m.status]?.[2] ?? 'neutral'}>{STATUS[m.status] ? L(STATUS[m.status][0], STATUS[m.status][1]) : m.status}</Badge></div>
    <div className="text-[12.5px] text-ink-3 mt-1"><span className="ltr inline-block">{m.ref_no}</span>{m.due_on ? ` · ${fmtDate(m.due_on, lang)}` : ''}{m.type ? ` · ${bi(m.type, lang)}` : ''}</div>
    <div className="mt-2 h-1.5 rounded-full bg-line overflow-hidden"><span className="block h-full bg-brand" style={{ width: `${m.progress}%` }} /></div>
  </button>)}</div>
}

function MMission({ id }: { id: number }) {
  const L = useL(); const lang = useLang()
  const { data: m } = useMission(id)
  const act = useMissionAct()
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const ref = useRef<HTMLInputElement>(null)
  if (!m) return <div className="py-16 grid place-items-center"><Loader2 className="animate-spin text-brand" /></div>
  const run = (path: string, v: Record<string, unknown>, ok: string) => act.mutate({ id, path, v }, { onSuccess: () => toast(ok), onError: e => toast((e as Error).message) })
  return <div className="space-y-3">
    <button onClick={() => navigate('/m/missions')} className="text-[13px] text-ink-3 inline-flex items-center gap-1">{lang === 'ar' ? <ChevronRight size={15} /> : <ChevronLeft size={15} />}{L('مهماتي', 'My missions')}</button>
    <div className="rounded-2xl border border-line bg-surface p-4"><div className="font-bold text-[17px]">{m.title}</div>
      <div className="text-[12.5px] text-ink-3 mt-1"><span className="ltr inline-block">{m.ref_no}</span> · {fmtDate(m.starts_on ?? '', lang)} — {m.due_on ? fmtDate(m.due_on, lang) : ''}</div>
      {m.description && <p className="text-[13.5px] mt-2 whitespace-pre-wrap">{m.description}</p>}</div>
    {m.stages && m.stages.length > 0 && <div className="rounded-2xl border border-line bg-surface p-4"><div className="font-semibold mb-2">{L('المراحل', 'Stages')} <span className="num text-ink-3">{m.progress}%</span></div>
      <div className="space-y-1">{m.stages.map((s, i) => <label key={i} className="flex items-center gap-3 h-11 text-[15px]"><input type="checkbox" className="size-5 accent-[var(--brand)]" disabled={!m.can.progress || act.isPending} checked={!!s.done_at}
        onChange={e => run('progress', { stages: m.stages!.map((x, j) => j === i ? e.target.checked : !!x.done_at) }, L('حُدّث التقدّم', 'Progress updated'))} />{lang === 'ar' ? s.name : s.en}</label>)}</div></div>}
    <div className="rounded-2xl border border-line bg-surface p-4 space-y-2"><div className="font-semibold">{L('تعليق أو صورة فاتورة', 'Comment or invoice photo')}</div>
      <textarea className="field" rows={2} value={text} onChange={e => setText(e.target.value)} placeholder={L('اكتب ملاحظة', 'Write a note')} />
      <input ref={ref} type="file" accept="image/*,application/pdf" capture="environment" className="hidden" onChange={async e => {
        const f = e.target.files?.[0]; if (!f) return; setBusy(true)
        try { const up = await uploadMissionFile(f); act.mutate({ id, path: 'comments', v: { text: text.trim() || L('صورة فاتورة / إثبات', 'Invoice / proof photo'), files: [up.id] } }, { onSuccess: () => { setText(''); toast(L('أُرفقت الصورة', 'Photo attached')) } }) }
        catch (x) { toast((x as Error).message) } finally { setBusy(false); e.target.value = '' } }} />
      <div className="flex gap-2"><Btn className="flex-1" icon={busy ? <Loader2 size={16} className="animate-spin" /> : <Camera size={16} />} onClick={() => ref.current?.click()}>{L('تصوير / إرفاق', 'Photo / attach')}</Btn>
        <Btn kind="primary" className="flex-1" icon={<Send size={16} />} disabled={!text.trim() || act.isPending} onClick={() => act.mutate({ id, path: 'comments', v: { text } }, { onSuccess: () => { setText(''); toast(L('أُرسل', 'Sent')) } })}>{L('إرسال', 'Send')}</Btn></div>
      <ul className="space-y-1.5 pt-2 text-[13px]">{[...m.comments].reverse().slice(0, 8).map(c => <li key={c.id}><b>{c.by}</b>: {c.text.replace(/@\[([^\]]+)\]\(\d+\)/g, '@$1')}{c.files.length > 0 && <span className="text-ink-3"> 📎{c.files.length}</span>}</li>)}</ul></div>
  </div>
}
