<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

/**
 * The 19 governorates of Iraq. Duhok, Erbil, Sulaymaniyah and Halabja form the Kurdistan
 * Region; the others are "Iraq" on the Main page overview.
 */
enum Governorate: string implements HasLabel
{
    use EnumHelpers;

    case Duhok = 'duhok';
    case Erbil = 'erbil';
    case Sulaymaniyah = 'sulaymaniyah';
    case Halabja = 'halabja';
    case Baghdad = 'baghdad';
    case Basra = 'basra';
    case Nineveh = 'nineveh';
    case Kirkuk = 'kirkuk';
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

    public const KURDISTAN = 'kurdistan';

    public const IRAQ = 'iraq';

    public function getLabel(): string
    {
        return PlateRegion::from($this->value)->getLabel();
    }

    public function isKurdistan(): bool
    {
        return in_array($this, [self::Duhok, self::Erbil, self::Sulaymaniyah, self::Halabja], true);
    }

    /**
     * "kurdistan" or "iraq" (the governorates outside the Kurdistan Region).
     */
    public function region(): string
    {
        return $this->isKurdistan() ? self::KURDISTAN : self::IRAQ;
    }

    public static function regionLabel(string $region): string
    {
        return match ($region) {
            self::KURDISTAN => __('Kurdistan'),
            self::IRAQ => __('Iraq'),
            default => __('Iraq and Kurdistan'),
        };
    }

    /**
     * @return list<self>
     */
    public static function inRegion(string $region): array
    {
        return array_values(array_filter(self::cases(), fn (self $g) => $g->region() === $region));
    }

    /**
     * Best guess from a city name in any language (used to fill existing stations).
     */
    public static function guess(?string $city): ?self
    {
        $city = mb_strtolower(trim((string) $city));

        if ($city === '') {
            return null;
        }

        $aliases = [
            'duhok' => ['duhok', 'dohuk', 'dahuk', 'zakho', 'akre', 'دهوك', 'دهۆک', 'زاخو'],
            'erbil' => ['erbil', 'arbil', 'irbil', 'hawler', 'hewler', 'أربيل', 'اربيل', 'هەولێر'],
            'sulaymaniyah' => ['sulaymaniyah', 'sulaimani', 'sulaimaniya', 'slemani', 'sulaymaniya', 'السليمانية', 'سلێمانی'],
            'halabja' => ['halabja', 'حلبجة', 'هەڵەبجە'],
            'baghdad' => ['baghdad', 'بغداد', 'بەغدا'],
            'basra' => ['basra', 'basrah', 'البصرة', 'بصرة', 'بەسرە'],
            'nineveh' => ['nineveh', 'ninawa', 'mosul', 'نينوى', 'الموصل', 'موسڵ'],
            'kirkuk' => ['kirkuk', 'كركوك', 'کەرکووک'],
            'anbar' => ['anbar', 'ramadi', 'fallujah', 'الأنبار', 'الانبار', 'الرمادي'],
            'babil' => ['babil', 'babylon', 'hillah', 'hilla', 'بابل', 'الحلة'],
            'karbala' => ['karbala', 'كربلاء'],
            'najaf' => ['najaf', 'النجف'],
            'wasit' => ['wasit', 'kut', 'واسط', 'الكوت'],
            'diyala' => ['diyala', 'baqubah', 'ديالى', 'بعقوبة'],
            'salahaddin' => ['salahaddin', 'saladin', 'salah al-din', 'tikrit', 'صلاح الدين', 'تكريت'],
            'dhi_qar' => ['dhi qar', 'nasiriyah', 'ذي قار', 'الناصرية'],
            'maysan' => ['maysan', 'amarah', 'ميسان', 'العمارة'],
            'muthanna' => ['muthanna', 'samawah', 'المثنى', 'السماوة'],
            'qadisiyyah' => ['qadisiyyah', 'diwaniyah', 'القادسية', 'الديوانية'],
        ];

        foreach ($aliases as $value => $names) {
            foreach ($names as $name) {
                if (str_contains($city, $name)) {
                    return self::from($value);
                }
            }
        }

        return null;
    }
}
