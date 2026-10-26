<?php

namespace App\Livewire\Ops\Concerns;

use App\Exceptions\Domain\StockRuleViolation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Run a service call from an ops screen: rule violations become an error toast (+ error buzz
 * for scans) instead of an exception page.
 */
trait RunsStockActions
{
    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T|null
     */
    protected function attempt(callable $action, ?string $success = null, bool $scan = false): mixed
    {
        try {
            $result = $action();
        } catch (StockRuleViolation|AuthorizationException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            if ($scan) {
                $this->dispatch('scan-feedback', type: 'error');
            }

            return null;
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: implode(' ', array_merge(...array_values($e->errors()))));

            return null;
        }

        if ($success !== null) {
            $this->dispatch('toast', type: 'success', message: $success);
        }

        if ($scan) {
            $this->dispatch('scan-feedback', type: 'success');
        }

        return $result;
    }

    protected function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
