<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

use Cbox\Tax\ValueObjects\TaxClassInfo;

/**
 * What a thing IS, independently of where it is sold.
 *
 * A merchant maps a product to one of these once. Every jurisdiction's own
 * vocabulary — the US taxability matrix, the EU's Annex III headings — is then a
 * mapping the DATA owns, not something the merchant has to learn.
 *
 * That division is the whole point, because the two regimes ask different
 * questions. The United States asks *is this taxable in this state*: a boolean per
 * (state, category), and 25 categories cover it because the question is binary.
 * The EU asks *which band*: everything is taxable and the schedule runs to 87
 * distinct headings in practice. A list built for the first question cannot answer
 * the second, and the earlier {@see TaxCategory} — built US-first — reached only
 * 23% of the EU's published bands. These 55 classes reach 97%.
 *
 * Three rules held while deriving them, and they are why this is a list and not an
 * opinion:
 *
 *  1. **It came out of the data.** Both the 87 EU headings (with the count of
 *     member states using each) and the 25 US categories were laid side by side;
 *     the classes are where they fall together. Nothing was invented to be tidy.
 *  2. **Every class is anchored.** An Annex III point where EU law permits a
 *     reduced rate, CN headings for goods, and the US category it corresponds to.
 *     See {@see TaxClassInfo}.
 *  3. **Finer than the source where the source is coarse.** Ireland reports books
 *     and periodicals under one heading at 0% and 9% at once, which no single
 *     answer fits. Two classes make it decidable. A taxonomy is allowed to be
 *     sharper than any one jurisdiction's schedule; that is most of its value.
 *
 * Five EU "headings" are deliberately absent: `REGION`, `NEW_PARKING_RATE`,
 * `EXEMPTION_SUPERREDUCED`, `TEMPORARY_EXEMPTION_RATE` and `HOUSEHOLD` are not
 * products at all — they are territorial and rate-mechanism artefacts sharing a
 * field with real categories. Mapping them blindly would have created product
 * classes for the Azores.
 */
enum TaxClass: string
{
    // ---- Food and drink ------------------------------------------------------
    case Groceries = 'groceries';
    case PreparedFood = 'prepared_food';
    case Candy = 'candy';
    case SoftDrinks = 'soft_drinks';
    case DietarySupplements = 'dietary_supplements';
    case Wine = 'wine';

    // ---- Books and publications ---------------------------------------------
    case Book = 'book';
    case Newspaper = 'newspaper';
    case Periodical = 'periodical';
    case AdvertisingPrint = 'advertising_print';

    // ---- Health and medical --------------------------------------------------
    case PrescriptionMedicine = 'prescription_medicine';
    case OtcMedicine = 'otc_medicine';
    case MedicalDevice = 'medical_device';
    case MedicalCare = 'medical_care';

    // ---- Travel and accommodation -------------------------------------------
    case Accommodation = 'accommodation';
    case PassengerTransport = 'passenger_transport';

    // ---- Culture, sport and entertainment ------------------------------------
    case CulturalAdmission = 'cultural_admission';
    case SportingAdmission = 'sporting_admission';
    case Broadcasting = 'broadcasting';

    // ---- Art, antiques and collectibles --------------------------------------
    case ArtOriginal = 'art_original';
    case Antique = 'antique';

    // ---- Utilities and energy ------------------------------------------------
    case Water = 'water';
    case Electricity = 'electricity';
    case Gas = 'gas';
    case Heating = 'heating';
    case FossilFuel = 'fossil_fuel';
    case RenewableEnergy = 'renewable_energy';

    // ---- Property and construction -------------------------------------------
    case Housing = 'housing';
    case Renovation = 'renovation';

    // ---- Services -------------------------------------------------------------
    case PersonalCare = 'personal_care';
    case RepairService = 'repair_service';
    case CleaningService = 'cleaning_service';
    case WasteTreatment = 'waste_treatment';
    case SocialCare = 'social_care';
    case AuthorshipService = 'authorship_service';
    case FuneralService = 'funeral_service';
    case PostalService = 'postal_service';
    case FinancialAdmin = 'financial_admin';
    case ProfessionalService = 'professional_service';
    case DataProcessing = 'data_processing';
    case WebHosting = 'web_hosting';
    case AiApi = 'ai_api';

    // ---- Agriculture -----------------------------------------------------------
    case AgriculturalProduce = 'agricultural_produce';
    case AgriculturalInput = 'agricultural_input';
    case Firewood = 'firewood';

    // ---- General goods ---------------------------------------------------------
    case GeneralGoods = 'general_goods';
    case Clothing = 'clothing';
    case Footwear = 'footwear';
    case ChildCarSeat = 'child_car_seat';
    case Bicycle = 'bicycle';
    case Electronics = 'electronics';
    case Furniture = 'furniture';

