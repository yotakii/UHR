import { Trophy, Coffee, Dumbbell, GraduationCap, Car, Headphones, CalendarPlus } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import type { Leader, Redemption, RewardKind } from '../../lib/rewards'
import { Avatar, Badge, Progress } from '../../ui/kit'

export const rewardIcon: Record<RewardKind, React.ReactNode> = { leave: <CalendarPlus size={22} />, food: <Coffee size={22} />, gym: <Dumbbell size={22} />, course: <GraduationCap size={22} />, parking: <Car size={22} />, gift: <Headphones size={22} /> }
const medal = ['text-[#e7c27a]', 'text-[#c9d1d9]', 'text-[#d39a6a]']

/** لوحة المتصدرين (Leaderboard في الديمو) */
export function Leaderboard({ rows }: { rows: Leader[] }) {
  const L = useL(); const lang = useLang()
  const top = rows[0]?.lifetime || 1
  if (!rows.length) return <p className="text-[13px] text-ink-3">{L('لا نقاط مكتسبة بعد — شارك رابط إحالة لتبدأ.', 'No points yet — share a referral link to start.')}</p>
  return <ol className="space-y-2.5">{rows.map((e, i) => <li key={e.id} className="flex items-center gap-3 rounded-xl border border-line-2 bg-sub/50 px-3 py-2.5 fadeup" style={{ animationDelay: `${i * 60}ms` }}>
    <span className={`w-6 text-center font-black font-display ${medal[i] ?? 'text-ink-3'}`}>{i < 3 ? <Trophy size={18} className="inline" /> : i + 1}</span>
    <Avatar name={e.name.ar} size={34} />
    <div className="flex-1 min-w-0"><div className="font-semibold truncate">{bi(e.name, lang)}</div>
      <div className="mt-1"><Progress value={e.lifetime / top * 100} tone={i === 0 ? 'brass' : 'brand'} /></div></div>
    <div className="text-end"><div className="font-black font-display num">{e.lifetime.toLocaleString('en-US')}</div><div className="text-[11px] text-ink-3"><span className="num">{e.hires}</span> {L('تعيينات', 'hires')}</div></div>
  </li>)}</ol>
}

export function RedemptionBadge({ s }: { s: Redemption['status'] }) {
  const L = useL()
  return s === 'pending' ? <Badge tone="warn">{L('نقاط محجوزة', 'Points held')}</Badge> : s === 'approved' ? <Badge tone="brand">{L('معتمد', 'Approved')}</Badge> : <Badge tone="danger">{L('مرفوض — أُعيدت النقاط', 'Declined — refunded')}</Badge>
}

export function ReferralBadge({ s, awarded }: { s: string; awarded: number }) {
  const L = useL()
  if (s === 'hired' || s === 'rewarded') return <Badge tone="brass">+{awarded} {L('نقطة', 'pts')}</Badge>
  if (s === 'applied' || s === 'processing') return <Badge tone="brand">{s === 'applied' ? L('تقدّم', 'Applied') : L('قيد المعالجة', 'In process')}{awarded ? ` · +${awarded}` : ''}</Badge>
  if (s === 'closed') return <Badge>{L('مغلق', 'Closed')}</Badge>
  return <Badge>{L('مُشارك', 'Shared')}</Badge>
}

/** شرح نقاط الإحالة المرحلية من قواعد التوظيف */
export function stagesText(stages: number[], x: number, L: (a: string, e: string) => string) {
  const [a, b, c, d] = stages
  return L(`النقاط على مراحل: ${a} عند الفرز، ${b} عند المقابلة، ${c} عند التعيين، ${d} بعد تثبيته — ومضاعفة ×${x} للوظائف صعبة الإشغال.`,
    `Points by stage: ${a} at screening, ${b} at interview, ${c} at hire, ${d} once confirmed — ×${x} for hard-to-fill roles.`)
}
