<?php

declare(strict_types=1);

namespace CarMoneyLab\Domain;

/**
 * Решение по заявке на основании LTV и пробега.
 *
 *   LTV < approve_max                -> approve
 *   approve_max <= LTV <= review_max -> review
 *   LTV > review_max                 -> reject
 *
 * Пробег свыше review_max_mileage_km понижает approve до review;
 * решения review и reject не меняет.
 */
final class DecisionEngine
{
    public const APPROVE = 'approve';
    public const REVIEW = 'review';
    public const REJECT = 'reject';

    private float $approveMax;
    private float $reviewMax;
    private int $reviewMaxMileageKm;

    /** @param array{approve_max:float,review_max:float} $thresholds */
    public function __construct(array $thresholds, int $reviewMaxMileageKm = 400000)
    {
        $this->approveMax = $thresholds['approve_max'];
        $this->reviewMax = $thresholds['review_max'];
        $this->reviewMaxMileageKm = $reviewMaxMileageKm;
    }

    public function decide(float $ltv, int $mileage = 0): string
    {
        if ($ltv < $this->approveMax) {
            return $mileage > $this->reviewMaxMileageKm ? self::REVIEW : self::APPROVE;
        }

        if ($ltv <= $this->reviewMax) {
            return self::REVIEW;
        }

        return self::REJECT;
    }
}
