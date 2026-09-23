<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\DeliveryRules;
use Cbox\Tax\Enums\RefusalReason;
use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\Register\Reader\Decision;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\ValueObjects\DeliveryCharge;
use Cbox\Tax\ValueObjects\DeliveryTreatment;
use DateTimeImmutable;

/**
 * Delivery charges against a state's `taxable_base` rules.
 *
 * TWO PUBLISHED SHAPES, and both are live. Twenty-three states publish a bare
 * `included` flag, whose exclusion conditions are prose the host must confirm.
 * Kansas publishes a decision: the same conditions — customer delivery, not direct
 * mail, separately stated, labelled as delivery, the true cost — as tests over named
 * facts, dated so the 2023 change of statute is two rules and not one. A decision is
 * evaluated here; a bare flag is passed through for the regime to confirm.
 */
final readonly class RegisterDelivery implements DeliveryRules
{
    public function __construct(private RegisterDataset $dataset) {}

    public function treatment(SubdivisionCode $state, DeliveryCharge $delivery, DateTimeImmutable $at): ?DeliveryTreatment
    {
        if ($delivery->goodsTaxable === null) {
            throw new UnresolvedTaxRule('Delivery requires the taxability of the delivered goods.', RefusalReason::DeliveryFactsRequired);
        }

        $key = $delivery->component->value.($delivery->goodsTaxable ? '_on_taxable_goods' : '_on_exempt_goods');
        $payload = null;

        foreach ($this->dataset->rulesOn(UsCode::of($state), 'taxable_base', $at) as $rule) {
            $candidate = Shape::map($rule['payload'] ?? null);

            if (($candidate['component'] ?? null) !== $key) {
                continue;
            }

            if ($payload !== null) {
                throw new UnresolvedTaxRule('Overlapping delivery rules for '.$state->value.'.');
            }

            $payload = $candidate;
        }

        if ($payload === null) {
            return null;
        }

        if (isset($payload['category']) || array_key_exists('proportion', $payload)) {
            throw new UnresolvedTaxRule('Ambiguous or unsupported delivery rule for '.$state->value.': a category or proportion scope.');
        }

        if (array_key_exists('decision', $payload)) {
            return $this->decided(Shape::map($payload['decision']), $delivery, $key.' in '.$state->value);
        }

        if (! is_bool($payload['included'] ?? null)) {
            throw new UnresolvedTaxRule('Delivery rule for '.$state->value.' states neither an inclusion nor a decision.');
        }

        return new DeliveryTreatment($payload['included'], conditionsVerified: false);
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function decided(array $decision, DeliveryCharge $delivery, string $where): DeliveryTreatment
    {
        // The engine knows whether the delivered goods were taxable; it does not know
        // what else shared the parcel. A host that states the fact is believed.
        //
        // TWO MORE IT KNOWS FROM THE SHAPE OF THE SALE. A delivery charge here is a
        // line on the customer's own invoice for delivering the goods on it, so its
        // purpose is delivery to the customer — freight-in, a fuel surcharge or a
        // charge-back on returned goods is not a line on a sale. And a sale is
        // delivered to ONE place, where direct mail is by definition sent to the
        // addressees on a mailing list. Kansas asks both from release 280, and without
        // them every Kansas order with shipping refused. A host that knows otherwise —
        // a printer mailing a client's list — says so and is believed.
        $facts = $delivery->facts
            ->withDefault('delivery.containsExemptGoods', ! $delivery->goodsTaxable)
            ->withDefault('delivery.purpose', 'customer_delivery')
            ->withDefault('delivery.isDirectMail', false);
        $outcome = Decision::evaluate($decision, $facts, $where);

        if (! $outcome->resolved()) {
            $missing = array_values(array_filter(Decision::required($decision), static fn (string $fact): bool => ! $facts->has($fact)));

            throw new UnresolvedTaxRule(
                sprintf(
                    'The published delivery rule for %s is %s%s%s.',
                    $where,
                    $outcome->status,
                    $outcome->reason === null ? '' : ': '.rtrim($outcome->reason, '.'),
                    $missing === [] ? '' : ' — supply '.implode(', ', $missing),
                ),
                $outcome->status === 'unknown' ? RefusalReason::DeliveryFactsRequired : RefusalReason::TaxRuleUnsupported,
            );
        }

        $treatment = Shape::text($outcome->fields['baseTreatment'] ?? null);
        $allocation = Shape::text($outcome->fields['allocation'] ?? null);
        $rateBasis = Shape::text($outcome->fields['rateBasis'] ?? null);

        // An inclusion is priced at the delivered goods' rate — the only rate this
        // engine has for freight. Anything that needs an allocation across goods, or a
        // rate of its own, is a case the decision resolved and this engine cannot.
        if ($allocation !== 'not_needed') {
            throw new UnresolvedTaxRule(sprintf('The published delivery rule for %s needs an allocation (%s) this engine does not perform.', $where, $allocation ?? 'unstated'));
        }

        return match (true) {
            $treatment === 'excluded' => new DeliveryTreatment(included: false, conditionsVerified: true),
            $treatment === 'included' && $rateBasis === 'underlying_goods' => new DeliveryTreatment(included: true, conditionsVerified: true),
            default => throw new UnresolvedTaxRule(sprintf('The published delivery rule for %s resolves to %s on a %s rate basis, which this engine does not price.', $where, $treatment ?? 'nothing', $rateBasis ?? 'unstated')),
        };
    }
}
