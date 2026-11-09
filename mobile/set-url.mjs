// يضبط عنوان الخادم الذي يفتحه التطبيق: node set-url.mjs https://hr.example.com
import fs from 'fs'
const url = (process.argv[2] || '').replace(/\/+$/, '')
if (!/^https:\/\/[a-z0-9.-]+(:\d+)?$/i.test(url)) { console.error('usage: node set-url.mjs https://host'); process.exit(1) }
const c = JSON.parse(fs.readFileSync('capacitor.config.json', 'utf8'))
c.server.url = url + '/m'; c.server.allowNavigation = [new URL(url).hostname]
fs.writeFileSync('capacitor.config.json', JSON.stringify(c, null, 2) + '\n')
console.log('server.url =', c.server.url)
