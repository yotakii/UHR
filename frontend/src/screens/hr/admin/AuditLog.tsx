import { useState } from 'react'
import { Archive, ChevronLeft, ChevronRight, Download, FileArchive, Loader2, Search, Settings2, X } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime } from '../../../lib/store'
import { setQuery, useRoute } from '../../../lib/router'
import { ApiError } from '../../../lib/api'
import { download, fmtSize, useAudit, useAuditArchives, useSaveAuditSettings, type AuditFilter, type AuditRow } from '../../../lib/admin'
import { Badge, Btn, Drawer, Empty, Field, Modal, PageHead, Panel, toast } from '../../../ui/kit'

/**
 * M16 «سجل التدقيق»: من، ماذا، متى، القيمة قبل/بعد، من أي عنوان. بحث وتصفية بالمستخدم والعملية والتاريخ، وتصدير Excel.
 * الواجهة تعرض آخر N يوماً (إعداد)، والأقدم في الأرشيف الدائم لمن يملك صلاحية الأرشفة — لا حذف.
 */
export function AuditLog() {
  const L = useL(); const lang = useLang()
  const route = useRoute()
  const f: AuditFilter = { q: route.query.q, user: route.query.user, group: route.query.group, from: route.query.from, to: route.query.to, archive: route.query.archive, page: route.query.page }
  const q = useAudit(f)
  const [qText, setQText] = useState(f.q ?? '')
  const [open, setOpen] = useState<AuditRow | null>(null)
  const [settings, setSettings] = useState(false)
  const [archives, setArchives] = useState(false)
  const [busy, setBusy] = useState(false)
  const d = q.data
  if (!d) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const set = (x: Partial<AuditFilter>) => setQuery({ ...x, page: undefined })
  const pages = Math.max(1, Math.ceil(d.total / d.per))
  const Prev = lang === 'ar' ? ChevronRight : ChevronLeft, Next = lang === 'ar' ? ChevronLeft : ChevronRight
  const exportXlsx = async () => {
    setBusy(true)
    try { await download(`/hr/admin/audit/export?${new URLSearchParams(Object.entries(f).filter(([k, v]) => v && k !== 'page') as [string, string][])}`, `UHR-audit-${new Date().toISOString().slice(0, 10)}.xlsx`) }
    catch (e) { toast(e instanceof ApiError ? e.message : L('تعذّر التصدير', 'Export failed')) } finally { setBusy(false) }
  }
  return <div className="max-w-[1300px] mx-auto space-y-5">
    <PageHead title={L('سجل التدقيق', 'Audit log')} sub={f.archive ? L('الأرشيف الكامل — كل العمليات منذ بدء التشغيل.', 'The full archive — every operation since go-live.')
      : L(`كل عملية كتابة ودخول وتنزيل في آخر ${d.settings.visible_days} يوماً؛ الأقدم في الأرشيف ولا يُحذف شيء.`, `Every write, sign-in and download in the last ${d.settings.visible_days} days; older entries are archived and nothing is deleted.`)}
      actions={<>
        {d.can.archive && <Btn icon={<FileArchive size={15} />} onClick={() => setArchives(true)}>{L('ملفات الأرشيف', 'Archive files')}</Btn>}
        {d.can.settings && <Btn icon={<Settings2 size={15} />} onClick={() => setSettings(true)}>{L('الإعداد', 'Setting')}</Btn>}
        {d.can.export && <Btn kind="primary" icon={busy ? <Loader2 size={15} className="animate-spin" /> : <Download size={15} />} disabled={busy} onClick={exportXlsx}>{L('تصدير Excel', 'Export to Excel')}</Btn>}
      </>} />
    <Panel>
      <div className="grid gap-3 grid-cols-1 sm:[grid-template-columns:repeat(auto-fill,minmax(180px,1fr))]">
        <div className="relative sm:[grid-column:span_2]"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" />
          <input className="field !ps-9" placeholder={L('ابحث: اسم، بريد، عملية، قيمة، عنوان IP', 'Search: name, email, action, value, IP')} value={qText} onChange={e => setQText(e.target.value)} onKeyDown={e => e.key === 'Enter' && set({ q: qText || undefined })} onBlur={() => qText !== (f.q ?? '') && set({ q: qText || undefined })} /></div>
        <select className="field" value={f.user ?? ''} onChange={e => set({ user: e.target.value || undefined })} aria-label={L('المستخدم', 'User')}><option value="">{L('كل المستخدمين', 'All users')}</option>{d.users.map(u => <option key={u.id} value={u.id}>{bi(u, lang)}</option>)}</select>
        <select className="field" value={f.group ?? ''} onChange={e => set({ group: e.target.value || undefined })} aria-label={L('العملية', 'Operation')}><option value="">{L('كل العمليات', 'All operations')}</option>{d.groups.map(g => <option key={g.key} value={g.key}>{bi(g, lang)}</option>)}</select>
        <label className="flex items-center gap-2 text-[13px]"><span className="text-ink-3 shrink-0">{L('من', 'From')}</span><input type="date" className="field num min-w-0" value={f.from ?? ''} onChange={e => set({ from: e.target.value || undefined })} /></label>
        <label className="flex items-center gap-2 text-[13px]"><span className="text-ink-3 shrink-0">{L('إلى', 'To')}</span><input type="date" className="field num min-w-0" value={f.to ?? ''} onChange={e => set({ to: e.target.value || undefined })} /></label>
      </div>
      <div className="flex flex-wrap items-center gap-3 mt-3 text-[13px]">
        {d.can.archive && <label className="flex items-center gap-2"><input type="checkbox" checked={!!f.archive} onChange={e => set({ archive: e.target.checked ? '1' : undefined })} /><Archive size={14} className="text-ink-3" />{L('البحث في الأرشيف الكامل', 'Search the full archive')}</label>}
        {!f.archive && <span className="text-ink-3">{L('يُعرض منذ', 'Showing since')} <span className="num">{fmtDate(d.since, lang)}</span></span>}
        {(f.q || f.user || f.group || f.from || f.to) && <button className="text-brand flex items-center gap-1" onClick={() => { setQText(''); setQuery({ q: undefined, user: undefined, group: undefined, from: undefined, to: undefined, page: undefined }) }}><X size={13} />{L('مسح التصفية', 'Clear filters')}</button>}
        <span className="ms-auto text-ink-3"><span className="num">{d.total.toLocaleString('en')}</span> {L('عملية', 'entries')}</span>
      </div>
    </Panel>
    <Panel pad={false}>
      {d.data.length === 0 ? <Empty title={L('لا توجد عمليات تطابق التصفية', 'No entries match the filters')} /> : <table className="tbl">
        <thead><tr><th>{L('الوقت', 'Time')}</th><th>{L('المستخدم', 'User')}</th><th>{L('العملية', 'Operation')}</th><th className="hidden lg:table-cell">{L('العنصر', 'Item')}</th><th className="hidden md:table-cell">{L('العنوان', 'IP')}</th></tr></thead>
        <tbody>{d.data.map(r => <tr key={r.id} className="cursor-pointer hover:bg-sub" onClick={() => setOpen(r)}>
          <td className="num whitespace-nowrap text-[12.5px]">{fmtDateTime(r.at, lang)}</td>
          <td>{r.user ? <><div className="font-medium">{bi(r.user, lang)}</div><div className="text-[12px] text-ink-3 ltr text-start hidden sm:block">{r.user.email}</div></> : <span className="text-ink-3">{L('النظام', 'System')}</span>}</td>
          <td><div>{bi(r.label, lang)}</div><div className="text-[11.5px] text-ink-3 ltr text-start">{r.action}</div><div className="lg:hidden text-[12px] text-ink-3">{r.subject && <>{bi(r.subject, lang)} <span className="num">#{r.subject.id}</span></>}</div></td>
          <td className="hidden lg:table-cell">{r.subject ? <>{bi(r.subject, lang)} <span className="text-ink-3 num">#{r.subject.id}</span></> : <span className="text-ink-3">—</span>}</td>
          <td className="hidden md:table-cell ltr text-start text-[12.5px] text-ink-3">{r.ip ?? '—'}</td>
        </tr>)}</tbody></table>}
      {pages > 1 && <div className="flex items-center justify-center gap-3 py-3 border-t border-line text-[13px]">
        <Btn size="sm" icon={<Prev size={15} />} disabled={d.page <= 1} onClick={() => setQuery({ page: String(d.page - 1) })}>{L('السابق', 'Previous')}</Btn>
        <span className="num">{d.page} / {pages}</span>
        <Btn size="sm" disabled={d.page >= pages} onClick={() => setQuery({ page: String(d.page + 1) })}>{L('التالي', 'Next')}<Next size={15} /></Btn>
      </div>}
    </Panel>
    <Drawer open={!!open} onClose={() => setOpen(null)} width={640}>{open && <AuditDetail r={open} onClose={() => setOpen(null)} />}</Drawer>
    {settings && <AuditSettings days={d.settings.visible_days} def={d.defaults.visible_days} onClose={() => setSettings(false)} />}
    {archives && <ArchiveFiles onClose={() => setArchives(false)} />}
  </div>
}

const show = (x: unknown) => x === null || x === undefined ? '—' : typeof x === 'object' ? JSON.stringify(x) : String(x)

function AuditDetail({ r, onClose }: { r: AuditRow; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const keys = [...new Set([...Object.keys(r.before ?? {}), ...Object.keys(r.after ?? {})])]
  return <div className="p-5 space-y-4">
    <div className="flex items-start gap-3"><div className="flex-1"><h2 className="text-[17px] font-bold">{bi(r.label, lang)}</h2><div className="text-[12px] text-ink-3"><span className="ltr inline-block">{r.action}</span></div></div>
      <button onClick={onClose} className="p-1.5 rounded-lg hover:bg-sub" aria-label={L('إغلاق', 'Close')}><X size={18} /></button></div>
    <dl className="grid grid-cols-[110px_1fr] gap-y-2 text-[13.5px]">
      <dt className="text-ink-3">{L('الوقت', 'Time')}</dt><dd className="num">{fmtDateTime(r.at, lang)}</dd>
      <dt className="text-ink-3">{L('المستخدم', 'User')}</dt><dd>{r.user ? <>{bi(r.user, lang)} <span className="text-ink-3 ltr">{r.user.email}</span></> : L('النظام', 'System')}</dd>
      <dt className="text-ink-3">{L('العنصر', 'Item')}</dt><dd>{r.subject ? <>{bi(r.subject, lang)} <span className="num">#{r.subject.id}</span></> : '—'}</dd>
      <dt className="text-ink-3">{L('العنوان', 'IP')}</dt><dd><span className="ltr inline-block">{r.ip ?? '—'}</span></dd>
      <dt className="text-ink-3">{L('المتصفح', 'Browser')}</dt><dd className="text-[12px] text-ink-3 break-all"><span className="ltr inline-block">{r.agent ?? '—'}</span></dd>
    </dl>
    {keys.length > 0 && <div className="rounded-xl border border-line overflow-hidden">
      <table className="tbl text-[13px]"><thead><tr><th>{L('الحقل', 'Field')}</th>{r.before && <th>{L('قبل', 'Before')}</th>}{r.after && <th>{L('بعد', 'After')}</th>}</tr></thead>
        <tbody>{keys.map(k => <tr key={k}><td className="ltr text-start text-ink-2 align-top">{k}</td>
          {r.before && <td className="align-top break-all"><span className="text-danger">{show(r.before[k])}</span></td>}
          {r.after && <td className="align-top break-all"><span className="text-brand">{show(r.after[k])}</span></td>}</tr>)}</tbody></table>
    </div>}
  </div>
}

function AuditSettings({ days, def, onClose }: { days: number; def: number; onClose: () => void }) {
  const L = useL()
  const save = useSaveAuditSettings()
  const [v, setV] = useState(days)
  const err = save.error instanceof ApiError ? save.error : null
  return <Modal open onClose={onClose} title={L('إعداد سجل التدقيق', 'Audit log setting')}
    footer={<><Btn onClick={() => setV(def)}>{L('الافتراضي', 'Default')}</Btn><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate({ visible_days: v }, { onSuccess: () => { toast(L('حُفظ الإعداد', 'Setting saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <Field label={L('أيام العرض في الواجهة', 'Days shown in the interface')} hint={L('من 7 إلى 365. الأقدم يبقى في الأرشيف الدائم ولا يُحذف.', 'From 7 to 365. Older entries stay in the permanent archive and are never deleted.')} error={err?.field('visible_days')}>
      <input type="number" min={7} max={365} className="field num" value={v} onChange={e => setV(Number(e.target.value))} /></Field>
  </Modal>
}

function ArchiveFiles({ onClose }: { onClose: () => void }) {
  const L = useL()
  const q = useAuditArchives(true)
  return <Modal open onClose={onClose} title={L('ملفات الأرشيف الشهرية', 'Monthly archive files')} footer={<Btn onClick={onClose}>{L('إغلاق', 'Close')}</Btn>}>
    <p className="text-[13px] text-ink-2 mb-3">{L('يُكتب ملف مضغوط لكل شهر يخرج من نافذة العرض (JSON Lines)، وتبقى السجلات في قاعدة البيانات أيضاً.', 'A compressed file (JSON Lines) is written for every month that leaves the display window; the entries also stay in the database.')}</p>
    {!q.data ? <div className="grid place-items-center py-8"><Loader2 className="animate-spin text-brand" /></div> : q.data.data.length === 0 ? <Empty title={L('لا توجد ملفات بعد', 'No files yet')} />
      : <ul className="divide-y divide-line-2">{q.data.data.map(f => <li key={f.month} className="py-2 flex items-center gap-3 text-[13.5px]"><FileArchive size={16} className="text-ink-3" /><span className="num flex-1">{f.month}</span><Badge>{fmtSize(f.size)}</Badge>
        <Btn size="sm" icon={<Download size={14} />} onClick={() => download(`/hr/admin/audit/archives/${f.month}`, `UHR-audit-${f.month}.jsonl.gz`)}>{L('تنزيل', 'Download')}</Btn></li>)}</ul>}
  </Modal>
}