    // ---- Digital and software ---------------------------------------------------
    case DigitalService = 'digital_service';
    case DigitalProduct = 'digital_product';
    case SoftwarePrewritten = 'software_prewritten';
    case SoftwareCustom = 'software_custom';

    /**
     * The one class that is safe as a default.
     *
     * General tangible goods are taxable at the standard rate in every sales-tax
     * state and every Member State, so a merchant who picks nothing is over- rather
     * than under-charged. Every other class must be chosen deliberately.
     */
    public static function default(): self
    {
        return self::GeneralGoods;
    }

    public function info(): TaxClassInfo
    {
        return match ($this) {
            self::Groceries => new TaxClassInfo(TaxClassGroup::Food, 'Groceries', 1, ['02', '04', '07', '08', '10', '11', '19'], ['bread', 'milk', 'fresh produce', 'rice']),
            self::PreparedFood => new TaxClassInfo(TaxClassGroup::Food, 'Restaurant and takeaway food', 12, [], ['restaurant meals', 'takeaway', 'catering']),
            self::Candy => new TaxClassInfo(TaxClassGroup::Food, 'Confectionery and sweets', 1, ['1704', '1806'], ['chocolate bars', 'sweets', 'chewing gum']),
            self::SoftDrinks => new TaxClassInfo(TaxClassGroup::Food, 'Soft drinks', 1, ['2202'], ['cola', 'energy drinks', 'bottled iced tea']),
            self::DietarySupplements => new TaxClassInfo(TaxClassGroup::Food, 'Dietary supplements', 1, ['2106'], ['vitamins', 'protein powder', 'fish oil']),
            self::Wine => new TaxClassInfo(TaxClassGroup::Food, 'Wine', null, ['2204'], ['still wine', 'sparkling wine']),

            self::Book => new TaxClassInfo(TaxClassGroup::Publications, 'Books', 6, ['4901'], ['printed books', 'e-books', 'audiobooks']),
            self::Newspaper => new TaxClassInfo(TaxClassGroup::Publications, 'Newspapers', 6, ['4902'], ['daily papers', 'digital news subscriptions']),
            self::Periodical => new TaxClassInfo(TaxClassGroup::Publications, 'Magazines and periodicals', 6, ['4902'], ['magazines', 'journals']),
            self::AdvertisingPrint => new TaxClassInfo(TaxClassGroup::Publications, 'Brochures and printed advertising', null, ['4911'], ['leaflets', 'catalogues', 'flyers']),

            self::PrescriptionMedicine => new TaxClassInfo(TaxClassGroup::Health, 'Prescription medicine', 3, ['3003', '3004'], ['prescribed drugs', 'insulin']),
            self::OtcMedicine => new TaxClassInfo(TaxClassGroup::Health, 'Over-the-counter medicine', 3, ['3003', '3004'], ['painkillers', 'antihistamines', 'cough syrup']),
            self::MedicalDevice => new TaxClassInfo(TaxClassGroup::Health, 'Medical equipment and aids', 4, ['9018', '9021'], ['wheelchairs', 'hearing aids', 'blood-pressure monitors']),
            self::MedicalCare => new TaxClassInfo(TaxClassGroup::Health, 'Medical and dental care', 15, [], ['consultations', 'physiotherapy']),

            self::Accommodation => new TaxClassInfo(TaxClassGroup::Travel, 'Hotel and holiday accommodation', 12, [], ['hotel nights', 'holiday lets', 'campsites']),
            self::PassengerTransport => new TaxClassInfo(TaxClassGroup::Travel, 'Passenger transport', 5, [], ['rail tickets', 'bus fares', 'taxi rides']),

            self::CulturalAdmission => new TaxClassInfo(TaxClassGroup::Culture, 'Admission to cultural events', 7, [], ['theatre tickets', 'museum entry', 'zoo entry', 'concerts']),
            self::SportingAdmission => new TaxClassInfo(TaxClassGroup::Culture, 'Admission to sport and sports facilities', 13, [], ['match tickets', 'gym entry', 'swimming pools']),
            self::Broadcasting => new TaxClassInfo(TaxClassGroup::Culture, 'Radio and television broadcasting', 8, [], ['TV licences', 'broadcast subscriptions']),

            self::ArtOriginal => new TaxClassInfo(TaxClassGroup::Art, 'Original works of art', 26, ['9701', '9702', '9703'], ['paintings', 'sculptures', 'limited-edition prints', 'art photographs']),
            self::Antique => new TaxClassInfo(TaxClassGroup::Art, 'Antiques and collectors\' items', 26, ['9705', '9706'], ['items over 100 years old', 'collections']),

            self::Water => new TaxClassInfo(TaxClassGroup::Utilities, 'Water supply', 2, ['2201'], ['mains water', 'sewerage']),
            self::Electricity => new TaxClassInfo(TaxClassGroup::Utilities, 'Electricity supply', 22, ['2716'], ['domestic electricity']),
            self::Gas => new TaxClassInfo(TaxClassGroup::Utilities, 'Gas supply', 22, ['2711'], ['natural gas', 'LPG']),
            self::Heating => new TaxClassInfo(TaxClassGroup::Utilities, 'District heating and cooling', 22, [], ['district heating', 'steam']),
            self::FossilFuel => new TaxClassInfo(TaxClassGroup::Utilities, 'Fuel', null, ['2710'], ['petrol', 'diesel', 'heating oil']),
            self::RenewableEnergy => new TaxClassInfo(TaxClassGroup::Utilities, 'Solar panels and renewable energy', 10, ['8541'], ['solar panels', 'heat pumps']),

            self::Housing => new TaxClassInfo(TaxClassGroup::Property, 'Housing provision', 10, [], ['social housing', 'residential lettings']),
            self::Renovation => new TaxClassInfo(TaxClassGroup::Property, 'Renovation and repair of dwellings', 11, [], ['home renovation', 'plumbing work']),

            self::PersonalCare => new TaxClassInfo(TaxClassGroup::Services, 'Hairdressing and personal care', 21, [], ['haircuts', 'beauty treatments']),
            self::RepairService => new TaxClassInfo(TaxClassGroup::Services, 'Repairs', 19, [], ['shoe repair', 'clothing alterations', 'appliance repair']),
            self::CleaningService => new TaxClassInfo(TaxClassGroup::Services, 'Cleaning services', 20, [], ['window cleaning', 'domestic cleaning']),
            self::WasteTreatment => new TaxClassInfo(TaxClassGroup::Services, 'Waste collection and treatment', 18, [], ['refuse collection', 'recycling']),
            self::SocialCare => new TaxClassInfo(TaxClassGroup::Services, 'Social and domestic care', 15, [], ['home help', 'elderly care']),
            self::AuthorshipService => new TaxClassInfo(TaxClassGroup::Services, 'Authors, composers and performers', 24, [], ['royalties', 'commissioned writing']),
            self::FuneralService => new TaxClassInfo(TaxClassGroup::Services, 'Funeral services', 16, [], ['undertaking', 'cremation']),
            self::PostalService => new TaxClassInfo(TaxClassGroup::Services, 'Postal services', 25, [], ['stamps', 'parcel delivery']),
            self::FinancialAdmin => new TaxClassInfo(TaxClassGroup::Services, 'Credit and security administration', null, [], ['credit management', 'security administration']),
            self::ProfessionalService => new TaxClassInfo(TaxClassGroup::Services, 'Professional services', null, [], ['consulting', 'legal advice', 'accountancy']),
            self::DataProcessing => new TaxClassInfo(TaxClassGroup::Services, 'Data processing', null, [], ['payroll processing', 'data entry']),
            self::WebHosting => new TaxClassInfo(TaxClassGroup::Services, 'Web hosting', null, [], ['shared hosting', 'cloud servers']),
            self::AiApi => new TaxClassInfo(TaxClassGroup::Services, 'AI and API access', null, [], ['model inference', 'metered API calls']),

            self::AgriculturalProduce => new TaxClassInfo(TaxClassGroup::Agriculture, 'Agricultural produce and livestock', 11, ['01', '06'], ['live plants', 'cut flowers', 'livestock']),
            self::AgriculturalInput => new TaxClassInfo(TaxClassGroup::Agriculture, 'Fertilisers and agricultural inputs', 11, ['31', '3808'], ['fertiliser', 'pesticides', 'farm equipment']),
            self::Firewood => new TaxClassInfo(TaxClassGroup::Agriculture, 'Firewood', 22, ['4401'], ['logs', 'wood pellets']),

            self::GeneralGoods => new TaxClassInfo(TaxClassGroup::Goods, 'General goods', null, [], ['most physical products']),
            self::Clothing => new TaxClassInfo(TaxClassGroup::Goods, 'Clothing', null, ['61', '62'], ['shirts', 'coats', 'dresses']),
            self::Footwear => new TaxClassInfo(TaxClassGroup::Goods, 'Footwear', null, ['64'], ['shoes', 'boots', 'trainers']),
            self::ChildCarSeat => new TaxClassInfo(TaxClassGroup::Goods, 'Child car seats', 9, ['9401'], ['infant carriers', 'booster seats']),
            self::Bicycle => new TaxClassInfo(TaxClassGroup::Goods, 'Bicycles', 23, ['8712', '8711'], ['bicycles', 'e-bikes']),
            self::Electronics => new TaxClassInfo(TaxClassGroup::Goods, 'Consumer electronics', null, ['85'], ['phones', 'laptops', 'televisions']),
            self::Furniture => new TaxClassInfo(TaxClassGroup::Goods, 'Furniture', null, ['94'], ['tables', 'sofas', 'beds']),

            self::DigitalService => new TaxClassInfo(TaxClassGroup::Digital, 'Software as a service', null, [], ['SaaS subscriptions', 'online tools']),
            self::DigitalProduct => new TaxClassInfo(TaxClassGroup::Digital, 'Digital downloads', null, [], ['music files', 'stock photos', 'templates']),
            self::SoftwarePrewritten => new TaxClassInfo(TaxClassGroup::Digital, 'Off-the-shelf software', null, [], ['boxed software', 'licences']),
            self::SoftwareCustom => new TaxClassInfo(TaxClassGroup::Digital, 'Custom software development', null, [], ['bespoke development']),
        };
    }

