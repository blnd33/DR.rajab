// Loaded at the end of every Filament admin page.
import { installDigitNormalizer } from './shared/digits'
import { registerServiceWorker } from './shared/service-worker'

installDigitNormalizer()
registerServiceWorker()
