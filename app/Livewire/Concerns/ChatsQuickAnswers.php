<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Services\Insight\QuickAnswers;
use App\Services\Insight\QuickQuestions;
use App\Services\Insight\SummarySettings;
use Illuminate\Support\Facades\Auth;

/**
 * The Quick answers conversation, shared by the admin page and the phone screen: tap a question,
 * or type / scan a code. The messages live only in the page (nothing typed is stored).
 */
trait ChatsQuickAnswers
{
    /** Messages kept on screen. */
    private const MAX_MESSAGES = 30;

    /** @var list<array<string, mixed>> oldest first */
    public array $messages = [];

    public string $text = '';

    public string $period = 'month';

    public ?string $from = null;

    public ?string $until = null;

    public ?string $branch = null;

    public string $currency = 'USD';

    /**
     * Links to the phone screens (true) or the admin panel (false).
     */
    abstract protected function onPhone(): bool;

    public function askQuestion(string $key): void
    {
        abort_unless(QuickAnswers::canUse($this->asker()), 403);

        $question = app(QuickQuestions::class)->find($key);

        if ($question === null) {
            return;
        }

        $this->push(['id' => uniqid('qa', true), 'from' => 'me', 'text' => $question->label]);
        $this->push(app(QuickAnswers::class)->ask($this->asker(), $key, $this->choices()));
    }

    public function send(): void
    {
        abort_unless(QuickAnswers::canUse($this->asker()), 403);

        $text = trim($this->text);
        $this->text = '';

        if ($text === '') {
            return;
        }

        $this->push(['id' => uniqid('qa', true), 'from' => 'me', 'text' => mb_substr($text, 0, 200)]);
        $this->push(app(QuickAnswers::class)->lookup($this->asker(), $text, $this->onPhone()));
    }

    /**
     * A code from the camera or a scanner gun.
     */
    public function handleScan(string $raw, string $source = 'manual'): void
    {
        $this->text = $raw;
        $this->send();
    }

    public function clearChat(): void
    {
        $this->messages = [];
    }

    public function updatedPeriod(): void
    {
        if (! in_array($this->period, QuickAnswers::PERIODS, true)) {
            $this->period = 'month';
        }
    }

    public function updatedBranch(): void
    {
        if ($this->branch !== null && $this->branch !== '' && ! array_key_exists((int) $this->branch, SummarySettings::branchOptions($this->asker()))) {
            $this->branch = null;
        }
    }

    public function updatedCurrency(): void
    {
        if (! in_array($this->currency, config('orthotrack.currencies'), true)) {
            $this->currency = 'USD';
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function chatViewData(): array
    {
        $user = $this->asker();

        return [
            'menu' => app(QuickAnswers::class)->menu($user),
            'periods' => QuickAnswers::periodOptions(),
            'branches' => SummarySettings::branchOptions($user),
            'currencies' => config('orthotrack.currencies'),
        ];
    }

    /**
     * @return array{period: string, from: ?string, until: ?string, branch: ?string, currency: string}
     */
    private function choices(): array
    {
        return [
            'period' => $this->period,
            'from' => $this->from,
            'until' => $this->until,
            'branch' => $this->branch,
            'currency' => $this->currency,
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function push(array $message): void
    {
        $this->messages[] = $message;
        $this->messages = array_slice($this->messages, -self::MAX_MESSAGES);
        $this->dispatch('quick-answer', focus: (bool) ($message['focus'] ?? false));
    }

    private function asker(): User
    {
        /** @var User */
        return Auth::user();
    }
}
