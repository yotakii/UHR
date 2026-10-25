import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'
import type { ReqRow } from './requests'

/** M12 — الإحالات والمكافآت (SRS §10.9)، الاستقطاب الداخلي F-27 (D-22)، والإعلانات (D-06) */
export type RewardKind = 'leave' | 'food' | 'gym' | 'course' | 'parking' | 'gift'
export interface Reward { id: number; title: Bi; cost: number; stock: number; kind: RewardKind; active: boolean }
export interface Redemption {
  id: number; employee: { id: number; code: string; name: Bi } | null; reward: { id: number; title: Bi; kind: RewardKind } | null
  cost: number; status: 'pending' | 'approved' | 'rejected'; note: string | null; decided_by: string | null; at: string; decided_at: string | null
}
export interface Leader { id: number; name: Bi; code: string; lifetime: number; hires: number }
export interface ReferralLink {
  id: number; code: string; clicks: number; status: 'shared' | 'applied' | 'processing' | 'hired' | 'rewarded' | 'closed'; awarded: number; created_at: string
  employee: { id: number; name: Bi } | null; job: { id: number; code: string; title: Bi; hard_to_fill: boolean } | null; candidate: { id: number; name: Bi; stage: string } | null
}
export interface ReferralsHr {
  leaderboard: Leader[]; funnel: { shared: number; applied: number; hired: number; points: number }; links: ReferralLink[]; redemptions: Redemption[]; rewards: Reward[]
  stages: number[]; hard_to_fill_x: number; kinds: RewardKind[]; reject_min: number; can: { edit: boolean }
}
export interface ShareJob { id: number; code: string; title: Bi; dept: Bi | null; hard_to_fill: boolean; referral: { code: string; link: string; clicks: number; status: string } | null }
export interface MyRewards {
  points: number; lifetime: number; jobs: ShareJob[]; leaderboard: Leader[]; stages: number[]; hard_to_fill_x: number; rewards: Reward[]; redemptions: Redemption[]
  log: { points: number; reason: Bi; at: string }[]; referrals: ReferralLink[]
}
export interface InternalJob { id: number; code: string; title: Bi; dept: Bi | null; visibility: string; hard_to_fill: boolean; own_dept: boolean; application: { id: number; status: string } | null }
export interface InternalOffer { status: 'sent' | 'accepted' | 'declined' | 'negotiation'; title: string | null; total: number; currency: 'USD' | 'SYP'; start_date: string | null; stage: string }
export interface MyInternal {
  eligibility: { ok: boolean; reasons: Bi[] }; jobs: InternalJob[]; rules: { min_months: number; no_probation: boolean; clean_months: number }
  data: (ReqRow & { offer: InternalOffer | null })[]
}
export interface RewardInput { title_ar: string; title_en: string; cost: number | ''; stock: number | ''; kind: RewardKind; active: boolean }

const refreshMine = () => { queryClient.invalidateQueries({ queryKey: ['me', 'rewards'] }); queryClient.invalidateQueries({ queryKey: ['me', 'internal'] }); queryClient.invalidateQueries({ queryKey: ['me', 'approvals'] }) }
const refreshHr = () => { queryClient.invalidateQueries({ queryKey: ['hr', 'referrals'] }); queryClient.invalidateQueries({ queryKey: ['hr', 'dashboard'] }) }

export const useReferralsHr = () => useQuery({ queryKey: ['hr', 'referrals'], queryFn: () => api<ReferralsHr>('/hr/referrals') })
export const useDecideRedemption = () => useMutation({ mutationFn: (v: { id: number; approve: boolean; note?: string }) => api(`/hr/referrals/redemptions/${v.id}`, { json: { approve: v.approve, note: v.note } }), onSuccess: refreshHr })
export const useSaveReward = (id: number | null) => useMutation({
  mutationFn: (v: RewardInput) => api<{ data: Reward }>(id ? `/hr/referrals/rewards/${id}` : '/hr/referrals/rewards', { method: id ? 'PUT' : 'POST', json: v }).then(r => r.data), onSuccess: refreshHr,
})

export const useMyRewards = () => useQuery({ queryKey: ['me', 'rewards'], queryFn: () => api<MyRewards>('/me/rewards') })
export const useShare = () => useMutation({ mutationFn: (jobId: number) => api<{ code: string; link: string }>('/me/rewards/share', { json: { job_id: jobId } }), onSuccess: refreshMine })
export const useRedeem = () => useMutation({ mutationFn: (rewardId: number) => api<MyRewards>('/me/rewards/redeem', { json: { reward_id: rewardId } }), onSuccess: d => { queryClient.setQueryData(['me', 'rewards'], d) } })

