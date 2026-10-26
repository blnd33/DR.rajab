<?php

namespace App\Filament\Admin\Resources\ExpenseCategories\Pages;

use App\Filament\Admin\Resources\ExpenseCategories\ExpenseCategoryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageExpenseCategories extends ManageRecords
{
    protected static string $resource = ExpenseCategoryResource::class;
}
