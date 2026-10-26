<?php

namespace App\Exceptions\Domain;

/**
 * A money rule said no (box would go below zero, no exchange rate yet, amount too large…).
 * Extends StockRuleViolation so every screen shows it the same way. Translated, safe to show.
 */
class MoneyRuleViolation extends StockRuleViolation {}
