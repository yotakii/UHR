import type { Bi } from '../../lib/types'

/**
 * نتيجة آخر إرسال — في الذاكرة فقط (لا تُخزَّن كلمة المرور في المتصفح أبداً).
 * تكفي شاشة النجاح وزر «متابعة حالة الطلب»؛ بعد إعادة تحميل الصفحة يدخل المتقدم من بطاقة الدخول.
 */
export interface Submission { ref: string; email: string; created_at: string; merged: boolean; job: { code: string; title: Bi }; password: string }

let last: Submission | null = null
export const setSubmission = (s: Submission) => { last = s }
export const getSubmission = () => last
export const clearSubmission = () => { last = null }
