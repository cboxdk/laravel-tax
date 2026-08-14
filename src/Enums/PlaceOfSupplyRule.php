<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * Which rule decides WHERE a supply is taxed, for EU VAT.
 *
 * The engine taxed everything at the customer's location, which is right for
 * digital services and for goods and wrong for services in general. Under
 * Art. 45 of the VAT Directive the place of supply of a service to a
 * non-taxable person is **where the supplier is established** — destination is
 * the exception (Art. 58 for telecoms/broadcasting/electronic services), not
 * the rule.
 *
 * The consequence is concrete: a German consultancy invoicing a French consumer
 * owes German VAT at 19%, not French VAT at 20%, and owes no OSS obligation for
 * that supply at all.
 */
enum PlaceOfSupplyRule: string
{
    /**
     * The customer's location. Art. 58 (telecoms, broadcasting, electronically
     * supplied services) and Art. 33(a) (intra-Community distance sales of goods).
     */
    case Destination = 'destination';

    /** The supplier's establishment — Art. 45, the general rule for B2C services. */
    case SupplierEstablishment = 'supplier_establishment';

    /**
     * Where the service is physically carried out — Art. 54 for work on movable
     * property, personal care, cultural and similar activities.
     *
     * The engine does not carry where that was, so it uses the customer's location
     * as a proxy and says so on the assessment. For a consumer having their hair
     * cut or a device repaired the two coincide almost always; modelling it
     * properly needs a performance location on the query, which is tracked
     * separately.
     */
    case WhereProvided = 'where_provided';
}
