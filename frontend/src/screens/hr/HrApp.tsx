import { Briefcase } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import type { SessionUser } from '../../lib/session'
import { Shell, type NavItem } from '../console/Shell'
import { Jobs } from './Jobs'

/** نظام الشركة (لوحة HR ومسؤول التوظيف). الصفحات تُضاف للقائمة مع بنائها (المهام 05–11) */
export function HrApp({ user }: { user: SessionUser }) {
  const L = useL(); const lang = useLang()
  const items: NavItem[] = [
    { to: '/hr/jobs', icon: <Briefcase size={18} />, label: L('الوظائف الشاغرة', 'Job openings'), group: L('التوظيف والتعيين', 'Hiring') },
  ]
  return <Shell items={items} user={user} org={user.company ? bi(user.company.short, lang) : ''} sub={L('مركز عمليات الموارد البشرية', 'People Operations Center')}>
    <Jobs />
  </Shell>
}