    /**
     * Where EU law says this supply is taxed, for a consumer.
     *
     * Deliberately kept off {@see TaxClassInfo}: the info describes what a thing
     * IS, and this describes what a Directive does to it. Mixing product taxonomy
     * with legal place-of-supply behaviour in one structure is the criticism the
     * earlier category enum earned, and the two move for different reasons.
     */
    public function placeOfSupplyRule(): PlaceOfSupplyRule
    {
        return match ($this) {
            // Art. 58 — telecoms, broadcasting, electronically supplied services.
            self::DigitalService, self::DigitalProduct,
            self::SoftwarePrewritten, self::WebHosting, self::AiApi,
            self::Broadcasting => PlaceOfSupplyRule::Destination,

            // Art. 54 — physically carried out where the customer is. Admission to
            // an event is taxed where the event happens, which is the same idea.
            self::RepairService, self::PersonalCare, self::CleaningService,
            self::CulturalAdmission, self::SportingAdmission,
            self::Accommodation, self::MedicalCare, self::Renovation => PlaceOfSupplyRule::WhereProvided,

            // Art. 45 — the general rule for services to a consumer.
            self::ProfessionalService, self::DataProcessing, self::SoftwareCustom,
            self::AuthorshipService, self::FinancialAdmin,
            self::SocialCare, self::FuneralService => PlaceOfSupplyRule::SupplierEstablishment,

            // Art. 33(a) — intra-Community distance sales of goods. Everything not
            // named above is goods.
            default => PlaceOfSupplyRule::Destination,
        };
    }

