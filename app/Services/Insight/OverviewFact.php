<?php

namespace App\Services\Insight;

use App\Enums\Governorate;
use App\Enums\Specialty;

/**
 * One station × product on the Home page overview: the pieces and the button's amount.
 */
final class OverviewFact
{
    public function __construct(
        public readonly int $branchId,
        public readonly int $productId,
        public readonly ?Governorate $governorate,
        public readonly ?Specialty $specialty,
        public readonly int $qty,
        public readonly string $amount,
        public readonly ?string $revenue = null,
        public readonly ?string $cost = null,
    ) {}
}
