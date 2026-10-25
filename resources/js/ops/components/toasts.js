/**
 * Toasts. Livewire components dispatch: $this->dispatch('toast', type: 'success', message: '…')
 * Every toast has an icon + text (never color alone) and is announced to screen readers.
 */
let nextId = 1

export default () => ({
    items: [],

    init() {
        window.addEventListener('toast', (event) => this.push(event.detail ?? {}))

        // Server-side flash (redirect after an action).
        const flash = this.$el.dataset.flash ? JSON.parse(this.$el.dataset.flash) : null
        if (flash?.message) this.push(flash)
    },

    push({ type = 'info', message = '', timeout = 4000 }) {
        if (!message) return
        const id = nextId++
        this.items.push({ id, type, message })
        if (timeout > 0) setTimeout(() => this.dismiss(id), type === 'error' ? timeout * 2 : timeout)
    },

    dismiss(id) {
        this.items = this.items.filter((item) => item.id !== id)
    },
})
