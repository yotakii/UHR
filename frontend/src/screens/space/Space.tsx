import { useMemo, useRef, useState } from 'react'
import { Archive, Award, Building2, Compass, Hash, ImagePlus, Loader2, Lock, LogOut, MessagesSquare, Plus, Settings2, ShieldAlert, Trash2, UserPlus, Users, X } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { navigate, setQuery, useRoute } from '../../lib/router'
import { ApiError } from '../../lib/api'
import { ago, useArchiveCommunity, useCreatePost, useDecideReport, useFeed, useJoin, useMembers, usePeople, usePost, useReports, useSaveCommunity, useSetMember,
  type CommunityRow, type FeedFilter, type FeedPage, type Reaction } from '../../lib/space'
import { Badge, Btn, Empty, Field, Modal, PageHead, Panel, Seg, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'
import { Body, Initials, MentionBox, dot } from './parts'
import { PostCard } from './PostCard'
import { PraiseBoard, PraiseModal } from './Praise'

const err = (e: unknown) => toast(e instanceof ApiError ? e.message : String(e))

/** «الساحة» — للبوابتين: base = /hr/space أو /me/space */
export function SpaceApp({ base }: { base: string }) {
  const route = useRoute()
  const sub = route.parts[2]; const id = Number(route.parts[3])
  if (sub === 'p' && id) return <PostPage id={id} base={base} />
  if (sub === 'moderation') return <Moderation base={base} />
  return <Feed base={base} community={sub === 'c' && id ? id : null} />
}

function Feed({ base, community }: { base: string; community: number | null }) {
  const L = useL()
  const [filter, setFilter] = useState<FeedFilter>('all')
  const q = useFeed(community, filter)
  const first = q.data?.pages[0]
  const posts = useMemo(() => q.data?.pages.flatMap(p => p.data) ?? [], [q.data])
  const [newC, setNewC] = useState(false)
  const route = useRoute()
  const praiseOpen = route.query.praise === '1'
  const openPraise = (on: boolean) => setQuery({ praise: on ? '1' : undefined })
  if (q.isError) return <Empty title={L('لا يمكنك رؤية هذا المجتمع', 'You cannot see this community')} action={<Btn onClick={() => navigate(base)}>{L('الساحة', 'Space')}</Btn>} />
  if (!first) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const c = first.community
  const filters: { v: FeedFilter; label: string }[] = [{ v: 'all', label: L('الكل', 'All') }, ...(first.settings.questions ? [{ v: 'questions' as FeedFilter, label: L('أسئلة', 'Questions') }, { v: 'unanswered' as FeedFilter, label: L('بلا إجابة', 'Unanswered') }] : []),
    ...(first.settings.polls ? [{ v: 'polls' as FeedFilter, label: L('استطلاعات', 'Polls') }] : []), { v: 'praise', label: L('شكر', 'Praise') }, { v: 'celebrations', label: L('احتفالات', 'Celebrations') }, { v: 'mentions', label: L('إشارات إليّ', 'Mentions') }, { v: 'mine', label: L('منشوراتي', 'Mine') }]
  const canPraise = first.praise.enabled && first.can.post
  return <div className="max-w-[1180px] mx-auto">
    <div className="grid gap-5 lg:[grid-template-columns:270px_minmax(0,1fr)]">
      <Communities d={first} base={base} current={community} onNew={() => setNewC(true)} />
      <main className="space-y-4 min-w-0">
        {c ? <CommunityHeader c={c} base={base} /> : <PageHead title={L('الساحة', 'Space')} sub={L('تواصل مع زملائك: شارك الأخبار، اسأل، اشكر زميلاً، واستطلع الآراء.', 'Connect with colleagues: share news, ask, praise and run polls.')}
          actions={canPraise ? <Btn kind="primary" icon={<Award size={15} />} onClick={() => openPraise(true)}>{L('شكر زميل', 'Praise a colleague')}</Btn> : undefined} />}
        {!first.settings.enabled ? <Empty title={L('الساحة غير مفعّلة في شركتك', 'The Space is turned off for your company')} />
          : <>
            {first.can.post && (c ? c.can.post : true) && <Composer d={first} community={c ?? null} />}
            {c && !c.can.post && c.can.join && <JoinBanner c={c} />}
            <div className="flex flex-wrap gap-1.5">{filters.map(f => <button key={f.v} onClick={() => setFilter(f.v)} className={`h-8 px-3 rounded-full border text-[13px] ${filter === f.v ? 'border-brand bg-brand-soft text-brand font-semibold' : 'border-line text-ink-2 hover:bg-sub'}`}>{f.label}</button>)}</div>
            {!c && first.praise_top && <div className="lg:hidden"><PraiseBoard top={first.praise_top} info={first.praise} /></div>}
            {posts.length === 0 ? <Empty title={L('لا منشورات بعد', 'No posts yet')}>{L('كن أول من يكتب هنا.', 'Be the first to write here.')}</Empty>
              : posts.map(p => <PostCard key={p.id} p={p} base={base} reactions={first.settings.reactions} />)}
            {q.hasNextPage && <div className="text-center"><Btn onClick={() => q.fetchNextPage()} disabled={q.isFetchingNextPage}>{q.isFetchingNextPage ? <Loader2 size={15} className="animate-spin" /> : L('عرض المزيد', 'Show more')}</Btn></div>}
          </>}
      </main>
    </div>
    {praiseOpen && canPraise && <PraiseModal info={first.praise} onClose={() => openPraise(false)} />}
    {newC && <CommunityForm onClose={() => setNewC(false)} onSaved={id => navigate(`${base}/c/${id}`)} />}
  </div>
}

function Communities({ d, base, current, onNew }: { d: FeedPage; base: string; current: number | null; onNew: () => void }) {
  const L = useL(); const lang = useLang()
  const mine = d.communities.filter(c => c.member && !c.archived)
  const other = d.communities.filter(c => !c.member && !c.archived)
  const archived = d.communities.filter(c => c.archived)
  const icon = (c: CommunityRow) => c.kind === 'company' ? <Building2 size={15} /> : c.kind === 'department' ? <Users size={15} /> : c.visibility === 'private' ? <Lock size={15} /> : <Hash size={15} />
  const row = (c: CommunityRow) => <li key={c.id}><button onClick={() => navigate(`${base}/c/${c.id}`)} className={`w-full flex items-center gap-2 px-2.5 py-1.5 rounded-lg text-start text-[13.5px] ${current === c.id ? 'bg-brand-soft text-brand font-semibold' : 'hover:bg-sub'}`}>
    <span className="size-6 grid place-items-center rounded-md shrink-0" style={{ ...dot(c.color), color: 'var(--card)' }}>{icon(c)}</span><span className="flex-1 truncate">{bi(c.name, lang)}</span>
    {c.posts > 0 && <span className="num text-[11px] text-ink-3">{c.posts}</span>}</button></li>
  return <aside className="space-y-3 lg:sticky lg:top-20 self-start">
    {/* الجوال: قائمة منسدلة */}
    <div className="lg:hidden flex gap-2">
      <select className="field flex-1" value={current ?? ''} onChange={e => navigate(e.target.value ? `${base}/c/${e.target.value}` : base)} aria-label={L('المجتمع', 'Community')}>
        <option value="">{L('الرئيسية — كل مجتمعاتي', 'Home — all my communities')}</option>
        {mine.length > 0 && <optgroup label={L('مجتمعاتي', 'My communities')}>{mine.map(c => <option key={c.id} value={c.id}>{bi(c.name, lang)}</option>)}</optgroup>}
        {other.length > 0 && <optgroup label={L('اكتشف', 'Discover')}>{other.map(c => <option key={c.id} value={c.id}>{bi(c.name, lang)}</option>)}</optgroup>}
      </select>
      {d.can.create_community && <Btn icon={<Plus size={15} />} onClick={onNew} aria-label={L('مجتمع جديد', 'New community')} />}
      {d.can.moderate && <Btn icon={<ShieldAlert size={15} />} onClick={() => navigate(`${base}/moderation`)} aria-label={L('الإشراف', 'Moderation')} />}
    </div>
    <div className="hidden lg:block space-y-3">
      <Panel pad={false}><div className="p-2">
        <button onClick={() => navigate(base)} className={`w-full flex items-center gap-2 px-2.5 py-2 rounded-lg text-start text-[14px] font-semibold ${current === null ? 'bg-brand-soft text-brand' : 'hover:bg-sub'}`}><MessagesSquare size={17} />{L('الرئيسية', 'Home')}</button>
        <div className="px-2.5 pt-3 pb-1 text-[11.5px] font-semibold text-ink-3">{L('مجتمعاتي', 'My communities')}</div>
        <ul>{mine.map(row)}</ul>
        {other.length > 0 && <><div className="px-2.5 pt-3 pb-1 text-[11.5px] font-semibold text-ink-3 flex items-center gap-1"><Compass size={12} />{L('اكتشف', 'Discover')}</div><ul>{other.map(row)}</ul></>}
        {archived.length > 0 && <><div className="px-2.5 pt-3 pb-1 text-[11.5px] font-semibold text-ink-3">{L('مؤرشفة', 'Archived')}</div><ul className="opacity-70">{archived.map(row)}</ul></>}
      </div></Panel>
      {d.can.create_community && <Btn className="w-full" icon={<Plus size={15} />} onClick={onNew}>{L('مجتمع جديد', 'New community')}</Btn>}
      {d.can.moderate && <Btn className="w-full" icon={<ShieldAlert size={15} />} onClick={() => navigate(`${base}/moderation`)}>{L('الإشراف والبلاغات', 'Moderation & reports')}</Btn>}
      {d.praise_top && d.praise.enabled && <PraiseBoard top={d.praise_top} info={d.praise} onGive={d.can.post ? () => setQuery({ praise: '1' }) : undefined} />}
    </div>
  </aside>
}

function CommunityHeader({ c, base }: { c: CommunityRow; base: string }) {
  const L = useL(); const lang = useLang()
  const join = useJoin(); const arch = useArchiveCommunity()
  const [edit, setEdit] = useState(false); const [members, setMembers] = useState(false)
  const kind = c.kind === 'company' ? L('الشركة كلها', 'Whole company') : c.kind === 'department' ? L('مجتمع إدارة', 'Department') : c.visibility === 'private' ? L('مجتمع خاص', 'Private community') : L('مجتمع عام', 'Public community')
  return <Panel>
    <div className="flex flex-wrap items-start gap-4">
      <span className="size-12 rounded-xl shrink-0" style={dot(c.color)} aria-hidden />
      <div className="min-w-0 flex-1">
        <h1 className="font-display font-bold text-[20px]">{bi(c.name, lang)}</h1>
        <div className="text-[12.5px] text-ink-3 flex flex-wrap gap-x-2">{kind}{c.members !== null && <span className="num">· {L(`${c.members} عضو`, `${c.members} members`)}</span>}{c.last_at && <span>· {L('آخر نشاط', 'Last activity')} {ago(c.last_at, lang === 'ar')}</span>}{c.archived && <Badge tone="neutral">{L('مؤرشف', 'Archived')}</Badge>}</div>
        {c.about && <Body text={c.about} className="text-[13.5px] text-ink-2 mt-1" />}
      </div>
      <div className="flex flex-wrap gap-2">
        {c.kind === 'interest' && <Btn size="sm" icon={<Users size={14} />} onClick={() => setMembers(true)}>{L('الأعضاء', 'Members')}</Btn>}
        {c.can.join && <Btn size="sm" kind="primary" icon={<UserPlus size={14} />} onClick={() => join.mutate({ id: c.id }, { onSuccess: () => toast(L('انضممت إلى المجتمع', 'You joined the community')), onError: err })}>{L('انضمام', 'Join')}</Btn>}
        {c.can.leave && <Btn size="sm" icon={<LogOut size={14} />} onClick={() => join.mutate({ id: c.id, leave: true }, { onSuccess: () => toast(L('غادرت المجتمع', 'You left the community')), onError: err })}>{L('مغادرة', 'Leave')}</Btn>}
        {c.can.admin && c.kind === 'interest' && <Btn size="sm" icon={<Settings2 size={14} />} onClick={() => setEdit(true)}>{L('تعديل', 'Edit')}</Btn>}
        {c.can.admin && c.kind !== 'company' && <Btn size="sm" icon={<Archive size={14} />} onClick={() => arch.mutate({ id: c.id, restore: c.archived }, { onSuccess: () => { toast(c.archived ? L('أُعيد المجتمع', 'Restored') : L('أُرشف المجتمع', 'Archived')); if (!c.archived) navigate(base) }, onError: err })}>{c.archived ? L('استعادة', 'Restore') : L('أرشفة', 'Archive')}</Btn>}
      </div>
    </div>
    {edit && <CommunityForm c={c} onClose={() => setEdit(false)} />}
    {members && <MembersModal c={c} onClose={() => setMembers(false)} />}
  </Panel>
}

function JoinBanner({ c }: { c: CommunityRow }) {
  const L = useL(); const join = useJoin()
  return <div className="rounded-xl border border-dashed border-line px-4 py-3 flex flex-wrap items-center gap-3 text-[13.5px]"><span className="flex-1">{L('انضم إلى المجتمع لتنشر فيه وتصلك منشوراته في الرئيسية.', 'Join the community to post and see its posts on your home.')}</span>
    <Btn size="sm" kind="primary" onClick={() => join.mutate({ id: c.id }, { onError: err })}>{L('انضمام', 'Join')}</Btn></div>
}

function Composer({ d, community }: { d: FeedPage; community: CommunityRow | null }) {
  const L = useL(); const lang = useLang()
  const save = useCreatePost()
  const targets = d.communities.filter(c => c.can.post && !c.archived)
  const [cid, setCid] = useState<number>(community?.id ?? targets.find(c => c.kind === 'company')?.id ?? targets[0]?.id ?? 0)
  const [kind, setKind] = useState<'post' | 'poll' | 'question'>('post')
  const [text, setText] = useState('')
  const [opts, setOpts] = useState(['', ''])
  const [multi, setMulti] = useState(false); const [closes, setCloses] = useState('')
  const [files, setFiles] = useState<File[]>([])
  const [focus, setFocus] = useState(false)
  const pickRef = useRef<HTMLInputElement>(null)
  const e = save.error instanceof ApiError ? save.error : null
  const reset = () => { setText(''); setOpts(['', '']); setMulti(false); setCloses(''); setFiles([]); setKind('post'); setFocus(false) }
  const submit = () => {
    const fd = new FormData()
    fd.append('community_id', String(community?.id ?? cid)); fd.append('kind', kind); fd.append('body', text)
    if (kind === 'poll') { opts.forEach(o => fd.append('options[]', o)); fd.append('multi', multi ? '1' : '0'); if (closes) fd.append('closes_at', closes) }
    files.forEach(f => fd.append('images[]', f))
    save.mutate(fd, { onSuccess: () => { toast(L('نُشر', 'Posted')); reset() } })
  }
  const kinds = [{ v: 'post' as const, label: L('منشور', 'Post') }, ...(d.settings.questions ? [{ v: 'question' as const, label: L('سؤال', 'Question') }] : []), ...(d.settings.polls ? [{ v: 'poll' as const, label: L('استطلاع', 'Poll') }] : [])]
  if (!targets.length) return null
  return <Panel>
    <div className="flex items-start gap-3">
      <Initials p={d.me} />
      <div className="flex-1 min-w-0 space-y-3" onFocus={() => setFocus(true)}>
        <MentionBox value={text} onChange={setText} rows={focus || text ? 3 : 1}
          placeholder={kind === 'question' ? L('ما سؤالك؟', 'What is your question?') : kind === 'poll' ? L('سؤال الاستطلاع', 'Poll question') : L('شارك زملاءك خبراً أو فكرة… (@ للإشارة إلى زميل)', 'Share news or an idea… (@ to mention)')} />
        {e?.field('body') && <p className="text-danger text-[12.5px]">{e.field('body')}</p>}
        {(focus || text) && <>
          {kind === 'poll' && <div className="space-y-2">{opts.map((o, i) => <div key={i} className="flex gap-2"><input className="field flex-1" value={o} maxLength={120} placeholder={L(`الخيار ${i + 1}`, `Option ${i + 1}`)} onChange={x => setOpts(s => s.map((v, j) => j === i ? x.target.value : v))} />
            {opts.length > 2 && <Btn size="sm" kind="ghost" icon={<X size={14} />} onClick={() => setOpts(s => s.filter((_, j) => j !== i))} aria-label={L('حذف', 'Remove')} />}</div>)}
            {e?.field('options') && <p className="text-danger text-[12.5px]">{e.field('options')}</p>}
            <div className="flex flex-wrap items-center gap-3 text-[13px]">{opts.length < 6 && <button className="text-brand font-semibold" onClick={() => setOpts(s => [...s, ''])}>+ {L('خيار', 'Option')}</button>}
              <label className="inline-flex items-center gap-1.5"><input type="checkbox" className="accent-[var(--brand)]" checked={multi} onChange={x => setMulti(x.target.checked)} />{L('أكثر من خيار', 'Multiple choice')}</label>
              <label className="inline-flex items-center gap-1.5 text-ink-3">{L('يُغلق', 'Closes')}<input type="datetime-local" className="field !h-8 !w-auto num" value={closes} onChange={x => setCloses(x.target.value)} /></label></div>
            {e?.field('closes_at') && <p className="text-danger text-[12.5px]">{e.field('closes_at')}</p>}</div>}
          {files.length > 0 && <div className="flex flex-wrap gap-2">{files.map((f, i) => <span key={i} className="relative"><img src={URL.createObjectURL(f)} alt="" className="size-16 rounded-lg object-cover" />
            <button className="absolute -top-1.5 -end-1.5 size-5 grid place-items-center rounded-full bg-surface border border-line" onClick={() => setFiles(s => s.filter((_, j) => j !== i))} aria-label={L('إزالة', 'Remove')}><X size={11} /></button></span>)}</div>}
          {e?.field('images') && <p className="text-danger text-[12.5px]">{e.field('images')}</p>}
          <div className="flex flex-wrap items-center gap-2">
            <Seg value={kind} onChange={setKind} options={kinds} />
            {kind !== 'poll' && d.settings.max_images > 0 && <><Btn size="sm" kind="ghost" icon={<ImagePlus size={15} />} disabled={files.length >= d.settings.max_images} onClick={() => pickRef.current?.click()}>{L('صور', 'Images')}</Btn>
              <input ref={pickRef} type="file" accept="image/*" multiple hidden onChange={x => { setFiles(s => [...s, ...Array.from(x.target.files ?? [])].slice(0, d.settings.max_images)); x.target.value = '' }} /></>}
            <span className="flex-1" />
            {!community && <select className="field !h-9 !w-auto max-w-[210px]" value={cid} onChange={x => setCid(Number(x.target.value))} aria-label={L('المجتمع', 'Community')}>{targets.map(c => <option key={c.id} value={c.id}>{bi(c.name, lang)}</option>)}</select>}
            <Btn size="sm" onClick={reset}>{L('إلغاء', 'Cancel')}</Btn>
            <Btn size="sm" kind="primary" disabled={!text.trim() || save.isPending} onClick={submit}>{save.isPending ? <Loader2 size={14} className="animate-spin" /> : L('نشر', 'Post')}</Btn>
          </div>
          {e && !Object.keys(e.errors).length && <p className="text-danger text-[12.5px]">{e.message}</p>}
        </>}
      </div>
    </div>
  </Panel>
}

function CommunityForm({ c, onClose, onSaved }: { c?: CommunityRow; onClose: () => void; onSaved?: (id: number) => void }) {
  const L = useL()
  const save = useSaveCommunity()
  const [v, setV] = useState({ name_ar: c?.name.ar ?? '', name_en: c?.name.en && c.name.en !== c.name.ar ? c.name.en : '', about: c?.about ?? '', visibility: c?.visibility ?? 'public' as 'public' | 'private', color: c?.color ?? 'brand' })
  const e = save.error instanceof ApiError ? save.error : null
  return <Modal open onClose={onClose} title={c ? L('تعديل المجتمع', 'Edit community') : L('مجتمع جديد', 'New community')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending || v.name_ar.trim().length < 3} onClick={() => save.mutate({ ...v, id: c?.id }, { onSuccess: r => { toast(L('حُفظ المجتمع', 'Community saved')); onClose(); onSaved?.((r as { data: CommunityRow }).data.id) } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid gap-4 sm:grid-cols-2">
      <Field label={L('الاسم', 'Name')} req error={e?.field('name_ar')}><input className="field" value={v.name_ar} onChange={x => setV({ ...v, name_ar: x.target.value })} /></Field>
      <Field label={L('الاسم بالإنكليزية', 'English name')}><input className="field ltr text-start" value={v.name_en} onChange={x => setV({ ...v, name_en: x.target.value })} /></Field>
      <Field label={L('نبذة', 'About')} className="sm:col-span-2"><textarea className="field !h-auto py-2" rows={3} maxLength={600} value={v.about} onChange={x => setV({ ...v, about: x.target.value })} /></Field>
      {(!c || c.kind === 'interest') && <Field label={L('الظهور', 'Visibility')} div><Seg value={v.visibility} onChange={x => setV({ ...v, visibility: x })} options={[{ v: 'public', label: L('عام — ينضم من يشاء', 'Public — anyone joins') }, { v: 'private', label: L('خاص — بالدعوة', 'Private — by invitation') }]} /></Field>}
      <Field label={L('اللون', 'Colour')} div><div className="flex gap-2">{['brand', 'info', 'brass', 'danger', 'ink-3'].map(k => <button key={k} onClick={() => setV({ ...v, color: k })} aria-label={k} aria-pressed={v.color === k}
        className={`size-8 rounded-full border-2 ${v.color === k ? 'border-ink' : 'border-transparent'}`} style={dot(k)} />)}</div></Field>
    </div>
    {e && !Object.keys(e.errors).length && <p className="mt-3 text-danger text-[13px]">{e.message}</p>}
  </Modal>
}

function MembersModal({ c, onClose }: { c: CommunityRow; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const m = useMembers(c.id); const set = useSetMember()
  const [q, setQ] = useState('')
  const people = usePeople(q, c.can.admin && q.length > 0).data ?? []
  const ids = new Set((m.data ?? []).map(x => x.user.id))
  return <Modal open onClose={onClose} title={<>{L('أعضاء', 'Members of')} {bi(c.name, lang)}</>} footer={<Btn kind="primary" onClick={onClose}>{L('تم', 'Done')}</Btn>}>
    {c.can.admin && <div className="mb-4"><input className="field" value={q} onChange={e => setQ(e.target.value)} placeholder={L('أضف زميلاً بالاسم…', 'Add a colleague by name…')} />
      {people.filter(p => !ids.has(p.id)).length > 0 && <ul className="mt-2 rounded-xl border border-line divide-y divide-line-2">{people.filter(p => !ids.has(p.id)).map(p => <li key={p.id} className="flex items-center gap-2 px-3 py-2">
        <Initials p={p} size={28} /><span className="flex-1 text-[13.5px]">{bi(p.name, lang)}</span><Btn size="sm" onClick={() => set.mutate({ id: c.id, user_id: p.id!, role: 'member' }, { onSuccess: () => setQ(''), onError: err })}>{L('إضافة', 'Add')}</Btn></li>)}</ul>}</div>}
    {!m.data ? <Loader2 className="animate-spin text-brand mx-auto" /> : <ul className="divide-y divide-line-2">{m.data.map(x => <li key={x.user.id} className="flex flex-wrap items-center gap-2 py-2">
      <Initials p={x.user} size={30} /><span className="flex-1 min-w-[140px]"><span className="block text-[13.5px] font-semibold">{bi(x.user.name, lang)}</span>{x.user.title && <span className="block text-[12px] text-ink-3">{bi(x.user.title, lang)}</span>}</span>
      {x.role === 'admin' && <Badge tone="brand">{L('مشرف', 'Admin')}</Badge>}
      {c.can.admin && <><Btn size="sm" kind="ghost" onClick={() => set.mutate({ id: c.id, user_id: x.user.id!, role: x.role === 'admin' ? 'member' : 'admin' }, { onError: err })}>{x.role === 'admin' ? L('إلغاء الإشراف', 'Remove admin') : L('تعيين مشرفاً', 'Make admin')}</Btn>
        <Btn size="sm" kind="ghost" icon={<Trash2 size={14} />} onClick={() => set.mutate({ id: c.id, user_id: x.user.id!, role: null }, { onError: err })} aria-label={L('إزالة', 'Remove')} /></>}
    </li>)}</ul>}
  </Modal>
}

function PostPage({ id, base }: { id: number; base: string }) {
  const L = useL()
  const q = usePost(id)
  if (q.isError) return <Empty title={L('المنشور غير متاح', 'Post not available')} action={<Btn onClick={() => navigate(base)}>{L('الساحة', 'Space')}</Btn>} />
  if (!q.data) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  return <div className="max-w-[760px] mx-auto space-y-4">
    <button className="text-[13px] text-ink-3 hover:text-ink" onClick={() => navigate(base)}>← {L('الساحة', 'Space')}</button>
    <PostCard p={q.data.data} base={base} reactions={q.data.settings.reactions as Reaction[]} open />
  </div>
}

function Moderation({ base }: { base: string }) {
  const L = useL(); const lang = useLang()
  const q = useReports(); const decide = useDecideReport()
  const [hide, setHide] = useState<{ target: 'post' | 'comment'; id: number } | null>(null)
  const reason = (k: string) => ({ inappropriate: L('غير لائق', 'Inappropriate'), offensive: L('مسيء', 'Offensive'), spam: L('إعلان أو تكرار', 'Spam'), private: L('معلومات خاصة', 'Private info'), other: L('آخر', 'Other') } as Record<string, string>)[k] ?? k
  return <div className="max-w-[900px] mx-auto space-y-4">
    <PageHead title={L('الإشراف على الساحة', 'Space moderation')} sub={L('البلاغات المفتوحة. «إبقاء» يُبقي المحتوى ظاهراً، و«إخفاء» يخفيه ويُبلغ صاحبه بالسبب.', 'Open reports. “Keep” leaves the content visible; “Hide” hides it and tells the author why.')}
      actions={<Btn onClick={() => navigate(base)}>{L('الساحة', 'Space')}</Btn>} />
    {!q.data ? <div className="grid place-items-center py-16"><Loader2 className="animate-spin text-brand" /></div> : !q.data.length ? <Empty title={L('لا بلاغات مفتوحة', 'No open reports')} />
      : q.data.map(r => <Panel key={r.target + r.id}>
        <div className="flex flex-wrap items-start gap-3">
          <Initials p={r.author} size={34} />
          <div className="flex-1 min-w-[220px]">
            <div className="text-[13px] font-semibold">{bi(r.author.name, lang)} <span className="text-ink-3 font-normal">· {r.target === 'post' ? L('منشور', 'Post') : L('تعليق', 'Comment')}{r.community && ` · ${r.community}`} · {ago(r.at, lang === 'ar')}</span>{r.hidden && <Badge tone="danger">{L('مخفي تلقائياً', 'Auto-hidden')}</Badge>}</div>
            <Body text={r.body} className="text-[13.5px] mt-1 rounded-lg bg-sub px-3 py-2" />
            <ul className="mt-2 space-y-1 text-[12.5px] text-ink-2">{r.reports.map((x, i) => <li key={i}>• <b>{reason(x.reason)}</b>{x.note && ` — ${x.note}`} <span className="text-ink-3">({lang === 'ar' ? x.by : x.by_en}، {ago(x.at, lang === 'ar')})</span></li>)}</ul>
          </div>
          <div className="flex flex-wrap gap-2">
            <Btn size="sm" onClick={() => navigate(`${base}/p/${r.post_id}`)}>{L('فتح', 'Open')}</Btn>
            <Btn size="sm" onClick={() => decide.mutate({ target: r.target, id: r.id, action: 'keep' }, { onSuccess: () => toast(L('أُبقي المحتوى', 'Kept')), onError: err })}>{L('إبقاء', 'Keep')}</Btn>
            <Btn size="sm" kind="danger" onClick={() => setHide({ target: r.target, id: r.id })}>{L('إخفاء', 'Hide')}</Btn>
          </div>
        </div>
      </Panel>)}
    <DecisionDialog open={!!hide} onClose={() => setHide(null)} tone="danger" title={L('إخفاء المحتوى', 'Hide content')} confirmLabel={L('إخفاء', 'Hide')} reasonRequired minLength={5}
      reasonLabel={L('السبب (يصل إلى صاحب المحتوى)', 'Reason (sent to the author)')} presets={[L('مخالف لميثاق السلوك', 'Against the code of conduct'), L('معلومات خاصة بزميل', 'A colleague’s private information'), L('محتوى إعلاني', 'Advertising')]}
      onConfirm={note => decide.mutateAsync({ ...hide!, action: 'remove', note }).then(() => toast(L('أُخفي المحتوى', 'Hidden'))).catch(e => { err(e); throw e })} />
  </div>
}
