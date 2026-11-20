<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * The four classes of goods the margin scheme reaches (Art. 311 of the VAT Directive),
 * under the register's own names. Which class a sale is decides the invoice mention
 * (Art. 226(14)) and, in some states, whether the per-period method is open to it.
 */
enum MarginGoods: string
{
    case SecondHandGoods = 'second_hand_goods';
    case WorksOfArt = 'works_of_art';
    case CollectorsItems = 'collectors_items';
    case Antiques = 'antiques';
}
