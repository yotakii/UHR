import { CalendarDays, PartyPopper } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import { setQuery, useRoute } from '../../lib/router'
import { useMyHolidays } from '../../lib/portal'
import { Badge, Empty, PageHead, Panel } from '../../ui/kit'

const DAYS: [string, string][] = [['الأحد', 'Sun'], ['الاثنين', 'Mon'], ['الثلاثاء', 'Tue'], ['الأربعاء', 'Wed'], ['الخميس', 'Thu'], ['الجمعة', 'Fri'], ['السبت', 'Sat']]

/** العطل الرسمية — صفحة للموظف (والـHR لنفسه): عطل السنة مجمّعة بالفترات، القادمة أولاً بالتمييز، وأيام الدوام الأسبوعية */
export function MyHolidays() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const year = Number(route.query.year) || new Date().getFullYear()
  const { data: d, isError } = useMyHolidays(year)
  const next = d?.data.find(h => h.state !== 'past')
  const weekday = (iso: string) => DAYS[new Date(iso + 'T12:00:00').getDay()][lang === 'ar' ? 0 : 1]
  const rest = d ? DAYS.map((x, i) => [x, i] as const).filter(([, i]) => !d.work_days.includes(i)).map(([x]) => x[lang === 'ar' ? 0 : 1]) : []
  return <div className="max-w-[900px] mx-auto">
    <PageHead title={L('العطل الرسمية', 'Public holidays')} sub={d ? L(`${d.data.length} عطلة بمجموع ${d.total} يوماً في ${year}`, `${d.data.length} holidays, ${d.total} days in ${year}`) : undefined}
      actions={d && d.years.length > 1 && <select className="field !w-auto" value={year} onChange={e => setQuery({ year: e.target.value })} aria-label={L('السنة', 'Year')}>
        {d.years.map(y => <option key={y} value={y}>{y}</option>)}</select>} />
    {next && <div className="rounded-2xl border border-brand/30 bg-brand-soft px-5 py-4 mb-4 flex items-center gap-4">
      <PartyPopper size={26} className="text-brand shrink-0" />
      <div className="min-w-0"><div className="text-[12px] text-ink-3">{next.state === 'now' ? L('عطلة جارية', 'Holiday in progress') : L('العطلة القادمة', 'Next holiday')}</div>
        <div className="font-bold text-[16px]">{bi(next, lang)}</div>
        <div className="text-[13px] text-ink-2">{weekday(next.from)} {fmtDate(next.from, lang)}{next.days > 1 ? ` — ${weekday(next.to)} ${fmtDate(next.to, lang)}` : ''} · {L(`${next.days} يوم`, `${next.days} day(s)`)}</div></div>
    </div>}
    <Panel pad={false} title={<span className="flex items-center gap-2"><CalendarDays size={17} className="text-brand" />{L(`عطل ${year}`, `${year} holidays`)}</span>}>
      {!d ? <Empty title={isError ? L('تعذّر التحميل', 'Could not load') : L('جارٍ التحميل…', 'Loading…')} />
        : d.data.length === 0 ? <Empty title={L('لم تُسجَّل عطل رسمية لهذه السنة بعد', 'No public holidays recorded for this year yet')}>{L('تضيفها الموارد البشرية من إعدادات الدوام.', 'HR adds them in the work settings.')}</Empty>
        : <ul className="divide-y divide-line-2">{d.data.map(h => <li key={h.from} className={`px-4 py-3 flex items-center gap-3 ${h.state === 'past' ? 'opacity-60' : ''}`}>
          <div className="w-14 text-center shrink-0"><div className="text-[20px] font-bold num leading-none">{h.from.slice(8)}</div><div className="text-[11px] text-ink-3 mt-1">{new Date(h.from + 'T12:00:00').toLocaleDateString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { month: 'short' })}</div></div>
          <div className="flex-1 min-w-0"><div className="font-semibold">{bi(h, lang)}</div>
            <div className="text-[12.5px] text-ink-3">{weekday(h.from)}{h.days > 1 ? ` — ${weekday(h.to)} ${fmtDate(h.to, lang)}` : ''}</div></div>
          <span className="text-[12.5px] text-ink-2 num">{L(`${h.days} يوم`, `${h.days} d`)}</span>
          {h.state === 'now' ? <Badge tone="brand" dot>{L('اليوم', 'Today')}</Badge> : h.state === 'upcoming' ? <Badge tone="info">{L('قادمة', 'Upcoming')}</Badge> : <Badge>{L('مضت', 'Past')}</Badge>}
        </li>)}</ul>}
    </Panel>
    {d && <p className="text-[12.5px] text-ink-3 mt-3">{L(`العطلة الأسبوعية: ${rest.join('، ')}. العطل الرسمية لا تُخصم من رصيد إجازتك السنوية.`, `Weekly rest: ${rest.join(', ')}. Public holidays are not deducted from your annual leave.`)}</p>}
  </div>
}
