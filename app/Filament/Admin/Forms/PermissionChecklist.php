<?php

namespace App\Filament\Admin\Forms;

use App\Services\Permissions\PermissionRegistry;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;

/**
 * Permissions grouped by module, one collapsible section per group.
 * State shape: [group => [permission names]] under the given state path;
 * use self::flatten() before saving.
 */
final class PermissionChecklist
{
    /**
     * @param  (Closure(): list<string>)|null  $inherited  permissions already granted (e.g. by role) — shown as a hint
     */
    public static function make(string $statePath = 'permissions', ?Closure $inherited = null): Group
    {
        $sections = [];

        foreach (PermissionRegistry::grouped() as $group => $options) {
            $sections[] = Section::make(PermissionRegistry::groupLabel($group))
                ->collapsible()
                ->compact()
                ->schema([
                    CheckboxList::make($group)
                        ->hiddenLabel()
                        ->options($options)
                        ->descriptions(function () use ($options, $inherited) {
                            $granted = $inherited ? $inherited() : [];
                            $descriptions = [];

                            foreach (array_keys($options) as $name) {
                                $parts = [];

                                if (in_array($name, $granted, true)) {
                                    $parts[] = __('Already included in the role');
                                }

                                if (PermissionRegistry::isScoped($name)) {
                                    $parts[] = __('Limited to branches');
                                }

                                if ($parts !== []) {
                                    $descriptions[$name] = implode(' · ', $parts);
                                }
                            }

                            return $descriptions;
                        })
                        ->columns(['default' => 1, 'md' => 2])
                        ->bulkToggleable()
                        ->gridDirection('row'),
                ]);
        }

        return Group::make($sections)->statePath($statePath);
    }

    /**
     * @param  list<string>  $permissions
     * @return array<string, list<string>>
     */
    public static function toGroups(array $permissions): array
    {
        $groups = [];

        foreach (PermissionRegistry::all() as $name => $definition) {
            $groups[$definition['group']] ??= [];

            if (in_array($name, $permissions, true)) {
                $groups[$definition['group']][] = $name;
            }
        }

        return $groups;
    }

    /**
     * @param  array<string, list<string>|null>|null  $groups
     * @return list<string>
     */
    public static function flatten(?array $groups): array
    {
        return array_values(array_unique(array_merge(...array_values(array_map(
            fn ($names) => array_values($names ?? []),
            $groups ?? [],
        )))));
    }
}
