import { useEffect, useState } from 'react'
import { useL } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { usePortalHosts, usePortalTexts, useSaveHosts, useSavePortal, type PortalTexts as T } from '../../lib/platform'
import { Btn, Field, PageHead, Panel, toast } from '../../ui/kit'

/** نصوص الواجهة البارزة لبوابة فرص العمل — باللغتين */
export function PortalTexts() {
  const L = useL()
  const { data } = usePortalTexts()
  const save = useSavePortal()
  const [v, setV] = useState<T | null>(null)
  useEffect(() => { if (data && !v) setV(data) }, [data])
  const e = save.error instanceof ApiError ? save.error : null
  if (!v) return <div className="max-w-3xl mx-auto h-64 rounded-2xl border border-line bg-surface animate-pulse" />
  const set = (k: keyof T, x: string) => { setV({ ...v, [k]: x }); save.reset() }
  return <div className="max-w-3xl mx-auto">
    <PageHead title={L('نصوص البوابة', 'Portal texts')} sub={L('ما يراه الزائر أعلى الصفحة الرئيسية لبوابة فرص العمل.', 'What visitors see at the top of the jobs portal home page.')} />
    <Panel>
      <div className="grid gap-4">
        <Field label={L('الشعار اللاتيني فوق العنوان', 'Latin tagline above the title')} req error={e?.field('brand_latin')}><input className="field ltr text-start" value={v.brand_latin} onChange={x => set('brand_latin', x.target.value)} /></Field>
        <div className="grid sm:grid-cols-2 gap-4">
          <Field label={L('العنوان (عربي)', 'Title (Arabic)')} req error={e?.field('hero_title_ar')}><input className="field" value={v.hero_title_ar} onChange={x => set('hero_title_ar', x.target.value)} /></Field>
          <Field label={L('العنوان (إنكليزي)', 'Title (English)')} req error={e?.field('hero_title_en')}><input className="field ltr text-start" value={v.hero_title_en} onChange={x => set('hero_title_en', x.target.value)} /></Field>
          <Field label={L('النص (عربي)', 'Text (Arabic)')} req error={e?.field('hero_text_ar')}><textarea className="field min-h-[96px]" value={v.hero_text_ar} onChange={x => set('hero_text_ar', x.target.value)} /></Field>
          <Field label={L('النص (إنكليزي)', 'Text (English)')} req error={e?.field('hero_text_en')}><textarea className="field min-h-[96px] ltr text-start" value={v.hero_text_en} onChange={x => set('hero_text_en', x.target.value)} /></Field>
        </div>
        <div className="flex justify-end gap-2"><a className="inline-flex items-center h-9 px-3.5 rounded-lg border border-line font-semibold hover:bg-sub" href="/" target="_blank" rel="noopener">{L('معاينة', 'Preview')}</a>
          <Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => toast(L('حُفظت نصوص البوابة', 'Portal texts saved')) })}>{L('حفظ', 'Save')}</Btn></div>
      </div>
    </Panel>
    <HostsPanel />
  </div>
}

/** بوابة لكل شركة (86): النطاق الأساسي ← {slug}.النطاق */
function HostsPanel() {
  const L = useL()
  const { data } = usePortalHosts()
  const save = useSaveHosts()
  const [v, setV] = useState<string | null>(null)
  const val = v ?? data?.base_domain ?? ''
  const e = save.error instanceof ApiError ? save.error : null
  return <Panel title={L('عناوين بوابات الشركات', 'Company portal addresses')} sub={L('لكل شركة بوابة دخول وفرص عمل بهويتها: /c/اسم-الشركة دائماً، و«اسم-الشركة.النطاق» عند ضبط النطاق هنا، أو نطاقها الخاص من بطاقة الشركة.', 'Each company has a sign-in and jobs portal with its identity: /c/company always, "company.domain" when a base domain is set here, or its own domain from the company card.')}>
    <div className="flex flex-wrap items-end gap-3">
      <div className="flex-1 min-w-[240px]"><Field label={L('النطاق الأساسي', 'Base domain')} error={e?.field('base_domain')} hint={L('يحتاج سجل DNS عاماً (*.النطاق) يشير إلى الخادم وشهادة له.', 'Needs a wildcard DNS record (*.domain) pointing to the server and a certificate.')}>
        <input className="field ltr text-start" value={val} placeholder="uhr.atmata-group.com" onChange={x => { setV(x.target.value.toLowerCase().trim()); save.reset() }} /></Field></div>
      <Btn kind="primary" disabled={save.isPending || v === null} onClick={() => save.mutate({ base_domain: val }, { onSuccess: () => { setV(null); toast(L('حُفظ', 'Saved')) } })}>{L('حفظ', 'Save')}</Btn>
    </div>
    {val && <p className="text-[12.5px] text-ink-3 mt-2">{L('مثال:', 'Example:')} <span className="ltr inline-block">https://alfarada.{val}</span></p>}
  </Panel>
}
