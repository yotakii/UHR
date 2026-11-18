import { Loader2 } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import { useCertificate } from '../../lib/externals'
import { Paper } from '../../ui/docs'
import { Btn } from '../../ui/kit'
import { navigate } from '../../lib/router'

/** شهادة تطوع (POL-VL-07) بالساعات المعتمدة والمهام ورمز تحقق يُفحص على /verify/{الرمز} */
export function CertPrint({ id, portal = false }: { id: number; portal?: boolean }) {
  const L = useL(); const lang = useLang()
  const { data: c } = useCertificate(id, portal)
  if (!c) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const url = `${location.origin}/verify/${c.code}`
  return <div className="max-w-[860px] mx-auto">
    <div className="mb-3" data-noprint><Btn kind="ghost" onClick={() => navigate(portal ? '/ext' : '/hr/externals')}>{L('رجوع', 'Back')}</Btn></div>
    <Paper code="VLC" title={L('شهادة تطوع', 'Volunteering certificate')} sub={c.no}>
      <div className="text-center space-y-4 py-6 text-[15px] leading-8">
        <p>{L('تشهد', 'This is to certify that')} <b>{bi(c.company, lang)}</b> {L('بأن', '')}</p>
        <p className="text-[22px] font-black">{bi(c.name, lang)}</p>
        <p>{L('قد تطوّع لدينا في', 'volunteered with us on')} «{c.title}» {L('خلال الفترة من', 'from')} <span className="num">{fmtDate(c.from, lang)}</span> {L('إلى', 'to')} <span className="num">{fmtDate(c.to, lang)}</span>،
          {L(' بمجموع ساعات معتمدة', ' with a total of')} <b className="num">{c.hours}</b> {L('ساعة', 'approved hours')}.</p>
        {c.tasks && <p className="text-ink-2 text-[14px]">{L('المهام', 'Tasks')}: {c.tasks}</p>}
        <p className="text-ink-2 text-[14px]">{L('مُنحت هذه الشهادة تقديراً لجهوده دون أن تنشئ علاقة عمل.', 'This certificate is given in appreciation and does not create an employment relationship.')}</p>
      </div>
      <div className="mt-6 text-[12.5px] text-ink-3 text-center">{L('رمز التحقق', 'Verification code')}: <b className="ltr font-mono">{c.code}</b> · <span className="ltr">{url}</span></div>
    </Paper>
  </div>
}
