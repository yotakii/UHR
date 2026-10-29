import { useState } from 'react'
import { CheckCircle2, Clock, DatabaseBackup, Download, Loader2, Play, Server, Settings2, XCircle } from 'lucide-react'
import { useL, useLang, bi, fmtDateTime } from '../../../lib/store'
import { ApiError } from '../../../lib/api'
import { openVault } from '../../../lib/vault'
import { download, fmtSize, useBackups, usePlatformBackups, useRunBackup, useRunPlatformBackup, useSaveBackupSettings, useSavePlatformBackupSettings, type BackupRow } from '../../../lib/admin'
import { Badge, Btn, Empty, Field, Modal, PageHead, Panel, Seg, toast } from '../../../ui/kit'

/** تنزيل نسخة: الملف يحوي الرواتب ← تحقق الهوية (وإذن رواتب الشركة كلها للنسخة الشركة) ثم نعيد المحاولة */
async function fetchBackup(path: string, name: string, L: (a: string, b: string) => string) {
  try { await download(path, name); toast(L('بدأ التنزيل', 'Download started')) }
  catch (e) {
    if (e instanceof ApiError && (e.code === 'salary_locked' || e.code === 'salary_grant_required')) { toast(e.message); openVault('others') }
    else toast(e instanceof ApiError ? e.message : L('تعذّر التنزيل', 'Download failed'))
  }
}

function StatusBadge({ b }: { b: BackupRow }) {
  const L = useL()
  return b.status === 'ok' ? <Badge tone="brand"><CheckCircle2 size={12} />{L('ناجحة', 'Done')}</Badge> : b.status === 'failed' ? <Badge tone="danger"><XCircle size={12} />{L('فشلت', 'Failed')}</Badge>
    : b.status === 'running' ? <Badge tone="info"><Loader2 size={12} className="animate-spin" />{L('قيد التنفيذ', 'Running')}</Badge> : <Badge>{L('حُذف الملف (الاحتفاظ)', 'File removed (retention)')}</Badge>
}

function BackupTable({ rows, onDownload }: { rows: BackupRow[]; onDownload?: (b: BackupRow) => void }) {
  const L = useL(); const lang = useLang()
  if (!rows.length) return <Empty title={L('لا توجد نسخ بعد', 'No backups yet')} />
  return <table className="tbl">
    <thead><tr><th>{L('الوقت', 'Time')}</th><th>{L('الحالة', 'Status')}</th><th className="hidden sm:table-cell">{L('الحجم', 'Size')}</th><th className="hidden md:table-cell">{L('المحتوى', 'Contents')}</th><th className="hidden lg:table-cell">{L('بواسطة', 'By')}</th><th /></tr></thead>
    <tbody>{rows.map(b => <tr key={b.id}>
      <td className="num text-[12.5px] whitespace-nowrap">{b.started_at ? fmtDateTime(b.started_at, lang) : '—'}<div className="text-[11.5px] text-ink-3">{b.trigger === 'auto' ? L('آلية', 'Automatic') : L('يدوية', 'Manual')}</div></td>
      <td><StatusBadge b={b} />{b.error && <div className="text-[11.5px] text-danger mt-1 max-w-[320px] break-words">{b.error}</div>}</td>
      <td className="hidden sm:table-cell num">{fmtSize(b.size)}</td>
      <td className="hidden md:table-cell text-[12.5px] text-ink-2">{b.meta ? b.kind === 'company'
        ? L(`${b.meta.tables} جدولاً · ${Number(b.meta.rows).toLocaleString('en')} سجلاً · ${b.meta.files} ملفاً`, `${b.meta.tables} tables · ${Number(b.meta.rows).toLocaleString('en')} rows · ${b.meta.files} files`)
        : L(`${b.meta.tables} جدولاً — قاعدة البيانات كاملة`, `${b.meta.tables} tables — the whole database`) : '—'}</td>
      <td className="hidden lg:table-cell">{b.by ? bi(b.by, lang) : <span className="text-ink-3">{L('الجدولة', 'Scheduler')}</span>}</td>
      <td className="text-end">{onDownload && b.status === 'ok' && <Btn size="sm" icon={<Download size={14} />} onClick={() => onDownload(b)}>{L('تنزيل', 'Download')}</Btn>}</td>
    </tr>)}</tbody></table>
}

