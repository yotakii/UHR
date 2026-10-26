import type { ReactNode } from 'react'
import { useL } from '../../../lib/store'
import { PageHead } from '../../../ui/kit'
import type { NavItem } from '../../console/Shell'

/** لوحة «الإعدادات» (M16): كل صفحات الإعدادات المسموحة للمستخدم، مجمّعة — بدل قائمة طويلة في السايدبار */
export function SettingsHub({ pages }: { pages: { item: NavItem; section?: 'org' | 'rules' | 'system' }[] }) {
  const L = useL()
  const groups: [string, ReactNode][] = [['org', L('الشركة والتنظيم', 'Company & organisation')], ['rules', L('السياسات والقواعد', 'Policies & rules')], ['system', L('الأجهزة والنماذج والإشعارات', 'Devices, forms & notifications')]]
  return <div className="max-w-[1200px] mx-auto space-y-6">
    <PageHead title={L('الإعدادات', 'Settings')} sub={L('كل ما يخص الشركة وقواعدها — يتغير من هنا وينعكس فوراً، وكل تغيير مسجّل في التدقيق.', 'Everything about the company and its rules — changed here, applied at once, and every change is audited.')} />
    {groups.map(([k, title]) => { const list = pages.filter(p => p.section === k); return list.length > 0 && <section key={k}>
      <h2 className="text-[13px] font-semibold text-ink-3 mb-2">{title}</h2>
      <div className="grid [grid-template-columns:repeat(auto-fill,minmax(230px,1fr))] gap-3">{list.map(p => <a key={p.item.to} href={p.item.to}
        className="hoverlift rounded-2xl border border-line bg-surface p-4 flex items-center gap-3"><span className="size-11 rounded-xl grid place-items-center bg-brand-soft text-brand shrink-0">{p.item.icon}</span>
        <span className="font-semibold text-[14px]">{p.item.label}</span></a>)}</div>
    </section> })}
  </div>
}
