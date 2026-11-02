import { Copy, MailCheck } from 'lucide-react'
import { useL } from '../lib/store'
import { Btn, Modal, toast } from './kit'

/** بعد إنشاء حساب أو طلب رابط كلمة مرور: أُرسل الرابط بالبريد، ويمكن نسخه وتسليمه بقناة أخرى إن تأخر البريد */
export function InviteLinkModal({ email, link, reset, onClose }: { email: string; link: string; reset?: boolean; onClose: () => void }) {
  const L = useL()
  return <Modal open onClose={onClose} title={reset ? L('رابط تعيين كلمة المرور', 'Password link') : L('رابط تفعيل الحساب', 'Activation link')} footer={<Btn kind="primary" onClick={onClose}>{L('تم', 'Done')}</Btn>}>
    <p className="flex items-start gap-2 text-[13.5px] text-ink-2"><MailCheck size={18} className="text-brand shrink-0 mt-0.5" />
      <span>{reset ? L('أُرسل إلى البريد رابط يختار به صاحب الحساب كلمة مرور جديدة:', 'A link to choose a new password was emailed to:') : L('أُرسل إلى البريد رابط يختار به صاحب الحساب كلمة مروره ويدخل مباشرة:', 'A link to choose a password and sign in was emailed to:')} <b className="ltr inline-block">{email}</b></span></p>
    <p className="mt-3 text-[12.5px] text-ink-3">{L('إن لم يصله البريد، انسخ الرابط وأرسله إليه بنفسك. يعمل لمرة واحدة، ويُبطله أي رابط جديد.', 'If the email does not arrive, copy the link and send it yourself. It works once, and a new link cancels it.')}</p>
    <div className="mt-2 flex items-center gap-2 rounded-lg bg-sub px-3 py-2"><span className="flex-1 min-w-0 ltr text-start text-[12.5px] break-all select-all">{link}</span>
      <button className="text-ink-3 hover:text-brand shrink-0" onClick={() => { void navigator.clipboard?.writeText(link); toast(L('نُسخ الرابط', 'Link copied')) }} aria-label={L('نسخ', 'Copy')}><Copy size={16} /></button></div>
  </Modal>
}
