import type { Address } from '~/types/api'

export function formatAddress(a: Address | null | undefined): string {
  if (!a) return ''
  return a.name ? a.name : a.email
}

export function formatAddressFull(a: Address | null | undefined): string {
  if (!a) return ''
  return a.name ? `${a.name} <${a.email}>` : a.email
}

export function formatAddressList(list: Address[] | null | undefined): string {
  return (list ?? []).map(formatAddressFull).join(', ')
}

const sameDay = (a: Date, b: Date) =>
  a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()

/** Gmail-style short date: time today, "Mon 8" this week, "8 Sep" this year, else "8 Sep 2023". */
export function formatListDate(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  const now = new Date()
  if (sameDay(d, now)) return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
  const diffDays = (now.getTime() - d.getTime()) / 86_400_000
  if (diffDays < 6) return d.toLocaleDateString(undefined, { weekday: 'short' })
  if (d.getFullYear() === now.getFullYear()) return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })
  return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
}

export function formatFullDate(iso: string | null | undefined): string {
  if (!iso) return ''
  return new Date(iso).toLocaleString(undefined, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function formatRelative(iso: string | null | undefined): string {
  if (!iso) return 'never'
  const diff = (Date.now() - new Date(iso).getTime()) / 1000
  if (diff < 45) return 'just now'
  if (diff < 3600) return `${Math.round(diff / 60)} min ago`
  if (diff < 86_400) return `${Math.round(diff / 3600)} h ago`
  return `${Math.round(diff / 86_400)} d ago`
}

export function formatBytes(n: number | null | undefined): string {
  if (!n) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  let i = 0
  let v = n
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024
    i++
  }
  return `${v < 10 && i > 0 ? v.toFixed(1) : Math.round(v)} ${units[i]}`
}

export function initials(name: string | null | undefined, email: string | null | undefined): string {
  const src = (name || email || '?').trim()
  const parts = src.split(/[\s.@_-]+/).filter(Boolean)
  const first = parts[0]?.[0] ?? '?'
  const second = parts.length > 1 ? parts[1]?.[0] ?? '' : ''
  return (first + second).toUpperCase()
}

/** Stable pastel colour per e-mail address for avatars. */
export function avatarColor(seed: string | null | undefined): string {
  let h = 0
  for (const ch of seed || '') h = (h * 31 + ch.charCodeAt(0)) >>> 0
  const hue = h % 360
  return `hsl(${hue} 55% 45%)`
}

/**
 * Date on an open message, like the classic clients: "14:12 (7 hours ago)" today,
 * "9 Nov, 14:12 (2 days ago)" this year, "9 Nov 2025, 14:12" before that.
 */
export function formatMailDate(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  const now = new Date()
  const time = d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
  const sameDay = d.toDateString() === now.toDateString()
  const day = d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', ...(d.getFullYear() === now.getFullYear() ? {} : { year: 'numeric' }) })
  const stamp = sameDay ? time : `${day}, ${time}`
  if (d.getFullYear() !== now.getFullYear()) return stamp

  const mins = Math.round((now.getTime() - d.getTime()) / 60000)
  const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })
  const ago = mins < 60 ? rtf.format(-Math.max(1, mins), 'minute')
    : mins < 60 * 24 ? rtf.format(-Math.round(mins / 60), 'hour')
      : rtf.format(-Math.round(mins / 1440), 'day')
  return `${stamp} (${ago})`
}
