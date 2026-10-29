import { chromium } from 'playwright'
const b = await chromium.launch(); const p = await b.newPage()
await p.goto('file:///home/user/UHR/docs/guide/user/UHR-User-Guide.html', { waitUntil: 'networkidle' })
await p.evaluate(() => document.fonts.ready)
await p.pdf({ path: '/home/user/UHR/docs/UHR-User-Guide.pdf', format: 'A4', printBackground: true, preferCSSPageSize: true,
  displayHeaderFooter: true, headerTemplate: '<span></span>',
  footerTemplate: '<div style="width:100%;font-size:8px;color:#6b7680;padding:0 14mm;display:flex;justify-content:space-between;font-family:sans-serif"><span>UHR — دليل المستخدم</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>' })
await b.close()
