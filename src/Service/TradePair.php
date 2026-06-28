<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Immutable trade pair configuration.
 */
final class TradePair
{
    public function __construct(
        public readonly string $key,
        public readonly string $base,
        public readonly string $quote,
        public readonly string $label,
        public readonly float $feePercent,
        public readonly float $minAmount
    ) {}
}
