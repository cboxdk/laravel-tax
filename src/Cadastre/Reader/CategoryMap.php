<?php

declare(strict_types=1);

namespace Cbox\Tax\Cadastre\Reader;

use Cbox\Tax\Enums\TaxClass;

/**
 * Where each {@see TaxClass} lands in the register's category vocabulary.
 *
 * THIS MAP IS LOSSY, AND KNOWING WHERE IS THE POINT OF WRITING IT DOWN. The engine
 * has 56 classes; 116 categories carry a live rate. So sixty distinctions the
 * register makes cannot be said in a `TaxClass` at all — `goods.medical_equipment`
 * has fourteen children (dialysis, oxygen, enteral feeding, breast pumps and their
 * supplies and their kits, hearing aids, prostheses) against one `MedicalDevice`,
 * and several US states split on exactly those lines.
 *
 * The answer is not to grow the enum. `TaxClass` was derived from a retired
 * dataset's 87 headings and is a hand-maintained copy of a vocabulary somebody else
 * now publishes, versions, and ships catalogue mappings into. It stays as the
 * ergonomic front door; a caller with a finer product can pass a category key or a
 * commodity code straight through and reach what this map cannot express.
 *
 * Where a class has no counterpart it maps to the nearest PARENT rather than to a
 * sibling that is nearly right. A parent is honestly coarse; a near-sibling is
 * confidently wrong, and the second one prices an invoice.
 */
final class CategoryMap
{
    /** The class every unmapped item falls to, and the reason it is safe to. */
    public const string FALLBACK = 'goods';

    public static function keyFor(TaxClass $class): string
    {
        return match ($class) {
            TaxClass::Groceries => 'goods.food.basic',
            TaxClass::PreparedFood => 'goods.food.prepared',
            TaxClass::Candy => 'goods.food.candy',
            TaxClass::SoftDrinks => 'goods.food.soft_drinks',
            TaxClass::DietarySupplements => 'goods.food.dietary_supplements',
            TaxClass::Wine => 'goods.beverages.alcoholic',

            TaxClass::Book => 'goods.publications.book',
            TaxClass::Newspaper => 'goods.publications.newspaper',
            TaxClass::Periodical => 'goods.publications.periodical',
            // Printed advertising has no category of its own. It is NOT
            // `services.digital.advertising` — that is advertising supplied
            // electronically, and the register corrected exactly this confusion in
            // its own data. The parent is the honest answer.
            TaxClass::AdvertisingPrint => 'goods.publications',

            TaxClass::PrescriptionMedicine => 'goods.medicine.prescription',
            TaxClass::OtcMedicine => 'goods.medicine.otc',
            // Fourteen children collapse here. See the class docblock.
            TaxClass::MedicalDevice => 'goods.medical_equipment',
            TaxClass::MedicalCare => 'services.medical',

            TaxClass::Accommodation => 'services.accommodation',
            TaxClass::PassengerTransport => 'services.passenger_transport',

            TaxClass::CulturalAdmission => 'services.cultural_admission',
            TaxClass::SportingAdmission => 'services.sporting_admission',
            TaxClass::Broadcasting => 'services.broadcasting',

            // The register files original art and antiques together; the engine
            // separates them. Both resolve to the one category that exists.
            TaxClass::ArtOriginal, TaxClass::Antique => 'goods.art',

            TaxClass::Water => 'goods.water',
            // Electricity, gas, district heating and firewood are one category in
            // the register. The engine's four classes are finer than the data.
            TaxClass::Electricity, TaxClass::Gas, TaxClass::Heating, TaxClass::Firewood => 'goods.energy',
            TaxClass::FossilFuel => 'goods.energy',
            TaxClass::RenewableEnergy => 'goods.solar_panels',

            TaxClass::Housing => 'services.construction',
            TaxClass::Renovation => 'services.housing_renovation',

            TaxClass::PersonalCare => 'services.personal_care',
            TaxClass::RepairService => 'services.repair',
            TaxClass::CleaningService => 'services.cleaning',
            TaxClass::WasteTreatment => 'services.waste',
            TaxClass::SocialCare => 'services.domestic_care',
            TaxClass::AuthorshipService => 'services.authors',
            TaxClass::FuneralService => 'services.funeral',
            TaxClass::PostalService => 'services.delivery',
            TaxClass::FinancialAdmin => 'services.financial',
            TaxClass::ProfessionalService => 'services.professional',
            TaxClass::DataProcessing => 'services.data_processing',
            TaxClass::WebHosting => 'services.digital.hosting',
            TaxClass::AiApi => 'services.digital.ai',

            TaxClass::AgriculturalInput => 'goods.agricultural_inputs',
            // Live plants, cut flowers and livestock are produce, not inputs, and
            // the register has no category for them. Up to the root rather than
            // across to inputs, which carries a different rate in several states.
            TaxClass::AgriculturalProduce => 'goods',

            TaxClass::Clothing, TaxClass::Footwear => 'goods.clothing',
            TaxClass::ChildCarSeat => 'goods.child_seats',
            TaxClass::Bicycle => 'goods.bicycles',
            // Nothing reduces general goods, electronics or furniture anywhere, so
            // the root is not a loss here — it is the whole answer.
            TaxClass::GeneralGoods, TaxClass::Electronics, TaxClass::Furniture => self::FALLBACK,

            TaxClass::DigitalService => 'services.digital',
            TaxClass::DigitalProduct => 'goods.digital_products',
            TaxClass::SoftwarePrewritten => 'goods.software.prewritten',
            TaxClass::SoftwareCustom => 'goods.software.custom',
        };
    }

    /**
     * A category and every ancestor above it, most specific first.
     *
     * The ladder a resolver walks when nothing is published at the exact key —
     * upward only, and only while each step still has one answer. Going up makes a
     * question broader, so it can only ever be tried after the specific one failed.
     *
     * @return list<string>
     */
    public static function ladder(string $key): array
    {
        $rungs = [$key];
        $parts = explode('.', $key);

        while (count($parts) > 1) {
            array_pop($parts);
            $rungs[] = implode('.', $parts);
        }

        return $rungs;
    }
}