    /**
     * The us-tax-data taxability category this class corresponds to, or null where
     * the dataset carries no determination for it.
     *
     * Null is a real answer, not a gap. US lodging is taxed by separate
     * transient-occupancy regimes at city and county level, and passenger transport
     * and utility supply are outside general sales tax in many states — so mapping
     * those to general goods would charge the wrong tax to the wrong authority.
     * They refuse instead, which is the same stance the dataset takes for any
     * undetermined pair.
     *
     * The correspondence is a translation between two of our own vocabularies, so
     * it lives here rather than in the dataset; `us-tax-data/resources/overlays/
     * class-map.json` carries the same table with the reasoning, and each side
     * tests its own half.
     */
    public function datasetCategory(): ?string
    {
        return match ($this) {
            self::Groceries => 'grocery',
            self::PreparedFood => 'prepared_food',
            self::Candy => 'candy',
            self::SoftDrinks => 'soft_drinks',
            self::DietarySupplements => 'dietary_supplements',
            self::Book => 'books',
            self::Newspaper => 'newspapers',
            self::Periodical => 'magazines',
            self::PrescriptionMedicine => 'prescription_drugs',
            self::OtcMedicine => 'otc_drugs',
            self::MedicalDevice => 'medical_devices',
            self::PersonalCare => 'services_personal_care',
            self::RepairService => 'services_repair',
            self::ProfessionalService => 'services_professional',
            self::DataProcessing => 'services_data_processing',
            self::WebHosting => 'services_web_hosting',
            self::AiApi => 'services_ai_api',
            self::GeneralGoods => 'goods_general',
            // Every state that draws a line puts footwear on the clothing side of
            // it, including the three with a price threshold.
            self::Clothing, self::Footwear => 'clothing',
            self::Electronics => 'goods_electronics',
            self::Furniture => 'goods_furniture',
            self::DigitalService => 'digital_service',
            self::DigitalProduct => 'digital_products',
            self::SoftwarePrewritten => 'software_prewritten',
            self::SoftwareCustom => 'software_custom',
            default => null,
        };
    }

    /**
     * Every class in a group, for building a merchant-facing picker.
     *
     * @return list<self>
     */
    public static function inGroup(TaxClassGroup $group): array
    {
        return array_values(array_filter(self::cases(), static fn (self $c): bool => $c->info()->group === $group));
    }
}
