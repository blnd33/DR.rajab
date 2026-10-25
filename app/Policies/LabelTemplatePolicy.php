<?php

namespace App\Policies;

use App\Models\LabelTemplate;
use App\Models\User;

/**
 * Thermal label layouts.
 */
class LabelTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('products.print_labels');
    }

    public function view(User $user, LabelTemplate $labelTemplate): bool
    {
        return $user->can('products.print_labels');
    }

    public function create(User $user): bool
    {
        return $user->can('labels.templates');
    }

    public function update(User $user, LabelTemplate $labelTemplate): bool
    {
        return $user->can('labels.templates');
    }

    public function delete(User $user, LabelTemplate $labelTemplate): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