/** M16 «النسخ الاحتياطي» للشركة: حالة نسخة الخادم اليومية + نسخة بيانات الشركة (zip) عند الطلب */
export function Backups() {
  const L = useL(); const lang = useLang()
  const q = useBackups(); const run = useRunBackup()
  const [settings, setSettings] = useState(false)
  const d = q.data
  if (!d) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const last = d.server.last
  return <div className="max-w-[1100px] mx-auto space-y-5">
    <PageHead title={L('النسخ الاحتياطي', 'Backups')} sub={L('نسخة كاملة للخادم يومياً، ونسخة من بيانات شركتك تنزّلها متى شئت.', 'A full server copy every day, plus a copy of your company data to download whenever you need it.')}
      actions={d.can.edit && <><Btn icon={<Settings2 size={15} />} onClick={() => setSettings(true)}>{L('الإعدادات', 'Settings')}</Btn>
        <Btn kind="primary" icon={run.isPending ? <Loader2 size={15} className="animate-spin" /> : <Play size={15} />} disabled={run.isPending}
          onClick={() => run.mutate(undefined, { onSuccess: r => toast(r.data.status === 'ok' ? L('أُنشئت نسخة بيانات الشركة', 'Company copy created') : L('فشلت النسخة', 'Backup failed')), onError: e => toast(e.message) })}>{L('نسخة الآن', 'Back up now')}</Btn></>} />
    <Panel>
      <div className="flex flex-wrap items-center gap-4">
        <span className="size-12 rounded-xl grid place-items-center bg-info-soft text-info"><Server size={22} /></span>
        <div className="flex-1 min-w-[220px]">
          <div className="font-semibold">{L('نسخة الخادم الكاملة', 'Full server backup')}</div>
          <div className="text-[13px] text-ink-2 mt-0.5">{d.server.schedule.enabled ? L(`يومياً الساعة ${d.server.schedule.time} — يُحتفظ بآخر ${d.server.schedule.keep} نسخة على الخادم.`, `Daily at ${d.server.schedule.time} — the last ${d.server.schedule.keep} copies are kept on the server.`) : L('النسخ الآلي متوقف — تواصل مع إدارة المنصة.', 'Automatic backup is off — contact the platform administrator.')}</div>
        </div>
        <div className="text-[13px]">{last ? <div className="flex items-center gap-2"><StatusBadge b={last} /><span className="num text-ink-2">{last.started_at && fmtDateTime(last.started_at, lang)}</span></div> : <span className="text-ink-3">{L('لم تُنفَّذ نسخة بعد', 'No backup yet')}</span>}
          {d.server.last_ok_at && last?.status !== 'ok' && <div className="text-[12px] text-ink-3 mt-1">{L('آخر نسخة ناجحة:', 'Last successful copy:')} <span className="num">{fmtDateTime(d.server.last_ok_at, lang)}</span></div>}</div>
      </div>
    </Panel>
    <Panel title={<span className="flex items-center gap-2"><DatabaseBackup size={17} />{L('نسخ بيانات الشركة', 'Company data copies')}</span>}
      sub={L(`ملف ZIP بكل سجلات الشركة (JSON) ${d.settings.include_files ? 'وملفاتها المرفوعة' : 'بدون الملفات المرفوعة'} — بلا كلمات مرور أو مفاتيح تحقق. يُحتفظ بآخر ${d.settings.keep} نسخ. التنزيل يحتاج التحقق من الهوية لأن الملف يحوي الرواتب.`,
        `A ZIP with every company record (JSON) ${d.settings.include_files ? 'and its uploaded files' : 'without uploaded files'} — no passwords or verification keys. The last ${d.settings.keep} copies are kept. Downloading needs identity verification because the file holds salaries.`)} pad={false}>
      <BackupTable rows={d.data} onDownload={d.can.edit ? b => fetchBackup(`/hr/admin/backups/${b.id}/download`, b.name ?? 'backup.zip', L) : undefined} />
    </Panel>
    {settings && <CompanyBackupSettings v={d.settings} def={d.defaults} onClose={() => setSettings(false)} />}
  </div>
}

