import { useMutation, useQuery } from '@tanstack/react-query'
import { useSyncExternalStore } from 'react'
import { api, ApiError, xsrf } from './api'
import { getPrefs } from './store'
import { queryClient } from './session'
import type { Bi } from './types'

/** «المساعد» (المهمة 13) */
export type Tone = 'brand' | 'brass' | 'info' | 'danger' | 'neutral'
export type Card =
  | { type: 'stats'; items: { label: string; value: number | string; sub?: string | null; tone?: Tone }[] }
  | { type: 'list'; title?: string; items: { title: string; sub?: string | null; badge?: { label: string; tone: Tone } | null }[] }
  | { type: 'link'; label: string; to: string }
export interface Source { kind: 'policy' | 'guide'; title: Bi; sub: string | null; excerpt: string; to: string | null }
export type ActionKind = 'leave' | 'justify' | 'certificate' | 'praise'
export interface Action {
  kind: ActionKind; status: 'pending' | 'done' | 'cancelled' | 'expired'; fields: Record<string, unknown>; errors: Record<string, string>; warnings: string[]
  info: Record<string, unknown>; digest: string; expires_at: string; result: { text: string; to: string } | null
}
export interface Msg {
  id: number; role: 'user' | 'assistant'; intent: string | null; text: string; at: string
  cards: Card[]; sources: Source[]; suggestions: string[]; action: Action | null; summary: 'writing' | 'done' | 'failed' | null
}
export interface AssistantState { enabled: boolean; summaries: boolean; suggestions: string[]; history: Msg[]; limit: { used: number; max: number } }

export const useAssistant = (on: boolean) => useQuery({ queryKey: ['assistant'], enabled: on, queryFn: () => api<AssistantState>('/assistant'), staleTime: 30_000 })

export type AskEvent = { t: 'reply'; message: Msg } | { t: 'delta'; s: string } | { t: 'done'; message: Msg } | { t: 'error'; message: string }

/** السؤال: يقرأ الرد سطراً سطراً (NDJSON) فتظهر الكلمات فور وصولها */
export async function ask(text: string, onEvent: (e: AskEvent) => void): Promise<void> {
  if (!xsrf()) await fetch('/api/auth/me', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
  const res = await fetch('/api/assistant/ask', {
    method: 'POST', credentials: 'same-origin', body: JSON.stringify({ text }),
    headers: { Accept: 'application/x-ndjson, application/json', 'Content-Type': 'application/json', 'Accept-Language': getPrefs().lang, 'X-XSRF-TOKEN': xsrf() },
  })
  if (!res.ok || !res.body) {
    const b = await res.json().catch(() => null) as { message?: string; code?: string; errors?: Record<string, string[]> } | null
    throw new ApiError(res.status, b?.message ?? (getPrefs().lang === 'en' ? 'Could not send the question.' : 'تعذّر إرسال السؤال.'), b?.code, b?.errors ?? {})
  }
  const reader = res.body.getReader(); const dec = new TextDecoder()
  let buf = ''
  for (;;) {
    const { value, done } = await reader.read()
    if (done) break
    buf += dec.decode(value, { stream: true })
    let i: number
    while ((i = buf.indexOf('\n')) >= 0) {
      const line = buf.slice(0, i).trim(); buf = buf.slice(i + 1)
      if (line) { try { onEvent(JSON.parse(line) as AskEvent) } catch { /* سطر ناقص */ } }
    }
  }
  if (buf.trim()) { try { onEvent(JSON.parse(buf) as AskEvent) } catch { /* */ } }
}

export const useActionDecision = () => useMutation({
  mutationFn: (v: { id: number; decision: 'preview' | 'confirm' | 'cancel'; fields?: Record<string, unknown>; digest?: string }) =>
    api<{ message: Msg }>(`/assistant/messages/${v.id}/action`, { json: { decision: v.decision, fields: v.fields, digest: v.digest } }).then(r => r.message),
})
export const useClearAssistant = () => useMutation({ mutationFn: () => api('/assistant', { method: 'DELETE' }), onSuccess: () => queryClient.invalidateQueries({ queryKey: ['assistant'] }) })

export interface AssistantSettings { enabled: boolean; summaries: boolean; actions: Record<ActionKind, boolean>; daily_limit: number; history_days: number }
export const useAssistantSettings = () => useQuery({ queryKey: ['assistant', 'settings'], queryFn: () => api<{ data: AssistantSettings; defaults: AssistantSettings; summaries_ready: boolean;
  usage: { today: number; month: number; users: number; actions: number; unanswered: number } }>('/assistant/settings') })
export const useSaveAssistantSettings = () => useMutation({ mutationFn: (v: AssistantSettings) => api('/assistant/settings', { method: 'PUT', json: v }),
  onSuccess: () => queryClient.invalidateQueries({ queryKey: ['assistant'] }) })

/** فتح اللوحة من أي مكان (مع سؤال جاهز اختيارياً) */
let state: { open: boolean; draft: string | null } = { open: false, draft: null }
const subs = new Set<() => void>()
const emit = () => subs.forEach(f => f())
export function openAssistant(draft?: string) { state = { open: true, draft: draft ?? null }; emit() }
export function closeAssistant() { state = { open: false, draft: null }; emit() }
export function takeDraft() { const d = state.draft; if (d) { state = { ...state, draft: null } } return d }
export const useAssistantOpen = () => useSyncExternalStore(f => { subs.add(f); return () => subs.delete(f) }, () => state.open)
