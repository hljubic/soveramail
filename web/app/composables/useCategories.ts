import type { CategoriesMeta, Category, CategorySummary } from '~/types/api'

/**
 * Local categories ("Clients", "Project Alpha", ...) with the active account's
 * counts, and how far the local model has come sorting the mailbox.
 */
export function useCategories() {
  const api = useApi()
  const { activeAccountId } = useAccounts()

  const categories = useState<CategorySummary[]>('categories', () => [])
  const meta = useState<CategoriesMeta | null>('categories-meta', () => null)

  async function load(accountId: number | null = activeAccountId.value) {
    if (!accountId) {
      categories.value = []
      meta.value = null
      return
    }
    try {
      const res = await api.get<{ data: CategorySummary[]; meta: CategoriesMeta }>(`/accounts/${accountId}/categories`)
      categories.value = res.data
      meta.value = res.meta
    } catch {
      /* the sidebar simply shows no categories */
    }
  }

  const byId = (id: number | null | undefined) => categories.value.find(c => c.id === id) ?? null

  async function create(input: Pick<Category, 'name' | 'description' | 'color'>) {
    await api.post('/categories', input)
    await load()
  }

  async function update(id: number, input: Partial<Pick<Category, 'name' | 'description' | 'color' | 'position'>>) {
    await api.put(`/categories/${id}`, input)
    await load()
  }

  async function remove(id: number) {
    await api.del(`/categories/${id}`)
    await load()
  }

  /** Sort everything again (hand-picked categories stay). */
  async function reclassify() {
    await api.post('/categories/reclassify')
    await load()
  }

  async function classifyNow(accountId: number | null = activeAccountId.value) {
    if (accountId) await api.post(`/accounts/${accountId}/classify`)
  }

  /** Pick the category of a conversation by hand. */
  async function setForMessage(messageId: number, categoryId: number) {
    await api.put(`/messages/${messageId}/category`, { category_id: categoryId })
    await load()
  }

  /** Move several conversations (by any of their messages) to one category at once. */
  async function setForMessages(messageIds: number[], categoryId: number): Promise<number> {
    const res = await api.put<{ data: { conversations: number } }>('/messages/category', { message_ids: messageIds, category_id: categoryId })
    await load()
    return res.data.conversations
  }

  return { categories, meta, load, byId, create, update, remove, reclassify, classifyNow, setForMessage, setForMessages }
}