export const useMyInternal = () => useQuery({ queryKey: ['me', 'internal'], queryFn: () => api<MyInternal>('/me/internal') })
export const useApplyInternal = () => useMutation({ mutationFn: (v: { job_id: number; reason: string }) => api<MyInternal>('/me/internal', { json: v }), onSuccess: d => { queryClient.setQueryData(['me', 'internal'], d); refreshMine() } })
export const useCancelInternal = () => useMutation({ mutationFn: (id: number) => api<MyInternal>(`/me/internal/${id}/cancel`, { method: 'POST' }), onSuccess: d => { queryClient.setQueryData(['me', 'internal'], d); refreshMine() } })
export const useInternalOffer = () => useMutation({
  mutationFn: (v: { id: number; status: 'accepted' | 'declined' | 'negotiation'; signature?: string; note?: string }) => api<MyInternal>(`/me/internal/${v.id}/offer-response`, { json: { status: v.status, signature: v.signature, note: v.note } }),
  onSuccess: d => { queryClient.setQueryData(['me', 'internal'], d) },
})

// ── الإعلانات ──
export type AnnTag = 'people' | 'payroll' | 'training' | 'policy' | 'event'
export interface Announcement {
  id: number; title: Bi; text: Bi; tag: AnnTag; audience: 'all' | 'department'; department: (Bi & { id: number }) | null
  image: number | null; attachment: { id: number; name: string } | null; published_at: string | null; expires_at: string | null; status: 'draft' | 'pending' | 'published' | 'expired'
  title_ar?: string; title_en?: string | null; text_ar?: string; text_en?: string; department_id?: number | null; author?: Bi | null; notified_at?: string | null; created_by?: number
}
export interface AnnouncementsHr {
  data: Announcement[]; departments: (Bi & { id: number })[]; tags: AnnTag[]; config: { require_approval: boolean; max_mb: number }; reject_min: number
  can: { create: boolean; decide: boolean; archive: boolean; settings: boolean }
}
export interface AnnInput { title_ar: string; title_en: string; text_ar: string; text_en: string; tag: AnnTag; audience: 'all' | 'department'; department_id: number | ''; publish_at: string; expires_at: string }

const refreshAnn = () => { queryClient.invalidateQueries({ queryKey: ['hr', 'announcements'] }); queryClient.invalidateQueries({ queryKey: ['me', 'announcements'] }) }
export const useAnnouncementsHr = () => useQuery({ queryKey: ['hr', 'announcements'], queryFn: () => api<AnnouncementsHr>('/hr/announcements') })
export const useMyAnnouncements = (enabled = true) => useQuery({ queryKey: ['me', 'announcements'], enabled, queryFn: () => api<{ data: Announcement[] }>('/me/announcements').then(r => r.data) })
export const useSaveAnnouncement = (id: number | null) => useMutation({
  mutationFn: (v: AnnInput & { image?: File | null; attachment?: File | null; submit: boolean }) => {
    const fd = new FormData()
    for (const [k, x] of Object.entries(v)) {
      if (k === 'image' || k === 'attachment') { if (x) fd.append(k, x as File); continue }
      if (k === 'submit') { fd.append(k, x ? '1' : '0'); continue }
      if (x !== '' && x !== null && x !== undefined) fd.append(k, String(x))
    }
    return api<{ data: Announcement }>(id ? `/hr/announcements/${id}` : '/hr/announcements', { method: 'POST', body: fd }).then(r => r.data)
  }, onSuccess: refreshAnn,
})
export const useDecideAnnouncement = () => useMutation({ mutationFn: (v: { id: number; approve: boolean; note?: string }) => api(`/hr/announcements/${v.id}/decide`, { json: { approve: v.approve, note: v.note } }), onSuccess: refreshAnn })
export const useArchiveAnnouncement = () => useMutation({ mutationFn: (id: number) => api(`/hr/announcements/${id}/archive`, { method: 'POST' }), onSuccess: refreshAnn })
export const useAnnSettings = () => useMutation({ mutationFn: (v: { require_approval: boolean; max_mb: number }) => api('/hr/announcements/settings', { method: 'PUT', json: v }), onSuccess: refreshAnn })
