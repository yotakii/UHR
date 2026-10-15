import { useEffect } from 'react'
import { useUI, setUI } from './lib/ui'
import { Showcase } from './screens/Showcase'
import { useStore, useL } from './lib/store'
import { NavProvider, useNav } from './lib/nav'
import { Toaster } from './ui/kit'
import { Public } from './screens/Public'
import { Terminal } from './screens/Terminal'
import { CandidateHome } from './screens/CandidateHome'
import { Shell, useHrNav, useEmpNav } from './screens/Shell'
import { Dashboard } from './screens/Dashboard'
import { Recruitment, CandidateFile } from './screens/Recruitment'
import { OnboardingList, OnboardPage } from './screens/Onboarding'
import { Employees, EmployeeFile } from './screens/Employees'
import { Attendance, Leaves, Custody, Policies, Jobs } from './screens/Ops'
import { Offboarding } from './screens/Offboarding'
import { ReferralsHR, MyRewards } from './screens/Rewards'
import { OrgChart, Performance, Analytics } from './screens/Insights'
import { PortalShell } from './screens/Portal'
import { EmpHome, MyAttendance, MyRequests, MyDocs, MyResign } from './screens/Self'

function HR() {
  const L = useL(); const { route, go } = useNav(); const items = useHrNav(); const lang = useStore(s => s.lang)
  const me = useStore(s => s.employees.find(e => e.id === s.session?.id))
  const p = route.page
  return <Shell items={items} role="hr" user={{ name: (lang === 'ar' ? me?.name : me?.nameEn) ?? 'HR', title: L('مديرة الموارد البشرية', 'HR Director') }}>
    {p === 'jobs' ? <Jobs /> : p === 'recruitment' ? <Recruitment /> : p === 'candidate' ? <CandidateFile key={route.id} id={route.id!} />
      : p === 'onboarding' ? <OnboardingList /> : p === 'onboard' ? <OnboardPage key={route.id} id={route.id!} />
      : p === 'employees' ? <Employees /> : p === 'employee' ? <EmployeeFile key={route.id} id={route.id!} />
      : p === 'attendance' ? <Attendance /> : p === 'kiosk-sim' ? <Terminal onBack={() => go('attendance')} /> : p === 'leaves' ? <Leaves /> : p === 'custody' ? <Custody />
      : p === 'org' ? <OrgChart /> : p === 'performance' ? <Performance /> : p === 'analytics' ? <Analytics /> : p === 'rewards' ? <ReferralsHR /> : p === 'offboarding' ? <Offboarding /> : p === 'policies' ? <Policies /> : <Dashboard />}
  </Shell>
}

function Emp() { return <PortalShell /> }

export default function App() {
  const lang = useStore(s => s.lang)
  const session = useStore(s => s.session)
  const theme = useStore(s => s.theme ?? 'slate')
  const { showcase } = useUI()
  useEffect(() => { document.documentElement.lang = lang; document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr' }, [lang])
  useEffect(() => { document.documentElement.classList.toggle('dark', theme !== 'light'); document.documentElement.classList.toggle('slate', theme === 'slate') }, [theme])
  const key = session ? `${session.role}:${session.id}` : 'public'
  return <>
    {!session ? <NavProvider key={key} initial="login"><Public /></NavProvider>
      : session.role === 'candidate' ? <NavProvider key={key} initial="home"><CandidateHome /></NavProvider>
      : session.role === 'employee' ? <NavProvider key={key} initial="home"><Emp /></NavProvider>
      : <NavProvider key={key} initial="dashboard"><HR /></NavProvider>}
    {showcase && <Showcase />}
    <Toaster />
  </>
}
