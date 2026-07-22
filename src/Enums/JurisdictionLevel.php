<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * The layer of government a rate record belongs to — mirrors the `level` field of
 * a us-tax-data rate record. Used when stacking a rooftop all-in rate to tell the
 * state share (added once) apart from the local records (county/city/special
 * district) that stack on top of it.
 */
enum JurisdictionLevel: string
{
    case State = 'state';
    case County = 'county';
    case City = 'city';
    case SpecialDistrict = 'special_district';
    case Local = 'local';
}
