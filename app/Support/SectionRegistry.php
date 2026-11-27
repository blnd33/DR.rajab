<?php

namespace App\Support;

use App\Filament\Admin\Pages\SectionPage;

/**
 * Every section of the side menu has its own home page (SectionPage), opened by clicking the
 * section's name. A section is known by a fixed key (its English name), so the address stays the
 * same in English, Arabic and Kurdish.
 */
final class SectionRegistry
{
    /** key => the section's English name (as written in __()). */
    public const SECTIONS = [
        'insight' => 'Insight',
        'reports' => 'Reports',
        'clinical' => 'Clinical',
        'operations' => 'Operations',
        'stock' => 'Stock',
        'inactive-stock-value' => 'Inactive stock value',
        'inactive-stock-volume' => 'Inactive stock volume',
        'pricelist' => 'Pricelist',
        'catalogues' => 'Catalogues',
        'catalog' => 'Catalog',
        'finance' => 'Finance',
        'company-agents' => 'Company agents',
        'official-company-documents' => 'Official company documents',
        'supplier-company-documents' => 'Supplier company documents',
        'shipment-documents' => 'Shipment documents',
        'administration' => 'Administration',
        'system' => 'System',
    ];

    /**
     * The key of a section from its name as shown (in the current language).
     */
    public static function key(string $label): ?string
    {
        foreach (self::SECTIONS as $key => $english) {
            if (__($english) === $label || $english === $label) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The address of a section's home page, or null for an unknown section.
     */
    public static function url(string $label): ?string
    {
        $key = self::key($label);

        return $key === null ? null : SectionPage::getUrl(['section' => $key]);
    }

    /**
     * The section's name in the current language.
     */
    public static function label(string $key): ?string
    {
        return isset(self::SECTIONS[$key]) ? __(self::SECTIONS[$key]) : null;
    }
}
