/**
 * عنوان الصفحة ووصفها وصورة المشاركة وبيانات JobPosting (JSON-LD) للصفحات العامة —
 * Google يقرأ البيانات المنظمة المولّدة بالجافاسكربت (SRS §10.15).
 */
type Meta = { title: string; description?: string; image?: string; url?: string; jsonLd?: object | null }

function tag(attr: 'name' | 'property', key: string, content?: string) {
  let el = document.head.querySelector<HTMLMetaElement>(`meta[${attr}="${key}"]`)
  if (!content) { el?.remove(); return }
  if (!el) { el = document.createElement('meta'); el.setAttribute(attr, key); document.head.appendChild(el) }
  el.content = content
}

export function setMeta({ title, description, image, url, jsonLd }: Meta) {
  document.title = title
  tag('name', 'description', description)
  tag('property', 'og:title', title)
  tag('property', 'og:description', description)
  tag('property', 'og:image', image ? new URL(image, location.origin).href : undefined)
  tag('property', 'og:url', url ?? location.href)
  tag('property', 'og:type', 'website')
  let ld = document.getElementById('uhr-jsonld') as HTMLScriptElement | null
  if (!jsonLd) { ld?.remove(); return }
  if (!ld) { ld = document.createElement('script'); ld.id = 'uhr-jsonld'; ld.type = 'application/ld+json'; document.head.appendChild(ld) }
  ld.textContent = JSON.stringify(jsonLd)
}

const TYPE: Record<string, string> = { full_time: 'FULL_TIME', part_time: 'PART_TIME', shifts: 'FULL_TIME', contract: 'TEMPORARY', remote: 'FULL_TIME' }

/** schema.org JobPosting لصفحة الشاغر */
export function jobPostingLd(j: { title: { ar: string; en: string }; summary: { ar: string; en: string }; requirements?: { ar: string; en: string }[]; posted_at: string; closes_at?: string | null; type_code: string; city: { ar: string; en: string }; company: { name: { ar: string; en: string }; logo: string | null; slug: string } }, lang: 'ar' | 'en') {
  const t = (b: { ar: string; en: string }) => b[lang]
  return {
    '@context': 'https://schema.org/', '@type': 'JobPosting',
    title: t(j.title),
    description: `<p>${t(j.summary)}</p>${j.requirements?.length ? '<ul>' + j.requirements.map(r => `<li>${t(r)}</li>`).join('') + '</ul>' : ''}`,
    datePosted: j.posted_at, ...(j.closes_at ? { validThrough: j.closes_at } : {}),
    employmentType: TYPE[j.type_code] ?? 'FULL_TIME',
    hiringOrganization: { '@type': 'Organization', name: t(j.company.name), sameAs: `${location.origin}/c/${j.company.slug}`, ...(j.company.logo ? { logo: new URL(j.company.logo, location.origin).href } : {}) },
    jobLocation: { '@type': 'Place', address: { '@type': 'PostalAddress', addressLocality: j.city.en, addressCountry: 'SY' } },
    directApply: true,
  }
}
