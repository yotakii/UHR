import { Moon, Sun, Layers } from 'lucide-react'
import { useLang, useTheme, setLang, setTheme } from '../lib/store'
import logo from '../assets/logo.png'
import logoDark from '../assets/logo-dark.png'

/** الشعار: نسخة بيضاء على الثيمات الداكنة، وكبسولة بيضاء على خلفيات العرض */
export function Logo({ h = 30, onDark, pill }: { h?: number; onDark?: boolean; pill?: boolean }) {
  const theme = useTheme()
  if (pill) return <span className="inline-flex items-center rounded-xl bg-white px-3 py-2"><img src={logo} alt="UHR" style={{ height: h }} /></span>
  const dark = onDark ?? theme !== 'light'
  return <img src={dark ? logoDark : logo} alt="UHR" style={{ height: h }} />
}

export function LangToggle({ onDark }: { onDark?: boolean }) {
  const lang = useLang()
  return <div className={`inline-flex rounded-lg border p-0.5 ${onDark ? 'border-show-fg/20' : 'border-line bg-surface'}`}>
    {(['en', 'ar'] as const).map(l => <button key={l} onClick={() => setLang(l)} className={`h-7 min-w-9 px-2 rounded-md text-[12px] font-bold ${lang === l ? 'bg-brand text-on-brand' : onDark ? 'text-show-fg/70 hover:text-show-fg' : 'text-ink-3 hover:text-ink'}`}>{l.toUpperCase()}</button>)}
  </div>
}

export function ThemeToggle() {
  const theme = useTheme()
  const opts: { v: 'slate' | 'dark' | 'light'; icon: React.ReactNode; t: string }[] = [{ v: 'slate', icon: <Layers size={15} />, t: 'Slate' }, { v: 'dark', icon: <Moon size={15} />, t: 'UHR' }, { v: 'light', icon: <Sun size={15} />, t: 'Light' }]
  return <div className="inline-flex rounded-lg border border-line bg-surface p-0.5">{opts.map(o => <button key={o.v} title={o.t} aria-label={o.t} onClick={() => setTheme(o.v)} className={`h-7 w-8 grid place-items-center rounded-md ${theme === o.v ? 'bg-brand text-on-brand' : 'text-ink-3 hover:text-ink'}`}>{o.icon}</button>)}</div>
}
