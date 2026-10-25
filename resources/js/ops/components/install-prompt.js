/**
 * "Install app" banner. Android/desktop: native prompt via beforeinstallprompt.
 * iOS: there is no prompt — show "Share → Add to Home Screen" instructions
 * (installing is also required for push notifications on iOS).
 */
const DISMISS_KEY = 'ot.install.dismissedAt'
const DISMISS_DAYS = 14

export default () => ({
    deferred: null,
    showAndroid: false,
    showIos: false,

    init() {
        if (this.isStandalone() || this.recentlyDismissed()) return

        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault()
            this.deferred = event
            this.showAndroid = true
        })

        window.addEventListener('appinstalled', () => this.hide())

        const ua = navigator.userAgent
        this.showIos = /iPhone|iPad|iPod/.test(ua) && /Safari/.test(ua) && !/CriOS|FxiOS/.test(ua)
    },

    async install() {
        if (!this.deferred) return
        this.deferred.prompt()
        await this.deferred.userChoice
        this.deferred = null
        this.hide()
    },

    dismiss() {
        try {
            localStorage.setItem(DISMISS_KEY, String(Date.now()))
        } catch {
            // storage unavailable — just hide for this page
        }
        this.hide()
    },

    hide() {
        this.showAndroid = false
        this.showIos = false
    },

    isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true
    },

    recentlyDismissed() {
        try {
            const at = Number(localStorage.getItem(DISMISS_KEY) || 0)
            return Date.now() - at < DISMISS_DAYS * 86_400_000
        } catch {
            return false
        }
    },
})
