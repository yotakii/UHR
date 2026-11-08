"""فهرس معرفة «المساعد» من دليل المستخدم: مقاطع قصيرة لكل قسم (العنوان، العنوان الفرعي، النص، الرابط، وهل يخص الموظفين)
يُشغَّل من build.py فيبقى الفهرس مطابقاً للدليل — الناتج backend/resources/assistant/guide.json"""
import re, html, json, os
D = os.path.dirname(os.path.dirname(os.path.abspath(__file__))) + '/'
OUT = os.path.abspath(D + '../../../backend/resources/assistant/guide.json')

def plain(s):
    s = re.sub(r'<br\s*/?>', ' ', s)
    s = re.sub(r'</(td|th)>', ' — ', s)
    s = re.sub(r'<[^>]+>', '', s)
    return re.sub(r'\s+', ' ', html.unescape(s)).strip(' —')

chunks = []
for p in 'abcde':
    src = open(D + f'part-{p}.html', encoding='utf-8').read()
    for sm in re.finditer(r'<section([^>]*)>(.*?)</section>', src, re.S):
        sid = (re.search(r'id="([^"]+)"', sm.group(1)) or [None, None])[1]
        body = sm.group(2)
        h2 = re.search(r'<h2>(.*?)</h2>', body, re.S)
        if not h2: continue
        title = plain(h2.group(1))
        who = re.search(r'<div class="who">(.*?)</div>', body, re.S)
        who_t = plain(who.group(1)) if who else ''
        paths = re.findall(r'<span class="path">([^<]+)</span>', who.group(1)) if who else []
        me = [x.strip() for x in paths if x.strip().startswith('/me')]
        hr = [x.strip() for x in paths if x.strip().startswith('/hr')]
        employee = bool(me) or 'كل موظف' in who_t or 'الموظف' in who_t
        sub = title
        buf = []
        def flush():
            if buf:
                text = ' '.join(buf)
                for i in range(0, len(text), 700):
                    chunks.append({'section': sid, 'title': title, 'sub': sub, 'text': text[i:i + 700], 'me': me[0] if me else None, 'hr': hr[0] if hr else None, 'employee': employee})
                buf.clear()
        for el in re.finditer(r'<h3[^>]*>(.*?)</h3>|<p[^>]*>(.*?)</p>|<li[^>]*>(.*?)</li>|<tr[^>]*>(.*?)</tr>', body, re.S):
            if el.group(1) is not None:
                flush(); sub = plain(el.group(1)); continue
            t = plain(el.group(2) or el.group(3) or el.group(4) or '')
            if not t or t.startswith('يصل إليها'): continue
            buf.append(t)
            if sum(len(x) for x in buf) > 500: flush()
        flush()
json.dump(chunks, open(OUT, 'w', encoding='utf-8'), ensure_ascii=False, indent=0)
print(len(chunks), 'guide chunks')
