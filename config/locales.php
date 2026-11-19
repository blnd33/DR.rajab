<?php

/*
|--------------------------------------------------------------------------
| Supported locales
|--------------------------------------------------------------------------
|
| Single source of truth for languages. To add a language: add an entry
| here and create lang/{code}.json (+ lang/{code}/*.php). Nothing else.
|
| flag: shown next to the language name in the language switchers
| (resources/views/components/flag.blade.php): us, iq, krd.
|
| digits: 'latn' = 0123456789, 'arab' = ٠١٢٣٤٥٦٧٨٩ (display only; storage
| is always Western). Can be overridden per language in Settings.
|
*/

return [

    'default' => 'en',

    // Order used for translatable DB fields when the requested locale is empty.
    'fallback_chain' => ['en', 'ar'],

    'supported' => [
        'en' => [
            'name' => 'English',
            'native' => 'English',
            'flag' => 'us',
            'dir' => 'ltr',
            'digits' => 'latn',
            'font' => 'inter',
            'required_for_translations' => true,
        ],
        'ar' => [
            'name' => 'Arabic',
            'native' => 'العربية',
            'flag' => 'iq',
            'dir' => 'rtl',
            'digits' => 'arab',
            'font' => 'vazirmatn',
            'required_for_translations' => false,
        ],
        'ckb' => [
            'name' => 'Kurdish (Sorani)',
            'native' => 'کوردی',
            'flag' => 'krd',
            'dir' => 'rtl',
            'digits' => 'arab',
            'font' => 'vazirmatn',
            'required_for_translations' => false,
        ],
    ],

    'fonts' => [
        'inter' => "'Inter Variable', 'Inter', ui-sans-serif, system-ui, sans-serif",
        'vazirmatn' => "'Vazirmatn Variable', 'Vazirmatn', 'Noto Sans Arabic', Tahoma, sans-serif",
        'mono' => "'JetBrains Mono Variable', 'JetBrains Mono', ui-monospace, monospace",
    ],

];
