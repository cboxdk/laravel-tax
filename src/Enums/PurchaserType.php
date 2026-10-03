<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * Who is buying, where that can exempt the supply: the reasons on the Streamlined
 * exemption certificate (F0003) in the United States, and the purchasers Art. 151 of
 * the VAT Directive names in the European Union.
 *
 * The BUYER, whoever makes the call: a marketplace pricing for a merchant states the
 * merchant's customer. Whether the type exempts anything is each place's own rule — a
 * charitable organisation is exempt in Texas and taxable in Alabama — so stating it
 * asks the question; it does not answer it.
 */
enum PurchaserType: string
{
    case FederalGovernment = 'federal_government';
    case StateOrLocalGovernment = 'state_or_local_government';
    case TribalGovernment = 'tribal_government';
    case ForeignDiplomat = 'foreign_diplomat';
    case CharitableOrganization = 'charitable_organization';
    case ReligiousOrganization = 'religious_organization';
    case Resale = 'resale';
    case AgriculturalProduction = 'agricultural_production';
    case IndustrialProduction = 'industrial_production';
    case DirectPayPermit = 'direct_pay_permit';
    case DirectMail = 'direct_mail';
    case EducationalOrganization = 'educational_organization';
    case Other = 'other';

    /** Art. 151(1)(a): diplomatic and consular arrangements. */
    case DiplomaticOrConsular = 'diplomatic_or_consular';

    /** Art. 151(1)(b): international bodies recognised by the host state. */
    case InternationalBody = 'international_body';

    /** Art. 151(1)(c)-(d): armed forces of other NATO states. */
    case ArmedForces = 'armed_forces';

    /** A public body, where a member state's own law exempts supplies to it. */
    case PublicBody = 'public_body';
}
