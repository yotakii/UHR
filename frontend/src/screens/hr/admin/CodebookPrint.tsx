import { ArrowRight, Loader2 } from 'lucide-react'
import { useL, useLang, bi } from '../../../lib/store'
import { fmtDate } from '../../../lib/dates'
import { navigate, setQuery, useRoute } from '../../../lib/router'
import { logBookPrint, useBook, useVersions } from '../../../lib/codes'
import { Paper } from '../../../ui/docs'
import { Btn } from '../../../ui/kit'

/** طباعة دليل الترميز كاملاً بترويسة الشركة (FR-CS-015): الحالي أو إصدار معتمد سابق */
export function CodebookPrint() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const version = route.query.version
  const { data } = useBook(version)
  const versions = useVersions().data
  if (!data) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  return <div className="max-w-[900px] mx-auto">
    <div className="flex flex-wrap gap-2 items-center mb-3" data-noprint>
      <Btn icon={<ArrowRight size={16} className="ltr:rotate-180" />} onClick={() => navigate('/hr/settings/codes')}>{L('رجوع', 'Back')}</Btn>
      {versions && versions.length > 0 && <select className="field w-auto" value={version ?? ''} onChange={e => setQuery({ version: e.target.value || undefined })} aria-label={L('الإصدار', 'Version')}>
        <option value="">{L('الحالي', 'Current')}</option>{versions.map(v => <option key={v.id} value={v.id}>{v.version}</option>)}</select>}
      <span className="text-[12.5px] text-ink-3">{L('للحفظ PDF اختر «حفظ بصيغة PDF» من نافذة الطباعة.', 'To save a PDF choose «Save as PDF» in the print window.')}</span>
    </div>
    <div onClickCapture={e => { if ((e.target as HTMLElement).closest('button')?.textContent?.includes(L('طباعة', 'Print'))) void logBookPrint() }}>
      <Paper code="CDB" title={L('دليل الترميز', 'Codebook')} sub={data.version ? `${L('الإصدار', 'Version')} ${data.version}${data.approved_at ? ` · ${fmtDate(data.approved_at, lang)}` : ''}` : L('مسودة غير معتمدة', 'Unapproved draft')}>
        <div className="space-y-6 text-[12.5px]">
          {data.lists.filter(l => l.items.length).map(l => <section key={l.key} className="break-inside-avoid-page">
            <h3 className="font-bold text-[14px] mb-1">{bi(l, lang)}{l.pattern && <span className="font-normal text-ink-3 ltr inline-block ms-2">{l.example}</span>}</h3>
            <table className="w-full border-collapse"><thead><tr className="bg-sub"><th className="border border-line px-2 py-1 text-start w-[130px]">{L('الرمز', 'Code')}</th><th className="border border-line px-2 py-1 text-start">{L('الاسم', 'Name')}</th>
              <th className="border border-line px-2 py-1 text-start">{L('بالإنكليزية', 'English')}</th><th className="border border-line px-2 py-1 text-start w-[90px]">{L('الأب', 'Parent')}</th></tr></thead>
              <tbody>{l.items.map(i => <tr key={i.code} className={i.status === 'active' ? '' : 'text-ink-3'}><td className="border border-line px-2 py-0.5 ltr text-start">{i.code}</td><td className="border border-line px-2 py-0.5">{i.ar}{i.status !== 'active' && ` (${L('معطّل', 'inactive')})`}</td>
                <td className="border border-line px-2 py-0.5 ltr text-start">{i.en}</td><td className="border border-line px-2 py-0.5 ltr text-start">{i.parent ?? ''}</td></tr>)}</tbody></table>
          </section>)}
          <section className="break-inside-avoid-page"><h3 className="font-bold text-[14px] mb-1">{L('قوالب الرموز المركّبة', 'Composite code templates')}</h3>
            <ul>{Object.entries(data.templates).map(([k, t]) => <li key={k}>{bi(t, lang)}: <span className="ltr inline-block">{t.template}</span></li>)}</ul></section>
        </div>
      </Paper>
    </div>
  </div>
}
