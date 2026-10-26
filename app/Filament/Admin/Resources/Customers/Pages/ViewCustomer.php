<?php

namespace App\Filament\Admin\Resources\Customers\Pages;

use App\Filament\Admin\Resources\Customers\CustomerResource;
use App\Models\Customer;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

/**
 * A hospital with what links to it: kits out, surgeries, returns (Module 11, P3-10).
 */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    public function getTitle(): string
    {
        /** @var Customer $customer */
        $customer = $this->getRecord();

        return $customer->name;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('code')->label(__('Code'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('type')->label(__('Type'))->badge(),
                TextEntry::make('city')->label(__('City'))->placeholder('—'),
                TextEntry::make('contact_person')->label(__('Contact person'))->placeholder('—'),
                TextEntry::make('phone')->label(__('Phone'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr'])->placeholder('—'),
                TextEntry::make('email')->label(__('Email'))->placeholder('—'),
                TextEntry::make('address')->label(__('Address'))->placeholder('—')->columnSpan(2),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
