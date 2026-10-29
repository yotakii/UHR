import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { api } from './api'
import { queryClient } from './session'

// التحديث اللحظي (Reverb، SRS §8): اتصال واحد بعد الدخول على نفس الأصل (/app)، قناة المستخدم + قناة الشركة لنظام الموارد البشرية.
// «uhr.changed» = تغيّرت جداول في الشركة ← نعيد تحميل الاستعلامات المعروضة فقط (ودمج TanStack البنيوي لا يغيّر ما لم يتغير)،
// «uhr.notice» = إشعار جديد ← الجرس فوراً. إن تعذّر الاتصال يبقى التحديث الدوري كما كان.

type Config = { enabled: boolean; key: string | null; channels: string[] }
let echo: Echo<'reverb'> | null = null
let userId: number | null = null
let timer: number | undefined

/** استعلامات لا تُعاد تلقائياً: حالة التحقق، الطباعة، الاختبار العام، جهاز الحضور */
const SKIP = new Set(['mfa', 'mfa-link', 'mfa-grants', 'print', 'public-test', 'device', 'certificate'])

export function refreshActive(delay = 700) {
  window.clearTimeout(timer)
  timer = window.setTimeout(() => void queryClient.invalidateQueries({ refetchType: 'active', predicate: q => !SKIP.has(String(q.queryKey[0])) }), delay)
}

export async function startRealtime(id: number) {
  if (userId === id && echo) return
  stopRealtime()
  userId = id
  let cfg: Config
  try { cfg = await api<Config>('/realtime') } catch { return }
  if (!cfg.enabled || !cfg.key || userId !== id) return
  const https = location.protocol === 'https:'
  const port = Number(location.port) || (https ? 443 : 80)
  ;(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher
  echo = new Echo({
    broadcaster: 'reverb', key: cfg.key, wsHost: location.hostname, wsPort: port, wssPort: port, forceTLS: https, enabledTransports: ['ws', 'wss'],
    // مصادقة القنوات الخاصة بالجلسة + CSRF (نفس عميل الـ API)
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, cb: (error: Error | null, data: { auth: string } | null) => void) => {
        api<{ auth: string }>('/broadcasting/auth', { json: { socket_id: socketId, channel_name: channel.name } }).then(d => cb(null, d)).catch(e => cb(e as Error, null))
      },
    }),
  })
  for (const ch of cfg.channels) {
    echo.private(ch)
      .listen('.uhr.changed', () => refreshActive())
      .listen('.uhr.notice', () => {
        void queryClient.invalidateQueries({ queryKey: ['notices'] })
        // الموظف لا يشترك بقناة الشركة: الإشعار يرافق غالباً تغييراً يخصه
        if (ch.startsWith('user.')) refreshActive(400)
      })
  }
}

export function stopRealtime() {
  try { echo?.disconnect() } catch { /* */ }
  echo = null; userId = null
}
