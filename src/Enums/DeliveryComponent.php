<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

enum DeliveryComponent: string
{
    case Transport = 'transport';
    case Handling = 'handling';
}
