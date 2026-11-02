import { useState } from 'react'
import { ChevronLeft, ChevronRight, Loader2, Search } from 'lucide-react'
import { useL, useLang, bi, fmtDateTime } from '../lib/store'
import { useMailLog } from '../lib/admin'
import { Badge, Empty, Seg } from './kit'

/** سجل البريد الصادر (11e): لمن أُرسلت كل رسالة، موضوعها، وهل وصلت إلى خادم البريد أو فشلت ولماذا */
export function MailLogList({ base, showCompany }: { base: '/hr/admin/mail-log' | '/platform/mail/log'; showCompany?: boolean }) {
  const L = useL(); const lang = useLang()
  const [status, setStatus] = useState(''); const [q, setQ] = useState(''); const [term, setTerm] = useState(''); const [page, setPage] = useState(1)
  const r = useMailLog(base, { status, q: term, page: page > 1 ? String(page) : undefined }); const d = r.data
  const Prev = lang === 'ar' ? ChevronRight : ChevronLeft, Next = lang === 'ar' ? ChevronLeft : ChevronRight
  const tone = { sent: 'brand', failed: 'danger', sending: 'brass' } as const
  const label = { sent: L('أُرسلت', 'Sent'), failed: L('فشلت', 'Failed'), sending: L('قيد الإرسال', 'Sending') }
  return <div className="space-y-3">
    <div className="flex flex-wrap items-center gap-2">
      <form className="relative flex-1 min-w-[200px] max-w-sm" onSubmit={e => { e.preventDefault(); setTerm(q); setPage(1) }}>
        <Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" /><input className="field !ps-9" value={q} onChange={e => setQ(e.target.value)} placeholder={L('البريد أو الموضوع…', 'Email or subject…')} /></form>
      <Seg value={status} onChange={v => { setStatus(v); setPage(1) }} options={[{ v: '', label: L('الكل', 'All') }, { v: 'sent', label: label.sent }, { v: 'failed', label: label.failed }]} />
    </div>
    {!d ? <div className="grid place-items-center py-10"><Loader2 className="animate-spin text-brand" /></div> : !d.data.length ? <Empty title={L('لا توجد رسائل', 'No messages')} /> :
      <ul className="divide-y divide-line-2 rounded-xl border border-line">{d.data.map(m => <li key={m.id} className="px-4 py-2.5 flex flex-wrap items-start gap-x-4 gap-y-1">
        <span className="flex-1 min-w-[200px]"><span className="block text-[13.5px] font-semibold break-words">{m.subject ?? '—'}</span>
          <span className="block text-[12.5px] text-ink-3 ltr text-start break-all">{m.to}</span>
          {showCompany && m.company && <span className="block text-[12px] text-ink-3">{bi(m.company, lang)}</span>}
          {m.status === 'failed' && m.error && <span className="block text-[12px] text-danger mt-1 ltr text-start break-words">{m.error}</span>}</span>
        <span className="text-[12px] text-ink-3 num whitespace-nowrap">{fmtDateTime(m.at, lang)}</span>
        <Badge tone={tone[m.status]}>{label[m.status]}</Badge></li>)}</ul>}
    {d && d.meta.pages > 1 && <div className="flex items-center justify-center gap-3 text-[13px]">
      <button className="size-8 grid place-items-center rounded-lg border border-line disabled:opacity-40" disabled={page <= 1} onClick={() => setPage(p => p - 1)} aria-label={L('السابق', 'Previous')}><Prev size={15} /></button>
      <span className="num">{page} / {d.meta.pages}</span>
      <button className="size-8 grid place-items-center rounded-lg border border-line disabled:opacity-40" disabled={page >= d.meta.pages} onClick={() => setPage(p => p + 1)} aria-label={L('التالي', 'Next')}><Next size={15} /></button></div>}
  </div>
}
