import { useState } from 'react'
import { CheckCircle2, EyeOff, Flag, HelpCircle, Loader2, MessageCircle, MoreHorizontal, Pencil, Pin, PinOff, Reply, Send, Trash2, X } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { navigate } from '../../lib/router'
import { fileUrl } from '../../lib/console'
import { ApiError } from '../../lib/api'
import { REACTIONS, ago, useBest, useComment, useDecideReport, useDeleteComment, useDeletePost, usePin, usePost, useReact, useReport, useUpdateComment, useUpdatePost, useVote,
  type CommentRow, type PostRow, type Reaction } from '../../lib/space'
import { Btn, Modal, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'
import { Body, Initials, MentionBox, Pill, dot } from './parts'

const err = (e: unknown) => toast(e instanceof ApiError ? e.message : String(e))

export function PostCard({ p, base, reactions, open: startOpen = false }: { p: PostRow; base: string; reactions: Reaction[]; open?: boolean }) {
  const L = useL(); const lang = useLang(); const ar = lang === 'ar'
  const [open, setOpen] = useState(startOpen)
  const [menu, setMenu] = useState(false)
  const [edit, setEdit] = useState(false)
  const [report, setReport] = useState(false)
  const [del, setDel] = useState(false)
  const [hide, setHide] = useState(false)
  const [img, setImg] = useState<number | null>(null)
  const react = useReact(); const pin = usePin(); const delPost = useDeletePost(); const decide = useDecideReport()
  const top = (Object.entries(p.reactions.counts) as [Reaction, number][]).filter(([, n]) => n > 0).sort((a, b) => b[1] - a[1]).slice(0, 3)
  const anyMenu = p.can.edit || p.can.pin || p.can.delete || p.can.report || p.can.moderate
  return <article className={`bg-surface border rounded-2xl ${p.pinned ? 'border-brand/50' : 'border-line'} ${p.hidden ? 'opacity-70' : ''}`}>
    <header className="flex items-start gap-3 px-4 pt-4">
      <Initials p={p.author} />
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5"><span className="font-semibold">{bi(p.author.name, lang)}</span>
          {p.pinned && <Pill tone="brand"><Pin size={11} />{L('مثبّت', 'Pinned')}</Pill>}
          {p.kind === 'question' && <Pill tone={p.best ? 'brand' : 'info'}>{p.best ? <CheckCircle2 size={11} /> : <HelpCircle size={11} />}{p.best ? L('تمت الإجابة', 'Answered') : L('سؤال', 'Question')}</Pill>}
          {p.kind === 'poll' && <Pill tone="brass">{L('استطلاع', 'Poll')}</Pill>}
          {p.hidden && <Pill tone="danger"><EyeOff size={11} />{L('مخفي', 'Hidden')}</Pill>}</div>
        <div className="text-[12px] text-ink-3 flex flex-wrap items-center gap-x-1.5">
          {p.author.title && <span>{bi(p.author.title, lang)}</span>}
          {p.author.title && <span aria-hidden>·</span>}
          <button className="inline-flex items-center gap-1 hover:text-ink" onClick={() => navigate(`${base}/c/${p.community.id}`)}><span className="size-2 rounded-full" style={dot(p.community.color)} />{bi(p.community.name, lang)}</button>
          <span aria-hidden>·</span><button className="hover:underline" onClick={() => navigate(`${base}/p/${p.id}`)}>{ago(p.at, ar)}</button>{p.edited && <span>· {L('معدّل', 'edited')}</span>}
        </div>
      </div>
      {anyMenu && <div className="relative">
        <button className="size-8 grid place-items-center rounded-lg hover:bg-sub text-ink-3" onClick={() => setMenu(m => !m)} aria-label={L('خيارات', 'Options')} aria-expanded={menu}><MoreHorizontal size={17} /></button>
        {menu && <><div className="fixed inset-0 z-20" onClick={() => setMenu(false)} />
          <ul className="absolute z-30 end-0 mt-1 w-48 rounded-xl border border-line bg-surface shadow-lg py-1 text-[13.5px]" onClick={() => setMenu(false)}>
            {p.can.edit && <Item icon={<Pencil size={15} />} onClick={() => setEdit(true)}>{L('تعديل', 'Edit')}</Item>}
            {p.can.pin && <Item icon={p.pinned ? <PinOff size={15} /> : <Pin size={15} />} onClick={() => pin.mutate({ id: p.id, on: !p.pinned }, { onError: err })}>{p.pinned ? L('إلغاء التثبيت', 'Unpin') : L('تثبيت في المجتمع', 'Pin to community')}</Item>}
            {p.can.report && <Item icon={<Flag size={15} />} onClick={() => setReport(true)}>{L('إبلاغ', 'Report')}</Item>}
            {p.can.moderate && !p.hidden && <Item icon={<EyeOff size={15} />} onClick={() => setHide(true)}>{L('إخفاء (إشراف)', 'Hide (moderation)')}</Item>}
            {p.can.moderate && p.hidden && <Item icon={<EyeOff size={15} />} onClick={() => decide.mutate({ target: 'post', id: p.id, action: 'keep' }, { onSuccess: () => toast(L('أُعيد إظهار المنشور', 'Post shown again')), onError: err })}>{L('إعادة الإظهار', 'Show again')}</Item>}
            {p.can.delete && <Item danger icon={<Trash2 size={15} />} onClick={() => setDel(true)}>{L('حذف', 'Delete')}</Item>}
          </ul></>}
      </div>}
    </header>
    <div className="px-4 pt-3"><Body text={p.body} className={p.kind === 'question' ? 'text-[15.5px] font-semibold' : 'text-[14.5px]'} /></div>
    {p.images.length > 0 && <div className={`px-4 pt-3 grid gap-1.5 ${p.images.length === 1 ? 'grid-cols-1' : 'grid-cols-2'}`}>
      {p.images.map((im, i) => <button key={im.id} onClick={() => setImg(im.id)} className={`overflow-hidden rounded-xl bg-sub ${p.images.length === 3 && i === 0 ? 'row-span-2' : ''}`}>
        <img src={fileUrl(im.id, true)} alt="" loading="lazy" className={`w-full object-cover ${p.images.length === 1 ? 'max-h-[420px]' : 'h-44'}`} /></button>)}</div>}
    {p.poll && <Poll p={p} />}
    {p.best && <div className="mx-4 mt-3 rounded-xl border border-brand/40 bg-brand-soft/40 px-3 py-2">
      <div className="text-[12px] font-semibold text-brand flex items-center gap-1"><CheckCircle2 size={13} />{L('أفضل إجابة', 'Best answer')} — {bi(p.best.author.name, lang)}</div>
      <Body text={p.best.body} className="text-[13.5px]" /></div>}
    <div className="px-4 pt-3 flex items-center gap-3 text-[12.5px] text-ink-3">
      {p.reactions.total > 0 && <span className="inline-flex items-center gap-1"><span className="text-[15px] leading-none">{top.map(([k]) => REACTIONS[k].emoji).join('')}</span><span className="num">{p.reactions.total}</span></span>}
      <span className="flex-1" />
      {p.comments_count > 0 && <button className="hover:underline" onClick={() => setOpen(o => !o)}>{L(`${p.comments_count} تعليق`, `${p.comments_count} comments`)}</button>}
    </div>
    <div className="mx-4 mt-2 border-t border-line-2 flex items-center gap-1 py-1.5">
      <ReactButton mine={p.reactions.mine} list={reactions} onPick={t => react.mutate({ target: 'post', id: p.id, type: t }, { onError: err })} />
      {p.can.comment && <button className="flex-1 inline-flex items-center justify-center gap-1.5 h-9 rounded-lg hover:bg-sub text-[13px] font-semibold text-ink-2" onClick={() => setOpen(o => !o)}>
        <MessageCircle size={16} />{p.kind === 'question' ? L('أجب', 'Answer') : L('تعليق', 'Comment')}</button>}
    </div>
    {open && <Comments id={p.id} kind={p.kind} canBest={p.can.best} bestId={p.best?.id ?? null} canComment={p.can.comment} />}

    {edit && <EditPost p={p} onClose={() => setEdit(false)} />}
    {report && <ReportModal target="post" id={p.id} onClose={() => setReport(false)} />}
    <DecisionDialog open={del} onClose={() => setDel(false)} tone="danger" title={L('حذف المنشور', 'Delete post')} confirmLabel={L('حذف', 'Delete')}
      subject={L('يُحذف المنشور وتعليقاته وتفاعلاته نهائياً.', 'The post, its comments and reactions are deleted for good.')} onConfirm={() => delPost.mutateAsync(p.id).then(() => toast(L('حُذف المنشور', 'Post deleted'))).catch(e => { err(e); throw e })} />
    <DecisionDialog open={hide} onClose={() => setHide(false)} tone="danger" title={L('إخفاء المنشور', 'Hide post')} confirmLabel={L('إخفاء', 'Hide')} reasonRequired minLength={5}
      reasonLabel={L('السبب (يصل إلى صاحب المنشور)', 'Reason (sent to the author)')} onConfirm={note => decide.mutateAsync({ target: 'post', id: p.id, action: 'remove', note }).then(() => toast(L('أُخفي المنشور', 'Post hidden'))).catch(e => { err(e); throw e })} />
    {img !== null && <Modal open width={900} onClose={() => setImg(null)} title={L('صورة', 'Image')}><img src={fileUrl(img, true)} alt="" className="w-full max-h-[75vh] object-contain" /></Modal>}
  </article>
}

function Item({ icon, children, onClick, danger }: { icon: React.ReactNode; children: React.ReactNode; onClick: () => void; danger?: boolean }) {
  return <li><button className={`w-full flex items-center gap-2 px-3 py-2 hover:bg-sub text-start ${danger ? 'text-danger' : ''}`} onClick={onClick}>{icon}{children}</button></li>
}

/** زر التفاعل: نقرة = إعجاب/إلغاء، والسهم أو الضغط المطوّل يفتح بقية التفاعلات */
function ReactButton({ mine, list, onPick }: { mine: Reaction | null; list: Reaction[]; onPick: (t: Reaction | null) => void }) {
  const L = useL(); const lang = useLang()
  const [pick, setPick] = useState(false)
  const main: Reaction = list.includes('like') ? 'like' : list[0]
  const cur = mine ? REACTIONS[mine] : null
  return <div className="relative flex-1 flex" onMouseLeave={() => setPick(false)}>
    <button className={`flex-1 inline-flex items-center justify-center gap-1.5 h-9 rounded-lg hover:bg-sub text-[13px] font-semibold ${mine ? 'text-brand' : 'text-ink-2'}`}
      onClick={() => onPick(mine ? null : main)} onMouseEnter={() => list.length > 1 && setPick(true)} onContextMenu={e => { e.preventDefault(); setPick(true) }} aria-pressed={!!mine}>
      <span className="text-[16px] leading-none">{cur ? cur.emoji : REACTIONS[main].emoji}</span>{cur ? (lang === 'ar' ? cur.ar : cur.en) : L('تفاعل', 'React')}</button>
    {pick && list.length > 1 && <div role="menu" className="absolute z-30 bottom-full mb-1 start-0 flex gap-1 rounded-full border border-line bg-surface shadow-lg px-2 py-1">
      {list.map(r => <button key={r} role="menuitem" title={lang === 'ar' ? REACTIONS[r].ar : REACTIONS[r].en} aria-label={lang === 'ar' ? REACTIONS[r].ar : REACTIONS[r].en}
        className={`size-9 grid place-items-center rounded-full text-[20px] hover:scale-125 transition ${mine === r ? 'bg-brand-soft' : ''}`} onClick={() => { setPick(false); onPick(mine === r ? null : r) }}>{REACTIONS[r].emoji}</button>)}
    </div>}
  </div>
}

function Poll({ p }: { p: PostRow }) {
  const L = useL(); const lang = useLang()
  const vote = useVote()
  const poll = p.poll!
  const voted = poll.options.some(o => o.mine)
  const show = voted || poll.closed
  const total = Math.max(1, poll.options.reduce((a, o) => a + o.votes, 0))
  const [sel, setSel] = useState<string[]>(poll.options.filter(o => o.mine).map(o => o.id))
  const send = (ids: string[]) => vote.mutate({ id: p.id, options: ids }, { onError: err })
  return <div className="px-4 pt-3 space-y-2">
    {poll.options.map(o => { const pct = Math.round(o.votes / total * 100)
      return <button key={o.id} disabled={poll.closed || vote.isPending} onClick={() => { if (poll.multi) { const n = sel.includes(o.id) ? sel.filter(x => x !== o.id) : [...sel, o.id]; setSel(n); send(n) } else send([o.id]) }}
        className={`relative w-full overflow-hidden rounded-xl border text-start px-3 py-2.5 ${o.mine ? 'border-brand' : 'border-line hover:border-ink-3'}`}>
        {show && <span className="absolute inset-y-0 start-0 bg-brand-soft" style={{ width: `${pct}%` }} aria-hidden />}
        <span className="relative flex items-center gap-2 text-[14px]">{poll.multi && <input type="checkbox" readOnly checked={o.mine} className="accent-[var(--brand)] pointer-events-none" tabIndex={-1} />}
          <span className={`flex-1 ${o.mine ? 'font-semibold' : ''}`}>{o.text}</span>{show && <span className="num text-[13px] font-semibold">{pct}%</span>}</span></button> })}
    <div className="text-[12px] text-ink-3 num">{L(`${poll.voters} مشارك`, `${poll.voters} voters`)}{poll.multi && ` · ${L('يمكن اختيار أكثر من خيار', 'multiple choice')}`}
      {poll.closes_at && ` · ${poll.closed ? L('أُغلق', 'closed') : L('يُغلق', 'closes')} ${new Date(poll.closes_at).toLocaleString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' })}`}</div>
  </div>
}

function Comments({ id, kind, canBest, bestId, canComment }: { id: number; kind: PostRow['kind']; canBest: boolean; bestId: number | null; canComment: boolean }) {
  const L = useL()
  const q = usePost(id)
  const add = useComment()
  const [text, setText] = useState('')
  const [reply, setReply] = useState<CommentRow | null>(null)
  const list = q.data?.data.comments ?? []
  const roots = list.filter(c => !c.parent_id)
  const send = () => { if (!text.trim()) return; add.mutate({ id, body: text, parent_id: reply?.id ?? null }, { onSuccess: () => { setText(''); setReply(null) }, onError: err }) }
  return <div className="px-4 pb-4 pt-1 space-y-3">
    {q.isLoading ? <div className="grid place-items-center py-4"><Loader2 className="animate-spin text-brand" size={18} /></div>
      : roots.map(c => <div key={c.id}><CommentItem c={c} postId={id} kind={kind} canBest={canBest} isBest={bestId === c.id} onReply={() => setReply(c)} />
        <div className="ps-10 mt-2 space-y-2">{list.filter(r => r.parent_id === c.id).map(r => <CommentItem key={r.id} c={r} postId={id} kind={kind} canBest={false} isBest={false} onReply={() => setReply(c)} />)}</div></div>)}
    {canComment && <div className="space-y-2">
      {reply && <div className="text-[12px] text-ink-3 flex items-center gap-1"><Reply size={13} />{L('ردّ على', 'Replying to')} {reply.author.name.ar}<button className="ms-1" onClick={() => setReply(null)} aria-label={L('إلغاء', 'Cancel')}><X size={13} /></button></div>}
      <div className="flex items-end gap-2"><div className="flex-1"><MentionBox value={text} onChange={setText} rows={2} onSubmit={send}
        placeholder={kind === 'question' ? L('اكتب إجابتك… (@ للإشارة إلى زميل)', 'Write your answer… (@ to mention)') : L('اكتب تعليقاً… (@ للإشارة إلى زميل)', 'Write a comment… (@ to mention)')} /></div>
        <Btn kind="primary" icon={add.isPending ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />} disabled={!text.trim() || add.isPending} onClick={send} aria-label={L('إرسال', 'Send')} /></div>
    </div>}
  </div>
}

function CommentItem({ c, postId, kind, canBest, isBest, onReply }: { c: CommentRow; postId: number; kind: PostRow['kind']; canBest: boolean; isBest: boolean; onReply: () => void }) {
  const L = useL(); const lang = useLang(); const ar = lang === 'ar'
  const react = useReact(); const best = useBest(); const del = useDeleteComment(); const upd = useUpdateComment()
  const [edit, setEdit] = useState<string | null>(null)
  const [report, setReport] = useState(false)
  return <div className="flex items-start gap-2">
    <Initials p={c.author} size={32} />
    <div className="min-w-0 flex-1">
      <div className={`rounded-2xl px-3 py-2 ${isBest ? 'bg-brand-soft border border-brand/40' : 'bg-sub'} ${c.hidden ? 'opacity-60' : ''}`}>
        <div className="text-[13px] font-semibold flex flex-wrap items-center gap-2">{bi(c.author.name, lang)}{isBest && <Pill tone="brand"><CheckCircle2 size={11} />{L('أفضل إجابة', 'Best answer')}</Pill>}{c.hidden && <Pill tone="danger">{L('مخفي', 'Hidden')}</Pill>}</div>
        {edit === null ? <Body text={c.body} className="text-[13.5px]" />
          : <div className="mt-1 space-y-2"><MentionBox value={edit} onChange={setEdit} rows={2} />
            <div className="flex gap-2"><Btn size="sm" kind="primary" disabled={!edit.trim()} onClick={() => upd.mutate({ id: c.id, body: edit }, { onSuccess: () => setEdit(null), onError: err })}>{L('حفظ', 'Save')}</Btn><Btn size="sm" onClick={() => setEdit(null)}>{L('إلغاء', 'Cancel')}</Btn></div></div>}
      </div>
      <div className="mt-1 flex flex-wrap items-center gap-x-3 px-2 text-[12px] text-ink-3">
        <span>{ago(c.at, ar)}{c.edited && ` · ${L('معدّل', 'edited')}`}</span>
        <button className={`font-semibold ${c.liked ? 'text-brand' : 'hover:text-ink'}`} onClick={() => react.mutate({ target: 'comment', id: c.id, type: c.liked ? null : 'like' }, { onError: err })}>👍 {c.reactions > 0 && <span className="num">{c.reactions}</span>} {L('إعجاب', 'Like')}</button>
        <button className="font-semibold hover:text-ink" onClick={onReply}>{L('ردّ', 'Reply')}</button>
        {kind === 'question' && canBest && <button className="font-semibold hover:text-ink" onClick={() => best.mutate({ id: postId, comment_id: isBest ? null : c.id }, { onError: err })}>{isBest ? L('إلغاء أفضل إجابة', 'Unmark best') : L('أفضل إجابة', 'Mark best')}</button>}
        {c.can?.edit && edit === null && <button className="hover:text-ink" onClick={() => setEdit(c.body)}>{L('تعديل', 'Edit')}</button>}
        {c.can?.delete && <button className="hover:text-danger" onClick={() => { if (confirm(L('حذف التعليق؟', 'Delete the comment?'))) del.mutate(c.id, { onError: err }) }}>{L('حذف', 'Delete')}</button>}
        {c.can?.report && <button className="hover:text-ink" onClick={() => setReport(true)}>{L('إبلاغ', 'Report')}</button>}
      </div>
    </div>
    {report && <ReportModal target="comment" id={c.id} onClose={() => setReport(false)} />}
  </div>
}

export function ReportModal({ target, id, onClose }: { target: 'post' | 'comment'; id: number; onClose: () => void }) {
  const L = useL()
  const rep = useReport()
  const [reason, setReason] = useState('inappropriate'); const [note, setNote] = useState('')
  const reasons = [['inappropriate', L('غير لائق', 'Inappropriate')], ['offensive', L('مسيء لزميل', 'Offensive')], ['spam', L('إعلان أو تكرار', 'Spam')], ['private', L('معلومات خاصة', 'Private info')], ['other', L('سبب آخر', 'Other')]]
  return <Modal open onClose={onClose} title={L('إبلاغ فريق الإشراف', 'Report to moderators')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={rep.isPending} onClick={() => rep.mutate({ target, id, reason, note }, { onSuccess: r => { toast((r as { message: string }).message); onClose() }, onError: err })}>{L('إرسال البلاغ', 'Send report')}</Btn></>}>
    <p className="text-[13px] text-ink-2 mb-3">{L('لا يرى صاحب المحتوى اسمك. يراجع فريق الإشراف البلاغ ويقرر.', 'The author does not see your name. Moderators review and decide.')}</p>
    <div className="flex flex-wrap gap-2">{reasons.map(([k, t]) => <button key={k} onClick={() => setReason(k)} className={`h-9 px-3 rounded-lg border text-[13px] ${reason === k ? 'border-brand bg-brand-soft text-brand font-semibold' : 'border-line'}`}>{t}</button>)}</div>
    <textarea className="field !h-auto py-2 mt-3" rows={3} maxLength={500} value={note} onChange={e => setNote(e.target.value)} placeholder={L('تفاصيل (اختيارية)', 'Details (optional)')} />
  </Modal>
}

function EditPost({ p, onClose }: { p: PostRow; onClose: () => void }) {
  const L = useL()
  const upd = useUpdatePost()
  const [text, setText] = useState(p.body)
  return <Modal open onClose={onClose} title={L('تعديل المنشور', 'Edit post')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={!text.trim() || upd.isPending} onClick={() => upd.mutate({ id: p.id, body: text }, { onSuccess: () => { toast(L('حُفظ التعديل', 'Saved')); onClose() }, onError: err })}>{L('حفظ', 'Save')}</Btn></>}>
    <MentionBox value={text} onChange={setText} rows={6} autoFocus />
  </Modal>
}


