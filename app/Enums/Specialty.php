<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

/**
 * The surgical specialty a product category belongs to (set on the category; a product takes
 * the nearest one up its category tree).
 */
enum Specialty: string implements HasLabel
{
    use EnumHelpers;

    case Arthroplasty = 'arthroplasty';
    case Trauma = 'trauma';
    case Spine = 'spine';
    case SportMedicine = 'sport_medicine';

    public function getLabel(): string
    {
        return match ($this) {
            self::Arthroplasty => __('Arthroplasty'),
            self::Trauma => __('Trauma'),
            self::Spine => __('Spine'),
            self::SportMedicine => __('Sport medicine'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            // Line drawings in resources/svg (icon set "ot").
            self::Arthroplasty => 'ot-hip-prosthesis',
            self::Trauma => 'ot-trauma',
            self::Spine => 'ot-spine',
            self::SportMedicine => 'ot-knee',
        };
    }

    /**
     * Best guess from a category name or code (used to fill existing categories).
     */
    public static function guess(?string $name): ?self
    {
        $name = mb_strtolower((string) $name);

        return match (true) {
            $name === '' => null,
            (bool) preg_match('/spine|spinal|vertebr|pedicle/', $name) => self::Spine,
            (bool) preg_match('/sport|arthroscop|ligament|acl\b|menisc|anchor/', $name) => self::SportMedicine,
            (bool) preg_match('/trauma|plate|screw|nail|wire|fixat|k-wire/', $name) => self::Trauma,
            (bool) preg_match('/arthroplast|hip|knee|shoulder|elbow|ankle|joint|acetab|femoral|tibial/', $name) => self::Arthroplasty,
            default => null,
        };
    }
}
