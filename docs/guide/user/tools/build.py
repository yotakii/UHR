import re, html
D='/home/user/UHR/docs/guide/user/'
parts=[open(D+f'part-{p}.html').read() for p in 'abcde']
body='\n'.join(parts)
# ترقيم الأقسام وفهرس من عناوين h2
toc=[]; n=[0]
def num(m):
    n[0]+=1
    attrs, title = m.group(1), m.group(2)
    sid = re.search(r'id="([^"]+)"', attrs)
    return m.group(0)
secs=list(re.finditer(r'<section([^>]*)>\s*<h2>(.*?)</h2>', body, re.S))
out=[]; last=0
for i,m in enumerate(secs,1):
    attrs=m.group(1); title=m.group(2)
    sid=re.search(r'id="([^"]+)"', attrs)
    sid=sid.group(1) if sid else f's{i}'
    if 'id=' not in attrs: attrs+=f' id="{sid}"'
    plain=re.sub('<[^>]+>','',title)
    toc.append(f'<li><a href="#{sid}"><span class="n">{i}.</span>{plain}</a></li>')
    out.append(body[last:m.start()]); out.append(f'<section{attrs}>\n<h2><span class="num">{i}.</span>{title}</h2>'); last=m.end()
out.append(body[last:]); body=''.join(out)
page=f'''<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><title>UHR — دليل المستخدم</title><link rel="stylesheet" href="_style.css"></head>
<body>
<div class="cover">
  <div class="top"><img src="../../../frontend/src/assets/logo.png" alt=""><div><b>نظام الموارد البشرية UHR</b><br><span class="small">شركة الفرادة للتطوير والاستثمار</span></div></div>
  <div>
    <h1>دليل المستخدم</h1>
    <div class="sub">شرح مفصّل لكل صفحة وحقل ومصطلح في النظام — للموارد البشرية والمديرين والموظفين والمتقدمين</div>
    <div class="box brand" style="margin-top:14mm"><b class="t">كيف تستخدم هذا الدليل</b>
      ابدأ بالقسم الأول لتتعرف على أجزاء النظام وطريقة الدخول والصلاحيات، ثم انتقل إلى القسم الذي يخص عملك.
      كل قسم يبيّن من يصل إلى الصفحة، ومعنى كل حقل فيها، وخطوات العمل، والقواعد التي يطبقها النظام.
      وفي آخر الدليل معجم للمصطلحات وأسئلة شائعة.</div>
  </div>
  <div class="meta"><div>الإصدار: 1.0</div><div>التاريخ: تشرين الأول 2026</div><div>للاستخدام الداخلي في الشركة</div><div>القيم المذكورة افتراضية ويمكن للشركة تعديلها من الإعدادات</div></div>
</div>
<section class="toc-page"><h2>المحتويات</h2><ol class="toc" style="list-style:none;padding:0">{''.join(toc)}</ol></section>
{body}
</body></html>'''
open(D+'UHR-User-Guide.html','w').write(page)
print(len(secs),'sections')
