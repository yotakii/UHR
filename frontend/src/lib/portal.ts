import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi, LogEvent } from './types'

/** M14 بوابة الموظف: الرئيسية، التسجيل من الجوال، فريقي، ملفي الشخصي */
export interface TeamRow { id: number; name: Bi; title: Bi; me: boolean; state: 'in' | 'out' | 'leave' | 'none'; since: string | null; phone: string | null }
export interface Team { dept: Bi | null; rows: TeamRow[]; away: number; total: number }
export interface PortalHome {
  me: { id: number; code: string; name: Bi; title: Bi; dept: (Bi & { id: number }) | null; status: string; hire_date: string | null; points: number; manager: Bi | null; probation_end: string | null; tenure_days: number; resigned: boolean; onboarding: boolean }
  today: { date: string; in: string | null; out: string | null; late: number; status: string; checkin: boolean }
  geo: { lat: number; lng: number; radius: number } | null
  balance: { annual: { left: number; of: number; used: number }; sick: { used: number; of: number | null }; points: number }
  recent: { kind: 'leave' | 'service'; id: number; type: string; title: Bi | null; date: string | null; status: string; at: string }[]
  team: Team
}
export interface MyFile {
  me: { id: number; code: string; name: Bi; title: Bi; dept: Bi | null; email: string | null; phone: string | null; national_id: string | null; hire_date: string | null; location: Bi | null; card: string | null; status: string; manager: Bi | null }
  log: LogEvent[]
}

export const useHome = () => useQuery({ queryKey: ['me', 'home'], queryFn: () => api<PortalHome>('/me/home'), refetchInterval: 60_000 })
export const useCheckIn = () => useMutation({
  mutationFn: (v: { lat?: number; lng?: number }) => api<PortalHome & { result: { type: 'in' | 'out'; time: string; late: number } }>('/me/checkin', { json: v }),
  onSuccess: d => { queryClient.setQueryData(['me', 'home'], d); queryClient.invalidateQueries({ queryKey: ['me', 'attendance'] }); queryClient.invalidateQueries({ queryKey: ['me', 'team'] }) },
})
export const useTeam = () => useQuery({ queryKey: ['me', 'team'], queryFn: () => api<Team>('/me/team'), refetchInterval: 60_000 })
export const useMyFile = () => useQuery({ queryKey: ['me', 'file'], queryFn: () => api<MyFile>('/me/my-file') })
export const useSavePhone = () => useMutation({ mutationFn: (phone: string) => api<MyFile>('/me/my-file/phone', { method: 'PUT', json: { phone } }), onSuccess: d => { queryClient.setQueryData(['me', 'file'], d) } })

/** الموقع الحالي (للتسجيل ضمن النطاق الجغرافي) */
export const position = () => new Promise<{ lat: number; lng: number }>((ok, no) => {
  if (!navigator.geolocation) return no(new Error('geo'))
  navigator.geolocation.getCurrentPosition(p => ok({ lat: p.coords.latitude, lng: p.coords.longitude }), no, { enableHighAccuracy: true, timeout: 12000 })
})
