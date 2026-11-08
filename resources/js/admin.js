// Loaded at the end of every Filament admin page.
import { installChartPalette } from './admin/chart-palette'
import { installDigitNormalizer } from './shared/digits'
import { registerServiceWorker } from './shared/service-worker'

installDigitNormalizer()
registerServiceWorker()
installChartPalette()
