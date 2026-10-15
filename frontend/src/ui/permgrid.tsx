import { Check } from 'lucide-react'
import { useL, useLang, bi } from '../lib/store'
import type { PermAction } from '../lib/session'
import type { Cell, PermGrid, Scope } from '../lib/console'

/**
 * PermissionGrid (SRS §10.4): صلاحيات دور واحد — الوحدات × الإجراءات (ع ض ت ق ص ط ح) + النطاق.
 * «عرض» شرط لبقية الإجراءات: إلغاؤه يمسح الصف، وأي إجراء آخر يفعّله.
 */
export function PermissionGrid({ grid, cells, onChange, readOnly, base }: {
  grid: Pick<PermGrid, 'actions' | 'scopes' | 'modules'>; cells: Record<string, Cell>; onChange?: (module: string, cell: Cell) => void; readOnly?: boolean; base?: Record<string, Cell>
}) {
  const L = useL(); const lang = useLang()
  const toggle = (m: string, a: PermAction) => {
    const c = cells[m]; const on = c.actions.includes(a)
    let actions: PermAction[] = on ? c.actions.filter(x => x !== a) : [...c.actions, a]
    if (a === 'view' && on) actions = []
    if (!on && a !== 'view' && !actions.includes('view')) actions = ['view', ...actions]
    onChange?.(m, { ...c, actions: grid.actions.map(x => x.key).filter(k => actions.includes(k)) })
  }
  const changed = (m: string) => base && JSON.stringify(base[m]) !== JSON.stringify(cells[m])
  return <div className="scroll-x"><table className="tbl min-w-[760px]">
    <thead><tr><th className="min-w-[200px]">{L('الوحدة', 'Module')}</th>
      {grid.actions.map(a => <th key={a.key} className="text-center w-[64px]" title={bi(a, lang)}><span className="block text-[15px] font-bold text-ink">{a.letter}</span><span className="block text-[10.5px] font-normal whitespace-nowrap">{bi(a, lang)}</span></th>)}
      <th className="w-[190px]">{L('النطاق', 'Scope')}</th></tr></thead>
    <tbody>{grid.modules.map(m => { const c = cells[m.key]; const none = !c.actions.length
      return <tr key={m.key} className={changed(m.key) ? 'bg-brass-soft/40' : ''}>
        <td className={`font-medium ${none ? 'text-ink-3' : ''}`}>{bi(m, lang)}</td>
        {grid.actions.map(a => { const on = c.actions.includes(a.key)
          return <td key={a.key} className="text-center">
            <button type="button" disabled={readOnly} onClick={() => toggle(m.key, a.key)} aria-pressed={on} aria-label={`${bi(m, lang)} — ${bi(a, lang)}`}
              className={`size-7 rounded-md border inline-grid place-items-center transition-colors ${on ? 'bg-brand border-brand text-on-brand' : 'border-line bg-surface text-transparent hover:border-brand/50'} disabled:cursor-default disabled:opacity-80`}><Check size={15} strokeWidth={3} /></button>
          </td> })}
        <td>{none ? <span className="text-[12px] text-ink-3">—</span>
          : <select disabled={readOnly} value={c.scope} onChange={e => onChange?.(m.key, { ...c, scope: e.target.value as Scope })} className="field h-8 py-0 text-[13px]" aria-label={L('النطاق', 'Scope')}>
            {grid.scopes.map(s => <option key={s.key} value={s.key}>{bi(s, lang)}</option>)}</select>}</td>
      </tr> })}</tbody>
  </table></div>
}

/** رموز الإجراءات المختصرة لخلية: «ع ض ت» */
export const letters = (grid: Pick<PermGrid, 'actions'>, c: Cell) => grid.actions.filter(a => c.actions.includes(a.key)).map(a => a.letter).join(' ')
