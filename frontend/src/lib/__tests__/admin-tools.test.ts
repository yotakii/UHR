import { describe, expect, it } from 'vitest'
import { orgPages } from '../employees'
import { normAr } from '../policies'
import { hrLink } from '../hr'

// الهيكل: 1 ← (2، 3)، 2 ← (4، 5)، 4 ← (6)، 6 ← (7)
const nodes = [{ id: 1, manager_id: null }, { id: 2, manager_id: 1 }, { id: 3, manager_id: 1 }, { id: 4, manager_id: 2 }, { id: 5, manager_id: 2 }, { id: 6, manager_id: 4 }, { id: 7, manager_id: 6 }]

describe('orgPages — تقسيم الهيكل على صفحات (D-33)', () => {
  it('keeps a small tree on one page', () => {
    expect(orgPages(nodes, [1], 5)).toEqual([{ root: 1, cut: [] }])
  })
  it('continues deep teams on their own pages', () => {
    expect(orgPages(nodes, [1], 3)).toEqual([{ root: 1, cut: [4] }, { root: 4, cut: [] }])
    expect(orgPages(nodes, [1], 2)).toEqual([{ root: 1, cut: [2] }, { root: 2, cut: [4] }, { root: 4, cut: [6] }, { root: 6, cut: [] }])
  })
  it('starts a page per root (departments, branches)', () => {
    expect(orgPages(nodes.filter(n => n.id >= 4), [4], 3).map(p => p.root)).toEqual([4])
    expect(orgPages(nodes, [2, 3], 4).map(p => p.root)).toEqual([2, 3])
  })
})

describe('policy search helpers', () => {
  it('normalises Arabic like the server', () => {
    expect(normAr('الإجازةُ المرضيّة')).toBe('الاجازه المرضيه')
    expect(normAr('مُستشفى')).toBe('مستشفي')
  })
  it('links policy notices', () => {
    expect(hrLink('policy:5')).toBe('/hr/policies?doc=5')
  })
})
