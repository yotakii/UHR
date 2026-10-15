import { createContext, useContext, useState, type ReactNode } from 'react'
export type Route = { page: string; id?: string }
const Ctx = createContext<{ route: Route; go: (page: string, id?: string) => void }>({ route: { page: '' }, go: () => {} })
export function NavProvider({ initial, children }: { initial: string; children: ReactNode }) {
  const [route, setRoute] = useState<Route>({ page: initial })
  const go = (page: string, id?: string) => { setRoute({ page, id }); document.getElementById('main-scroll')?.scrollTo(0, 0); window.scrollTo(0, 0) }
  return <Ctx.Provider value={{ route, go }}>{children}</Ctx.Provider>
}
export const useNav = () => useContext(Ctx)
