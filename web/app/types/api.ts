export type SyncStatus = 'idle' | 'queued' | 'syncing' | 'error' | 'needs_reauth' | 'disconnected'

/** A sync is queued or running right now. */
export const isSyncBusy = (s: SyncStatus) => s === 'syncing' || s === 'queued'

export type Encryption = 'ssl' | 'starttls' | 'none'

export interface SyncProgress {
  mode: 'full' | 'backfill' | 'range'
  total: number
  done: number
  started_at: string
  updated_at: string
}

/** IMAP/SMTP servers of an account (never the password). */
export interface ImapServers {
  host: string
  port: number
  encryption: Encryption
  username: string
  smtp_host: string
  smtp_port: number
  smtp_encryption: Encryption
}

/** What the app sends to connect an IMAP/SMTP mailbox. */
export interface ImapConnectPayload {
  email: string
  display_name?: string
  password: string
  imap: { host: string; port: number; encryption: Encryption; username?: string }
  smtp: { host: string; port: number; encryption: Encryption; username?: string; password?: string }
}

export interface Account {
  id: number
  provider: 'gmail' | 'imap'
  imap?: ImapServers
  email: string
  display_name: string | null
  avatar_url: string | null
  scopes: string[]
  can_modify: boolean
  initial_sync_done: boolean
  sync_status: SyncStatus
  sync_error: string | null
  last_synced_at: string | null
  /** Live progress of a running fetch: `done` of `total` messages are on this computer. */
  sync_progress?: SyncProgress | null
  /** Gmail's own message count (null until a fetch asked for it). */
  mailbox_total?: number | null
  messages_count?: number
  unread_count?: number
  created_at: string
}

export interface Address {
  name: string | null
  email: string
}

export interface Label {
  id: number
  provider_label_id: string
  name: string
  type: 'system' | 'user'
  color_bg: string | null
  color_fg: string | null
  visible: boolean
}

export interface MessageSummary {
  id: number
  account_id: number
  provider_message_id: string
  thread_id: string | null
  subject: string | null
  from: Address
  to: Address[]
  snippet: string | null
  label_ids: string[]
  is_unread: boolean
  is_starred: boolean
  has_attachments: boolean
  has_draft?: boolean
  /** Carries an attachment screened as dangerous (executable, script, macro, archive, …). */
  is_risky: boolean
  /** Carries an archive: contents unknown until downloaded (amber, no lock). */
  has_caution: boolean
  /** The user confirmed they know before opening it. */
  risk_acknowledged: boolean
  /** Judged once by the local model while the message was unread; null = not judged (yet). */
  urgency?: Urgency | null
  /** Conversation rows only: messages in the thread, unread ones, urgency of the newest judged unread, a saved draft. */
  thread_count?: number
  thread_unread?: number
  thread_urgency?: Urgency | null
  thread_has_draft?: boolean
  /** Conversation rows only: the local category of the thread. */
  category_id?: number | null
  /** Conversation rows only: up to three attachments shown as chips under the row, and how many more. */
  attachment_chips?: { filename: string | null; mime_type: string | null; risk_level: 'danger' | 'caution' | null }[]
  attachments_more?: number
  sent_at: string | null
  received_at: string
  /** Smart search only: similarity to the topic (0..1). */
  score?: number
}

export type Urgency = 'high' | 'medium' | 'low'

/** A local category (exists only in Sovera Mail). */
export interface Category {
  id: number
  name: string
  description: string | null
  color: string
  position: number
  is_fallback: boolean
}

/** A category with the counts of one account (sidebar). */
export interface CategorySummary extends Category {
  threads: number
  unread: number
}

export interface CategoriesMeta {
  active: boolean
  model: string | null
  progress: { total: number; done: number; unread_pending: number }
}

export interface AttachmentMeta {
  id: number
  filename: string | null
  mime_type: string | null
  size: number
  is_inline: boolean
  content_id: string | null
  /** 'danger' = blocked for good; 'caution' = archive, download asks for confirmation; null = clean. */
  risk_level: 'danger' | 'caution' | null
  /** Human-readable reason for the level, or null. */
  risk: string | null
  previewable: boolean
  url: string | null
  download_url: string | null
}

export interface MessageDetail extends MessageSummary {
  message_id_header: string | null
  in_reply_to: string | null
  cc: Address[]
  bcc: Address[]
  reply_to: Address[]
  body_text: string | null
  body_html: string | null
  size_estimate: number
  attachments: AttachmentMeta[]
  local_draft?: LocalDraft | null
  /** The conversation's local category; 'user' = picked by hand (the model never changes it). */
  category_id?: number | null
  category_source?: 'ai' | 'user' | null
}

export interface LocalDraft {
  kind: 'reply' | 'forward'
  to: string[]
  cc: string[]
  bcc: string[]
  subject: string | null
  body: string
  updated_at?: string | null
}

export interface CursorPage<T> {
  data: T[]
  links: { first: string | null; last: string | null; prev: string | null; next: string | null }
  meta: { path: string; per_page: number; next_cursor: string | null; prev_cursor: string | null; /** Conversations in the view (first page only). */ total?: number }
}

export type Folder = 'inbox' | 'starred' | 'unread' | 'sent' | 'drafts' | 'important' | 'archive' | 'spam' | 'trash' | 'all'

export interface Health {
  app: string
  version: string
  status: 'ok' | 'degraded'
  database: 'ok' | 'error'
  google_configured: boolean
  api_key_required: boolean
  time: string
}

export type RemoteResult = 'ok' | 'skipped' | 'failed'

export type ComposeMode =
  | { kind: 'reply' | 'forward'; original: MessageDetail; draft?: LocalDraft | null; /** Reply to everyone (sender + To + Cc). */ all?: boolean }
  | { kind: 'new'; original?: undefined; draft?: undefined }

export interface ComposePayload {
  to: string[]
  cc?: string[]
  bcc?: string[]
  subject?: string
  body: string
  include_attachments?: boolean
  /** The AI draft this text started from, so the API can learn from the edits (send only). */
  ai_suggestion_id?: number | null
}
