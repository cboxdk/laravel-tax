<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * The layer of government a rate component belongs to. Used when stacking a rate
 * to tell the shares apart: a federal share and a provincial one in Canada, a
 * state share (added once) and the local records (county/city/special district)
 * that stack on top of it in the United States.
 */
enum JurisdictionLevel: string
{
    case Country = 'country';
    case State = 'state';
    case County = 'county';
    case City = 'city';
    case SpecialDistrict = 'special_district';
    case Local = 'local';
}
