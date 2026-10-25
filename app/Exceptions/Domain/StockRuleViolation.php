<?php

namespace App\Exceptions\Domain;

use RuntimeException;

/**
 * A stock rule said no (serial already exists, expired unit, quantity would go
 * below zero…). The message is translated and safe to show to the user.
 */
class StockRuleViolation extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
