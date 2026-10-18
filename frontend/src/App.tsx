import { useEffect } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { useLang, useTheme, applyDocument, useL } from './lib/store'
import { useRoute } from './lib/router'
import { queryClient, useMe } from './lib/session'
import { Btn, Toaster, Empty } from './ui/kit'
import { Logo } from './ui/brand'
import { Public, PUBLIC_PAGES } from './screens/public/Public'
import { HrApp } from './screens/hr/HrApp'
import { PlatformApp } from './screens/platform/PlatformApp'
import { ForceChangePassword, PortalHome } from './screens/Account'
import SystemStatus from './screens/SystemStatus'
import { DevKit } from './screens/DevKit'
import { CandidateHome } from './screens/candidate/CandidateHome'
import { EmployeeApp } from './screens/employee/EmployeeApp'

function Splash() {
  return <div className="min-h-full grid place-items-center app-glow"><div className="flex flex-col items-center gap-4"><Logo h={34} /><Loader2 className="animate-spin text-brand" size={22} /></div></div>
}

function Root() {
  const L = useL(); const route = useRoute()
  const me = useMe()
  // صفحات المطوّرين — بيئة التطوير فقط
  if (import.meta.env.DEV && route.page === 'kit') return <DevKit />
  if (route.page === 'status') return <SystemStatus />
  if (me.isLoading) return <Splash />
  if (me.isError) return <div className="min-h-full grid place-items-center app-glow"><div className="bg-surface border border-line rounded-2xl"><Empty title={L('تعذّر الاتصال بالخادم', 'Cannot reach the server')} action={<Btn onClick={() => me.refetch()}>{L('إعادة المحاولة', 'Retry')}</Btn>}>{L('تحقق من الشبكة أو من حالة النظام.', 'Check your connection or the system status.')} <a className="underline" href="#/status">#/status</a></Empty></div></div>
  const user = me.data
  if (!user) return <Public />
  if (user.must_change_password) return <ForceChangePassword user={user} />
  // صفحات البوابة العامة متاحة للجميع؛ الصفحة الرئيسية لكل حساب = واجهته
  if (route.page && PUBLIC_PAGES.includes(route.page) && route.page !== 'login') return <Public />
  if (user.portal === 'platform') return <PlatformApp user={user} />
  if (user.portal === 'hr') return <HrApp user={user} />
  if (user.portal === 'candidate') return <CandidateHome user={user} />
  if (user.portal === 'employee') return <EmployeeApp user={user} />
  return <PortalHome user={user} />
}

export default function App() {
  const lang = useLang(); const theme = useTheme()
  useEffect(() => applyDocument(), [lang, theme])
  return <QueryClientProvider client={queryClient}>
    <Root />
    <Toaster />
  </QueryClientProvider>
}
