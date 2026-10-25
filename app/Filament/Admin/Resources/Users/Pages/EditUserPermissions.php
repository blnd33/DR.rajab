<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Forms\PermissionChecklist;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Branch;
use App\Models\User;
use App\Services\Permissions\PermissionRegistry;
use App\Services\Users\UserService;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Per-user permissions: extra "what" beyond the role + per-permission "where".
 */
class EditUserPermissions extends EditRecord
{
    protected static string $resource = UserResource::class;

    private const ALL_BRANCHES = 'all';

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return $record instanceof User && (Auth::user()?->can('managePermissions', $record) ?? false);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Permissions: :name', ['name' => $this->getRecord()->getAttribute('name')]);
    }

    public function getBreadcrumb(): string
    {
        return __('Permissions');
    }

    /**
     * Devices are managed on the main edit page only.
     */
    public function getRelationManagers(): array
    {
        return [];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Extra permissions'))
                    ->description(__('Granted on top of the role. Permissions already in the role are marked.'))
                    ->schema([
                        PermissionChecklist::make('permissions', fn () => $this->rolePermissions()),
                    ]),

                Section::make(__('Branch scope per permission'))
                    ->description(__('By default every permission applies to the user\'s branches. Add a row to change the branches for one permission.'))
                    ->schema([
                        Repeater::make('overrides')
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->addActionLabel(__('Add branch rule'))
                            ->columns(['default' => 1, 'md' => 2])
                            ->schema([
                                Select::make('permission')
                                    ->label(__('Permission'))
                                    ->options(fn () => $this->scopedPermissionOptions())
                                    ->searchable()
                                    ->required()
                                    ->distinct()
                                    ->native(false),
                                Select::make('branches')
                                    ->label(__('Branches'))
                                    ->multiple()
                                    ->options(fn () => [self::ALL_BRANCHES => __('All branches')] + Branch::query()->orderBy('code')->get()->mapWithKeys(fn (Branch $b) => [(string) $b->id => $b->label()])->all())
                                    ->required()
                                    ->native(false),
                            ]),
                    ]),
            ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->getRecord();

        $data['permissions'] = PermissionChecklist::toGroups($user->getDirectPermissions()->pluck('name')->all());

        $data['overrides'] = $user->permissionBranchOverrides()
            ->with('permission')
            ->get()
            ->groupBy(fn ($row) => $row->permission->name)
            ->map(fn ($rows, $permission) => [
                'permission' => $permission,
                'branches' => $rows->map(fn ($row) => $row->branch_id === null ? self::ALL_BRANCHES : (string) $row->branch_id)->values()->all(),
            ])
            ->values()
            ->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $service = app(UserService::class);

        $service->update($record, ['permissions' => PermissionChecklist::flatten($data['permissions'] ?? [])], Auth::user());

        $overrides = [];

        foreach ($data['overrides'] ?? [] as $row) {
            $branches = $row['branches'] ?? [];
            $overrides[$row['permission']] = in_array(self::ALL_BRANCHES, $branches, true)
                ? [null]
                : array_map('intval', $branches);
        }

        $service->syncPermissionBranches($record, $overrides);

        return $record;
    }

    protected function getRedirectUrl(): ?string
    {
        return UserResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /**
     * @return list<string>
     */
    private function rolePermissions(): array
    {
        /** @var User $user */
        $user = $this->getRecord();

        return $user->getPermissionsViaRoles()->pluck('name')->unique()->values()->all();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function scopedPermissionOptions(): array
    {
        $options = [];

        foreach (PermissionRegistry::all() as $name => $definition) {
            if ($definition['scoped']) {
                $options[PermissionRegistry::groupLabel($definition['group'])][$name] = __($definition['label']);
            }
        }

        return $options;
    }
}
