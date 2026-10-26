/**
 * Surgery drafts that are still only on this phone (not sent, or waiting for a connection).
 * Usage: <section x-data="localDrafts({ resumeUrl })">
 */
import { flushSurgeryDrafts } from '../offline/surgery-sync'
import { all } from '../offline/db'

export default (options = {}) => ({
    drafts: [],

    async init() {
        await this.load()
        window.addEventListener('ot:offline-store-changed', () => this.load())
        window.addEventListener('ot:surgeries-synced', () => this.$wire?.$refresh())
    },

    async load() {
        try {
            this.drafts = (await all('drafts')).sort((a, b) => String(b.updated_at).localeCompare(String(a.updated_at)))
        } catch {
            this.drafts = []
        }
    },

    resume(draft) {
        window.location.assign(`${options.resumeUrl}?draft=${encodeURIComponent(draft.client_uuid)}`)
    },

    sendNow() {
        flushSurgeryDrafts()
    },
})
