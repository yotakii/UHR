import { api } from './api'
import { navigate } from './router'

/**
 * تطبيق الجوال (Android): الصفحات نفسها تُفتح داخل التطبيق من الخادم (/m)، والتطبيق يضيف جسراً أصلياً (Capacitor) للإشعارات.
 * لا نضمّن مكتبات Capacitor في الموقع — نستخدم الجسر الذي يحقنه التطبيق إن وُجد، وفي المتصفح العادي لا شيء يتغير.
 */
type Listener = { remove: () => Promise<void> }
interface PushPlugin {
  checkPermissions(): Promise<{ receive: string }>
  requestPermissions(): Promise<{ receive: string }>
  register(): Promise<void>
  createChannel?(c: { id: string; name: string; importance: number; sound?: string; vibration?: boolean }): Promise<void>
  addListener(e: 'registration', f: (t: { value: string }) => void): Promise<Listener>
  addListener(e: 'registrationError', f: (err: unknown) => void): Promise<Listener>
  addListener(e: 'pushNotificationActionPerformed', f: (a: { notification: { data?: Record<string, string> } }) => void): Promise<Listener>
  addListener(e: 'pushNotificationReceived', f: (n: { title?: string; body?: string; data?: Record<string, string> }) => void): Promise<Listener>
  removeAllListeners(): Promise<void>
}
interface CapacitorGlobal { isNativePlatform?: () => boolean; getPlatform?: () => string; Plugins?: { PushNotifications?: PushPlugin; App?: { getInfo?: () => Promise<{ version: string }> } } }
const cap = () => (window as unknown as { Capacitor?: CapacitorGlobal }).Capacitor
export const isApp = () => !!cap()?.isNativePlatform?.()

const KEY = 'uhr-push-token'

/** رابط الإشعار (requests، approvals، payslip…) ← صفحة في تطبيق الجوال، أو null إن لم تكن فيه */
export function mobileLink(link: string | null | undefined): string | null {
  if (!link) return null
  const k = link.split(':')[0]
  const map: Record<string, string> = { requests: '/m/requests', approvals: '/m/approvals', payslip: '/m/pay', services: '/m/requests', leaves: '/m/requests', justify: '/m/requests' }
  return map[k] ?? null
}

let started = false
/** يطلب إذن الإشعارات ويسجّل الجهاز للمستخدم الحالي (مرة لكل تشغيل) */
export async function startPush(onForeground?: (text: string) => void) {
  const p = cap()?.Plugins?.PushNotifications
  if (!isApp() || !p || started) return
  started = true
  try {
    // الإشعارات خارج التطبيق تعمل فقط بعد إضافة مفتاح Firebase في لوحة المنصة
    if (!(await api<{ enabled: boolean }>('/me/push')).enabled) { started = false; return }
    await p.removeAllListeners()
    await p.createChannel?.({ id: 'uhr', name: 'UHR', importance: 4, sound: 'default', vibration: true }).catch(() => {})
    await p.addListener('registration', t => {
      try { localStorage.setItem(KEY, t.value) } catch { /* اختياري */ }
      const ver = cap()?.Plugins?.App?.getInfo?.().then(i => i.version).catch(() => undefined) ?? Promise.resolve(undefined)
      void ver.then(v => api('/me/push', { json: { token: t.value, platform: cap()?.getPlatform?.() === 'ios' ? 'ios' : 'android', app_version: v } }).catch(() => {}))
    })
    await p.addListener('pushNotificationActionPerformed', a => { const to = mobileLink(a.notification.data?.link); navigate(to ?? '/m/notices') })
    await p.addListener('pushNotificationReceived', n => { if (n.body) onForeground?.(n.body) })
    let perm = await p.checkPermissions()
    if (perm.receive !== 'granted') perm = await p.requestPermissions()
    if (perm.receive === 'granted') await p.register()
  } catch { started = false }
}

/** عند الخروج: يُلغى ربط الجهاز بالحساب حتى لا تصل إشعاراته لمن يدخل بعده */
export async function stopPush() {
  started = false
  let t: string | null = null
  try { t = localStorage.getItem(KEY) } catch { /* */ }
  if (t) await api('/me/push', { method: 'DELETE', json: { token: t } }).catch(() => {})
}