function CompanyBackupSettings({ v: init, def, onClose }: { v: { keep: number; include_files: boolean }; def: { keep: number; include_files: boolean }; onClose: () => void }) {
  const L = useL()
  const save = useSaveBackupSettings(); const [v, setV] = useState(init)
  const err = save.error instanceof ApiError ? save.error : null
  return <Modal open onClose={onClose} title={L('إعدادات نسخ الشركة', 'Company copy settings')}
    footer={<><Btn onClick={() => setV(def)}>{L('الافتراضي', 'Default')}</Btn><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => { toast(L('حُفظت الإعدادات', 'Settings saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid gap-4">
      <Field label={L('عدد النسخ المحفوظة', 'Copies kept')} hint={L('من 1 إلى 50 — تُحذف ملفات الأقدم ويبقى سطرها في السجل.', '1 to 50 — older files are removed; their row stays in the list.')} error={err?.field('keep')}><input type="number" min={1} max={50} className="field num" value={v.keep} onChange={e => setV({ ...v, keep: Number(e.target.value) })} /></Field>
      <Field label={L('الملفات المرفوعة', 'Uploaded files')} div><Seg value={v.include_files ? 'yes' : 'no'} onChange={x => setV({ ...v, include_files: x === 'yes' })} options={[{ v: 'yes', label: L('تُضمَّن في النسخة', 'Included') }, { v: 'no', label: L('السجلات فقط', 'Records only') }]} /></Field>
    </div>
  </Modal>
}

/** المنصة: نسخة الخادم الكاملة (pg_dump) — يدوياً وآلياً يومياً، والإعدادات */
export function PlatformBackups() {
  const L = useL()
  const q = usePlatformBackups(); const run = useRunPlatformBackup(); const save = useSavePlatformBackupSettings()
  const [v, setV] = useState<{ enabled: boolean; time: string; keep: number } | null>(null)
  const d = q.data
  if (!d) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const s = v ?? d.settings
  const err = save.error instanceof ApiError ? save.error : null
  return <div className="max-w-[1100px] mx-auto space-y-5">
    <PageHead title={L('النسخ الاحتياطي للخادم', 'Server backups')} sub={L('نسخة كاملة لقاعدة البيانات بصيغة pg_dump، تُستعاد بـ pg_restore.', 'A full database copy in pg_dump format, restored with pg_restore.')}
      actions={<Btn kind="primary" icon={run.isPending ? <Loader2 size={15} className="animate-spin" /> : <Play size={15} />} disabled={run.isPending}
        onClick={() => run.mutate(undefined, { onSuccess: r => toast(r.data.status === 'ok' ? L('أُنشئت النسخة', 'Backup created') : L('فشلت النسخة', 'Backup failed')), onError: e => toast(e.message) })}>{L('نسخة الآن', 'Back up now')}</Btn>} />
    <Panel title={<span className="flex items-center gap-2"><Clock size={17} />{L('الجدولة', 'Schedule')}</span>}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(200px,1fr))] items-end">
        <Field label={L('النسخ الآلي', 'Automatic backup')} div><Seg value={s.enabled ? 'on' : 'off'} onChange={x => setV({ ...s, enabled: x === 'on' })} options={[{ v: 'on', label: L('يومياً', 'Daily') }, { v: 'off', label: L('متوقف', 'Off') }]} /></Field>
        <Field label={L('الوقت', 'Time')} error={err?.field('time')}><input type="time" className="field num" value={s.time} onChange={e => setV({ ...s, time: e.target.value })} /></Field>
        <Field label={L('عدد النسخ المحفوظة', 'Copies kept')} error={err?.field('keep')}><input type="number" min={1} max={90} className="field num" value={s.keep} onChange={e => setV({ ...s, keep: Number(e.target.value) })} /></Field>
        <div className="flex gap-2"><Btn onClick={() => setV(d.defaults)}>{L('الافتراضي', 'Default')}</Btn><Btn kind="primary" disabled={!v || save.isPending} onClick={() => v && save.mutate(v, { onSuccess: () => { setV(null); toast(L('حُفظت الجدولة', 'Schedule saved')) } })}>{L('حفظ', 'Save')}</Btn></div>
      </div>
    </Panel>
    <Panel title={L('النسخ', 'Copies')} pad={false}><BackupTable rows={d.data} onDownload={b => fetchBackup(`/platform/backups/${b.id}/download`, b.name ?? 'uhr.dump', L)} /></Panel>
  </div>
}
