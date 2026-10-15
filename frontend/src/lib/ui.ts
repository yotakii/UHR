import { useSyncExternalStore } from 'react'
type UI = { showcase: boolean; palette: boolean }
let ui: UI = { showcase: false, palette: false }
const ls = new Set<() => void>()
export const setUI = (p: Partial<UI>) => { ui = { ...ui, ...p }; ls.forEach(l => l()) }
export const useUI = () => useSyncExternalStore(cb => { ls.add(cb); return () => ls.delete(cb) }, () => ui)
export function toggleFullscreen() {
  if (document.fullscreenElement) document.exitFullscreen?.(); else document.documentElement.requestFullscreen?.().catch(() => {})
}
