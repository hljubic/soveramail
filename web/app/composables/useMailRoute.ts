import type { RouteLocationNormalizedLoaded } from 'vue-router'
import type { Folder } from '~/types/api'
import { FOLDERS } from './useMailbox'

/**
 * Every mailbox view has its own address, so links, refresh and Back work:
 *   /            Inbox            /unread, /starred, /sent, …   a folder
 *   /category/3  a local category /label/Label_12               a Gmail label
 * The address is the source of truth; the sidebar only navigates.
 */
export type MailView = { folder: Folder } | { category: number } | { label: string }

const FOLDER_KEYS = new Set<string>(FOLDERS.map(f => f.key))

export function mailPath(view: MailView): string {
  if ('category' in view) return `/category/${view.category}`
  if ('label' in view) return `/label/${encodeURIComponent(view.label)}`
  return view.folder === 'inbox' ? '/' : `/${view.folder}`
}

/** The view an address shows, or null when it is not a mailbox address. */
export function viewOf(route: RouteLocationNormalizedLoaded): MailView | null | 'invalid' {
  const p = route.params as Record<string, string | string[] | undefined>
  const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? ''
  if (route.path === '/') return { folder: 'inbox' }
  if (route.name === 'folder') return FOLDER_KEYS.has(one(p.folder)) ? { folder: one(p.folder) as Folder } : 'invalid'
  if (route.name === 'category-id') {
    const id = Number(one(p.id))
    return Number.isFinite(id) && id > 0 ? { category: id } : 'invalid'
  }
  if (route.name === 'label-id') return one(p.id) ? { label: one(p.id) } : 'invalid'
  return null
}

export const isMailRoute = (route: RouteLocationNormalizedLoaded) => {
  const v = viewOf(route)
  return v !== null && v !== 'invalid'
}

/** Put the mailbox in the state the address asks for (no-op when it already is). */
export function applyMailView(view: MailView) {
  const mailbox = useMailbox()
  if ('category' in view) {
    if (mailbox.category.value !== view.category) mailbox.setCategory(view.category)
  } else if ('label' in view) {
    if (mailbox.label.value !== view.label) mailbox.setLabel(view.label)
  } else if (mailbox.folder.value !== view.folder || mailbox.label.value || mailbox.category.value) {
    mailbox.setFolder(view.folder)
  }
}
