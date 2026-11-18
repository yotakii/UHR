import { useMutation, useQuery } from '@tanstack/react-query'
import { api, xsrf } from './api'
import { getPrefs } from './store'
import { queryClient } from './session'
import type { Bi } from './types'

/** وكلاء أجهزة الموظفين UHR-Agent (D-15) */
export type AgentAction = 'lock' | 'logoff' | 'shutdown'
export interface PcAgentRow {
  id: number; name: string; active: boolean; online: boolean; paired: boolean; key_hint: string | null; host: string | null; os_user: string | null; version: string | null
  last_ip: string | null; last_seen_at: string | null; employee: (Bi & { id: number; code: string }) | null; queued: number
  last: { id: number; action: AgentAction; reason: string; status: string; error: string | null; at: string; done_at: string | null } | null
}
export interface AgentSettings { enabled: boolean; action: AgentAction; warn_seconds: number; postpone_minutes: number; sources: string[]; fresh_minutes: number; queue_hours: number; offline_minutes: number; poll_seconds: number; notify_employee: boolean }
export interface AgentCmd { id: number; action: AgentAction; reason: string; source: string | null; status: string; error: string | null; at: string; sent_at: string | null; done_at: string | null }

type List = { data: PcAgentRow[]; settings: AgentSettings; defaults: AgentSettings; can: { edit: boolean; settings: boolean } }
const changed = () => queryClient.invalidateQueries({ queryKey: ['pc-agents'] })
export const usePcAgents = (employee?: number) => useQuery({ queryKey: ['pc-agents', employee ?? 'all'], refetchInterval: 15000, queryFn: () => api<List>(`/hr/pc-agents${employee ? `?employee=${employee}` : ''}`) })
export const useAgentCommands = (id: number | null) => useQuery({ queryKey: ['pc-agents', 'cmd', id], enabled: !!id, queryFn: () => api<{ data: AgentCmd[] }>(`/hr/pc-agents/${id}/commands`).then(r => r.data) })
export const useAddAgent = () => useMutation({ mutationFn: (v: { employee_id: number; name: string }) => api<{ data: PcAgentRow }>('/hr/pc-agents', { json: v }), onSuccess: changed })
export const useUpdateAgent = () => useMutation({ mutationFn: (v: { id: number; name: string; active: boolean }) => api(`/hr/pc-agents/${v.id}`, { method: 'PUT', json: { name: v.name, active: v.active } }), onSuccess: changed })
export const useTestAgent = () => useMutation({ mutationFn: (v: { id: number; action: AgentAction }) => api(`/hr/pc-agents/${v.id}/test`, { json: { action: v.action } }), onSuccess: changed })
export const useSaveAgentSettings = () => useMutation({ mutationFn: (v: AgentSettings) => api('/hr/pc-agents/settings', { method: 'PUT', json: v }), onSuccess: changed })

/** الحزمة: zip بالوكيل ومفتاح جديد (يوقف أي نسخة سابقة على الجهاز) */
export async function downloadAgent(id: number) {
  const res = await fetch(`/api/hr/pc-agents/${id}/package`, { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': xsrf(), Accept: 'application/zip', 'Accept-Language': getPrefs().lang } })
  if (!res.ok) throw new Error(`HTTP ${res.status}`)
  const url = URL.createObjectURL(await res.blob())
  const a = document.createElement('a'); a.href = url; a.download = 'UHR-Agent.zip'; document.body.appendChild(a); a.click(); a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 5000)
  changed()
}

export const ACTION: Record<AgentAction, [string, string]> = { lock: ['قفل الجهاز', 'Lock the computer'], logoff: ['تسجيل الخروج من Windows', 'Sign out of Windows'], shutdown: ['إيقاف التشغيل', 'Shut down'] }
export const CMD_STATUS: Record<string, [string, string, 'brand' | 'brass' | 'info' | 'danger' | 'neutral' | 'warn']> = {
  queued: ['بالانتظار', 'Queued', 'brass'], sent: ['أُرسل للجهاز', 'Sent', 'info'], done: ['نُفّذ', 'Done', 'brand'], failed: ['فشل', 'Failed', 'danger'], expired: ['انتهت مدته', 'Expired', 'neutral'], cancelled: ['أُلغي', 'Cancelled', 'neutral'],
}
export const REASON: Record<string, [string, string]> = { checkout: ['تسجيل خروج', 'Check-out'], test: ['تجربة', 'Test'], manual: ['يدوي', 'Manual'] }
export const SOURCE: Record<string, [string, string]> = { card: ['بطاقة الحضور', 'Attendance card'], fingerprint: ['البصمة', 'Fingerprint'], phone: ['الجوال والملصق', 'Phone & sticker'] }
