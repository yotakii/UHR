import { useEffect, useRef } from 'react'

/**
 * قارئ البطاقات (useCardReader في الديمو): قارئ USB يعمل كلوحة مفاتيح (أو UHR-NFC-Bridge بوضع الكتابة) يكتب الرقم بسرعة ثم Enter.
 * يُميَّز عن الكتابة اليدوية بسرعة الضغطات، ويستخدم رمز المفتاح الفيزيائي (e.code) فيعمل حتى ولوحة المفاتيح عربية.
 */
const AR_DIGITS = '٠١٢٣٤٥٦٧٨٩'
function keyChar(e: KeyboardEvent): string | null {
  let m = /^(?:Digit|Numpad)(\d)$/.exec(e.code); if (m) return m[1]
  m = /^Key([A-Z])$/.exec(e.code); if (m) return m[1]
  if (e.key.length === 1) { const i = AR_DIGITS.indexOf(e.key); if (i >= 0) return String(i); if (/[0-9a-zA-Z:]/.test(e.key)) return e.key }
  return null
}

export function useCardReader(onCard: (raw: string, via: 'usb' | 'phone') => void, enabled = true, onSignal?: (buf: string) => void) {
  const buf = useRef(''); const start = useRef(0); const last = useRef(0); const timer = useRef<number>(0)
  const cb = useRef(onCard); cb.current = onCard; const sig = useRef(onSignal); sig.current = onSignal
  useEffect(() => {
    if (!enabled) return
    const isReader = () => buf.current.length >= 4 && (last.current - start.current) / Math.max(1, buf.current.length - 1) < 75
    const flush = () => { const v = buf.current; buf.current = ''; if (v) cb.current(v, 'usb') }
    const down = (e: KeyboardEvent) => {
      const now = performance.now()
      if (e.key === 'Enter' || e.code === 'NumpadEnter' || e.key === 'Tab') {
        if (isReader()) { e.preventDefault(); e.stopPropagation(); clearTimeout(timer.current); flush() } else buf.current = ''
        return
      }
      const ch = keyChar(e); if (!ch) return
      if (!buf.current || now - last.current > 120) { buf.current = ''; start.current = now }
      else if (now - last.current < 60) { const t = e.target as HTMLElement; if (!(t instanceof HTMLInputElement && t.dataset.manual)) e.preventDefault() }
      buf.current += ch; last.current = now
      if (buf.current.length >= 3) sig.current?.(buf.current)
      clearTimeout(timer.current); timer.current = window.setTimeout(() => { if (buf.current.length >= 6 && isReader()) flush(); else buf.current = '' }, 220)
    }
    window.addEventListener('keydown', down, true)
    return () => { window.removeEventListener('keydown', down, true); clearTimeout(timer.current) }
  }, [enabled])
}

type NDEF = { scan: () => Promise<void>; addEventListener: (t: string, f: (e: { serialNumber: string }) => void) => void }
export const hasWebNfc = () => typeof window !== 'undefined' && 'NDEFReader' in window
/** Web NFC (Chrome أندرويد / Sunmi) — يتطلب https وإذن المستخدم */
export async function startWebNfc(onCard: (uid: string) => void): Promise<void> {
  const R = (window as unknown as { NDEFReader: new () => NDEF }).NDEFReader
  const r = new R(); await r.scan()
  r.addEventListener('reading', e => onCard(e.serialNumber))
}

/** بيانات القراءة للتشخيص (analyse في الديمو) */
export const normUid = (raw: string) => raw.replace(/[^0-9a-zA-Z]/g, '').toUpperCase()
export function analyse(raw: string) {
  const n = normUid(raw)
  const isDec = /^\d+$/.test(n)
  let hex = n
  if (isDec) { try { hex = BigInt(n).toString(16).toUpperCase(); if (hex.length % 2) hex = '0' + hex } catch { /* ignore */ } }
  const bytes = hex.match(/.{1,2}/g) ?? []
  return { raw, norm: n, format: isDec ? 'DEC' : 'HEX', hex: bytes.join(':'), reversed: [...bytes].reverse().join(':'), bytes: bytes.length, bits: bytes.length * 8 }
}

export function beep(ok: boolean) {
  try { const C = new AudioContext(); const o = C.createOscillator(); const g = C.createGain(); o.connect(g); g.connect(C.destination)
    o.frequency.value = ok ? 1320 : 330; g.gain.setValueAtTime(.18, C.currentTime); g.gain.exponentialRampToValueAtTime(.001, C.currentTime + (ok ? .18 : .45)); o.start(); o.stop(C.currentTime + (ok ? .2 : .5))
    if (ok) { const o2 = C.createOscillator(); const g2 = C.createGain(); o2.connect(g2); g2.connect(C.destination); o2.frequency.value = 1760; g2.gain.setValueAtTime(.14, C.currentTime + .12); g2.gain.exponentialRampToValueAtTime(.001, C.currentTime + .32); o2.start(C.currentTime + .12); o2.stop(C.currentTime + .34) }
  } catch { /* no audio */ }
}
