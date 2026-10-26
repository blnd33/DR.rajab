<?php

namespace App\Filament\Admin\Support;

use App\Exceptions\Domain\StockRuleViolation;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Helpers for admin screens that call document services.
 */
final class ServiceAction
{
    /**
     * Run a service call from a Filament action: rule violations become a red notification
     * and the modal stays open.
     *
     * @template T
     *
     * @param  callable(User): T  $callback
     * @return T|null
     */
    public static function run(?Action $action, callable $callback, ?string $success = null): mixed
    {
        try {
            $result = $callback(self::user());
        } catch (StockRuleViolation|AuthorizationException $e) {
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();
            $action?->halt();

            return null;
        } catch (ValidationException $e) {
            Notification::make()->title(implode(' ', array_merge(...array_values($e->errors()))))->danger()->persistent()->send();
            $action?->halt();

            return null;
        }

        if ($success !== null) {
            Notification::make()->title($success)->success()->send();
        }

        return $result;
    }

    /**
     * Rows whose branch (in any of $columns) is in the user's scope for $permission.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @param  list<string>  $columns
     * @return TBuilder
     */
    public static function scoped(Builder $query, string $permission, array $columns): Builder
    {
        $ids = app(BranchScopeResolver::class)->branchIdsFor(self::user(), $permission);

        if ($ids === null) {
            return $query;
        }

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($columns, $ids) {
            foreach ($columns as $column) {
                $q->orWhereIn($column, $ids);
            }
        });
    }

    /**
     * Branch options the user may act in.
     *
     * @return array<int, string>
     */
    public static function branchOptions(string $permission): array
    {
        return app(BranchScopeResolver::class)->selectableBranches(self::user(), $permission)->get()
            ->mapWithKeys(fn ($b) => [$b->id => $b->label()])->all();
    }

    public static function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
