import { useInfiniteQuery, useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'

/** «الساحة» (12a) — التواصل الاجتماعي الداخلي */
export type Reaction = 'like' | 'love' | 'celebrate' | 'insightful' | 'support' | 'funny'
export const REACTIONS: Record<Reaction, { emoji: string; ar: string; en: string }> = {
  like: { emoji: '👍', ar: 'إعجاب', en: 'Like' }, love: { emoji: '❤️', ar: 'حب', en: 'Love' }, celebrate: { emoji: '🎉', ar: 'احتفاء', en: 'Celebrate' },
  insightful: { emoji: '💡', ar: 'مفيد', en: 'Insightful' }, support: { emoji: '🤝', ar: 'دعم', en: 'Support' }, funny: { emoji: '😄', ar: 'طريف', en: 'Funny' },
}
export interface Person { id: number | null; name: Bi; title: Bi | null; gender: string | null; employee_id: number | null; system?: boolean }
export interface CommunityRow {
  id: number; kind: 'company' | 'department' | 'interest'; name: Bi; about: string | null; visibility: 'public' | 'private'; color: string; archived: boolean
  member: boolean; role: 'member' | 'admin' | null; members: number | null; posts: number; last_at: string | null
  can: { post: boolean; admin: boolean; join: boolean; leave: boolean }
}
export interface CommentRow { id: number; parent_id: number | null; body: string; author: Person; at: string; edited: boolean; reactions: number; liked: boolean; hidden: boolean; best?: boolean; can?: { edit: boolean; delete: boolean; report: boolean } }
export interface PostRow {
  id: number; kind: 'post' | 'poll' | 'question' | 'praise' | 'celebration'; body: string; at: string; edited: boolean; meta: PostMeta | null
  community: { id: number; kind: string; name: Bi; color: string }; author: Person; images: { id: number }[]
  poll: { multi: boolean; closes_at: string | null; closed: boolean; voters: number; options: { id: string; text: string; votes: number; mine: boolean }[] } | null
  reactions: { counts: Partial<Record<Reaction, number>>; mine: Reaction | null; total: number }
  comments_count: number; best: CommentRow | null; pinned: boolean; hidden: boolean; hidden_reason: string | null
  can: { edit: boolean; delete: boolean; pin: boolean; best: boolean; report: boolean; moderate: boolean; comment: boolean }
  comments?: CommentRow[]
}
/** 12b: شكر زميل والاحتفالات */
export interface PraiseValue { key: string; ar: string; en: string; color: string }
export interface MetaPerson { employee_id: number; user_id: number | null; name: Bi; title: Bi | null; gender: string | null; years?: number | null }
export interface PostMeta { value?: PraiseValue; recipients?: MetaPerson[]; points?: number; type?: 'birthday' | 'anniversary' | 'joiner' | 'promotion'; people?: MetaPerson[]; from?: string }
export interface PraiseInfo { enabled: boolean; values: PraiseValue[]; points: number; max_recipients: number; min_message: number; limit: number; left: number }
export interface PraiseTop { employee_id: number; name: Bi; title: Bi; gender: string | null; count: number }
export interface Colleague { id: number; name: Bi; title: Bi; dept: Bi; gender: string | null }
export interface PraiseSettings { enabled: boolean; values: PraiseValue[]; points: number; monthly_limit: number; min_message: number; max_recipients: number }
export interface CelebrationSettings { enabled: boolean; birthdays: boolean; anniversaries: boolean; new_joiners: boolean; promotions: boolean }
export interface SpaceMeta {
  settings: { enabled: boolean; reactions: Reaction[]; polls: boolean; questions: boolean; max_images: number; max_mb: number; create_communities: 'everyone' | 'moderators' }
  me: Person; can: { moderate: boolean; post: boolean; create_community: boolean; settings: boolean }
  praise: PraiseInfo
}
export type FeedPage = SpaceMeta & { data: PostRow[]; more: boolean; next: number | null; communities: CommunityRow[]; community?: CommunityRow; praise_top?: PraiseTop[] }
export type FeedFilter = 'all' | 'questions' | 'polls' | 'mine' | 'mentions' | 'unanswered' | 'praise' | 'celebrations'

const qs = (o: Record<string, string | number | undefined | null>) => new URLSearchParams(Object.entries(o).filter(([, v]) => v !== undefined && v !== null && v !== '').map(([k, v]) => [k, String(v)])).toString()
export const useFeed = (community: number | null, filter: FeedFilter) => useInfiniteQuery({
  queryKey: ['space', 'feed', community, filter], initialPageParam: null as number | null,
  queryFn: ({ pageParam }) => api<FeedPage>(`/space?${qs({ community, filter: filter === 'all' ? null : filter, before: pageParam })}`),
  getNextPageParam: last => last.more ? last.next : null,
})
export const usePost = (id: number) => useQuery({ queryKey: ['space', 'post', id], queryFn: () => api<SpaceMeta & { data: PostRow }>(`/space/posts/${id}`) })
export const usePeople = (q: string, on: boolean) => useQuery({ queryKey: ['space', 'people', q], enabled: on, queryFn: () => api<{ data: Person[] }>(`/space/people?${qs({ q })}`).then(r => r.data), staleTime: 60_000 })
export const useMembers = (id: number | null) => useQuery({ queryKey: ['space', 'members', id], enabled: !!id, queryFn: () => api<{ data: { user: Person; role: 'member' | 'admin' }[] }>(`/space/communities/${id}/members`).then(r => r.data) })
export const useReports = () => useQuery({ queryKey: ['space', 'moderation'], queryFn: () => api<{ data: ReportRow[] }>('/space/moderation').then(r => r.data) })
export interface ReportRow { target: 'post' | 'comment'; id: number; post_id: number; body: string; author: Person; community: string | null; hidden: boolean; at: string; reports: { reason: string; note: string | null; by: string; by_en: string; at: string }[] }
export interface SpaceSettingsData { enabled: boolean; create_communities: 'everyone' | 'moderators'; max_images: number; max_mb: number; edit_minutes: number; reactions: Reaction[]; polls: boolean; questions: boolean; auto_hide_reports: number }
export const useSpaceSettings = () => useQuery({ queryKey: ['space', 'settings'], queryFn: () => api<{ data: SpaceSettingsData; defaults: SpaceSettingsData; reactions: Reaction[]; praise: PraiseSettings; celebrations: CelebrationSettings; praise_defaults: PraiseSettings; celebrations_defaults: CelebrationSettings }>('/space/settings') })

const refresh = () => queryClient.invalidateQueries({ queryKey: ['space'] })
const m = <V,>(fn: (v: V) => Promise<unknown>) => () => useMutation({ mutationFn: fn, onSuccess: refresh })
export const useCreatePost = m((fd: FormData) => api<{ data: PostRow }>('/space/posts', { method: 'POST', body: fd }))
export const useUpdatePost = m((v: { id: number; body: string }) => api(`/space/posts/${v.id}`, { method: 'PUT', json: { body: v.body } }))
export const useDeletePost = m((id: number) => api(`/space/posts/${id}`, { method: 'DELETE' }))
export const usePin = m((v: { id: number; on: boolean }) => api(`/space/posts/${v.id}/pin`, { json: { on: v.on } }))
export const useReact = m((v: { target: 'post' | 'comment'; id: number; type: Reaction | null }) => api(`/space/${v.target}/${v.id}/react`, { json: { type: v.type } }))
export const useVote = m((v: { id: number; options: string[] }) => api(`/space/posts/${v.id}/vote`, { json: { options: v.options } }))
export const useComment = m((v: { id: number; body: string; parent_id?: number | null }) => api(`/space/posts/${v.id}/comments`, { json: { body: v.body, parent_id: v.parent_id ?? null } }))
export const useUpdateComment = m((v: { id: number; body: string }) => api(`/space/comments/${v.id}`, { method: 'PUT', json: { body: v.body } }))
export const useDeleteComment = m((id: number) => api(`/space/comments/${id}`, { method: 'DELETE' }))
export const useBest = m((v: { id: number; comment_id: number | null }) => api(`/space/posts/${v.id}/best`, { json: { comment_id: v.comment_id } }))
export const useReport = m((v: { target: 'post' | 'comment'; id: number; reason: string; note?: string }) => api<{ message: string }>(`/space/${v.target}/${v.id}/report`, { json: { reason: v.reason, note: v.note } }))
export const useSaveCommunity = m((v: { id?: number; name_ar: string; name_en?: string; about?: string; visibility: 'public' | 'private'; color: string }) =>
  api<{ data: CommunityRow }>(v.id ? `/space/communities/${v.id}` : '/space/communities', { method: v.id ? 'PUT' : 'POST', json: v }))
export const useJoin = m((v: { id: number; leave?: boolean }) => api(`/space/communities/${v.id}/${v.leave ? 'leave' : 'join'}`, { json: {} }))
export const useArchiveCommunity = m((v: { id: number; restore?: boolean }) => api(`/space/communities/${v.id}/archive`, { json: { restore: !!v.restore } }))
export const useSetMember = m((v: { id: number; user_id: number; role: 'member' | 'admin' | null }) => api(`/space/communities/${v.id}/members`, { json: { user_id: v.user_id, role: v.role } }))
export const useDecideReport = m((v: { target: 'post' | 'comment'; id: number; action: 'keep' | 'remove'; note?: string }) => api(`/space/moderation/${v.target}/${v.id}`, { json: { action: v.action, note: v.note } }))
export const useSaveSpaceSettings = m((v: SpaceSettingsData) => api('/space/settings', { method: 'PUT', json: v }))
export const useColleagues = (q: string) => useQuery({ queryKey: ['space', 'colleagues', q], queryFn: () => api<{ data: Colleague[] }>(`/space/colleagues?${qs({ q })}`).then(r => r.data), staleTime: 60_000 })
export const useGivePraise = m((v: { recipients: number[]; value: string; message: string }) => api<{ data: PostRow; praise: PraiseInfo }>('/space/praise', { json: v }))
export const useSavePraiseSettings = m((v: { praise: PraiseSettings; celebrations: CelebrationSettings }) => api('/space/praise-settings', { method: 'PUT', json: v }))

/** النص مع الإشارات: @[الاسم](رقم) */
export type Chunk = { t: 'text'; v: string } | { t: 'mention'; name: string; id: number } | { t: 'link'; v: string }
export function parseBody(s: string): Chunk[] {
  const out: Chunk[] = []
  const re = /@\[([^\]\n]{1,80})\]\((\d{1,10})\)|(https?:\/\/[^\s<>()]+)/gu
  let last = 0
  for (const m of s.matchAll(re)) {
    if (m.index! > last) out.push({ t: 'text', v: s.slice(last, m.index) })
    out.push(m[3] ? { t: 'link', v: m[3] } : { t: 'mention', name: m[1], id: Number(m[2]) })
    last = m.index! + m[0].length
  }
  if (last < s.length) out.push({ t: 'text', v: s.slice(last) })
  return out
}
/** «منذ 5 د» */
export function ago(iso: string, ar: boolean): string {
  const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000)
  if (s < 60) return ar ? 'الآن' : 'now'
  const m = Math.floor(s / 60); if (m < 60) return ar ? `منذ ${m} د` : `${m}m`
  const h = Math.floor(m / 60); if (h < 24) return ar ? `منذ ${h} س` : `${h}h`
  const d = Math.floor(h / 24); if (d < 7) return ar ? `منذ ${d} يوم` : `${d}d`
  return new Date(iso).toLocaleDateString(ar ? 'ar-SY-u-nu-latn' : 'en-GB', { day: 'numeric', month: 'short' })
}
