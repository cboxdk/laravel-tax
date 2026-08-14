<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * The top level a merchant navigates before picking a class.
 *
 * Fifty-five choices in a flat list is a list nobody reads; a merchant selling
 * shoes should reach "Clothing and footwear" through "Goods" without meeting
 * "Waste treatment" on the way. The grouping carries no tax meaning of its own —
 * every determination hangs off the {@see TaxClass}, never off the group — so it
 * can be reorganised for legibility without changing a single answer.
 */
enum TaxClassGroup: string
{
    case Food = 'food';
    case Publications = 'publications';
    case Health = 'health';
    case Travel = 'travel';
    case Culture = 'culture';
    case Art = 'art';
    case Utilities = 'utilities';
    case Property = 'property';
    case Services = 'services';
    case Agriculture = 'agriculture';
    case Goods = 'goods';
    case Digital = 'digital';

    public function label(): string
    {
        return match ($this) {
            self::Food => 'Food and drink',
            self::Publications => 'Books and publications',
            self::Health => 'Health and medical',
            self::Travel => 'Travel and accommodation',
            self::Culture => 'Culture, sport and entertainment',
            self::Art => 'Art, antiques and collectibles',
            self::Utilities => 'Utilities and energy',
            self::Property => 'Property and construction',
            self::Services => 'Services',
            self::Agriculture => 'Agriculture',
            self::Goods => 'General goods',
            self::Digital => 'Digital and software',
        };
    }
}
