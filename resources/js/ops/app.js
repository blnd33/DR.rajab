/**
 * Operations app entry. Livewire + Alpine are bundled here (not injected) so our
 * Alpine components are registered before Alpine starts.
 */
import { Alpine, Livewire } from '../../../vendor/livewire/livewire/dist/livewire.esm'

import { installDigitNormalizer } from '../shared/digits'
import { registerServiceWorker } from '../shared/service-worker'
import connectivity from './components/connectivity'
import countQueue from './components/count-queue'
import installPrompt from './components/install-prompt'
import scanner from './scanner/scanner'
import toasts from './components/toasts'

Alpine.data('connectivity', connectivity)
Alpine.data('countQueue', countQueue)
Alpine.data('installPrompt', installPrompt)
Alpine.data('scanner', scanner)
Alpine.data('toasts', toasts)

installDigitNormalizer()
registerServiceWorker()

Livewire.start()
