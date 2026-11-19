<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

/**
 * What an Arabic number plate says next to the number: "Iraq" or one of the governorates.
 */
enum PlateRegion: string implements HasLabel
{
    use EnumHelpers;

    case Iraq = 'iraq';
    case Duhok = 'duhok';
    case Erbil = 'erbil';
    case Sulaymaniyah = 'sulaymaniyah';
    case Halabja = 'halabja';
    case Kirkuk = 'kirkuk';
    case Nineveh = 'nineveh';
    case Baghdad = 'baghdad';
    case Basra = 'basra';
    case Anbar = 'anbar';
    case Babil = 'babil';
    case Karbala = 'karbala';
    case Najaf = 'najaf';
    case Wasit = 'wasit';
    case Diyala = 'diyala';
    case Salahaddin = 'salahaddin';
    case DhiQar = 'dhi_qar';
    case Maysan = 'maysan';
    case Muthanna = 'muthanna';
    case Qadisiyyah = 'qadisiyyah';

    public function getLabel(): string
    {
        return match ($this) {
            self::Iraq => __('Iraq'),
            self::Duhok => __('Duhok'),
            self::Erbil => __('Erbil'),
            self::Sulaymaniyah => __('Sulaymaniyah'),
            self::Halabja => __('Halabja'),
            self::Kirkuk => __('Kirkuk'),
            self::Nineveh => __('Nineveh'),
            self::Baghdad => __('Baghdad'),
            self::Basra => __('Basra'),
            self::Anbar => __('Anbar'),
            self::Babil => __('Babil'),
            self::Karbala => __('Karbala'),
            self::Najaf => __('Najaf'),
            self::Wasit => __('Wasit'),
            self::Diyala => __('Diyala'),
            self::Salahaddin => __('Saladin'),
            self::DhiQar => __('Dhi Qar'),
            self::Maysan => __('Maysan'),
            self::Muthanna => __('Muthanna'),
            self::Qadisiyyah => __('Al-Qadisiyyah'),
        };
    }

    /**
     * The word as it is printed on the plate (always Arabic).
     */
    public function onPlate(): string
    {
        return match ($this) {
            self::Iraq => 'العراق',
            self::Duhok => 'دهوك',
            self::Erbil => 'أربيل',
            self::Sulaymaniyah => 'السليمانية',
            self::Halabja => 'حلبجة',
            self::Kirkuk => 'كركوك',
            self::Nineveh => 'نينوى',
            self::Baghdad => 'بغداد',
            self::Basra => 'البصرة',
            self::Anbar => 'الأنبار',
            self::Babil => 'بابل',
            self::Karbala => 'كربلاء',
            self::Najaf => 'النجف',
            self::Wasit => 'واسط',
            self::Diyala => 'ديالى',
            self::Salahaddin => 'صلاح الدين',
            self::DhiQar => 'ذي قار',
            self::Maysan => 'ميسان',
            self::Muthanna => 'المثنى',
            self::Qadisiyyah => 'القادسية',
        };
    }
}
