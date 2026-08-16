<?php

declare(strict_types=1);

use Cbox\Tax\Exceptions\Malformed;
use Cbox\Tax\Exceptions\Refusal;
use Cbox\Tax\Exceptions\Transient;

/**
 * Every exception this engine throws must declare which of four things it is, because
 * a consumer has to do something different about each.
 *
 * | Category                 | What it means                | Over HTTP |
 * | ------------------------ | ---------------------------- | --------- |
 * | {@see Refusal}           | We will not guess            | 422       |
 * | {@see Transient}         | Upstream is down             | 503       |
 * | {@see Malformed}         | Send something different     | 400       |
 * | *(none — a defect)*      | We are broken                | 500       |
 *
 * WITHOUT THIS TEST A NEW EXCEPTION FALLS TO 500 SILENTLY. That is survivable for a
 * defect and wrong for everything else: a refusal reported as a server error tells a
 * shop's checkout to retry a question that will never have a different answer, and
 * the shop retries instead of falling back to its own rate and flagging the line.
 *
 * The defect list is spelled out rather than inferred from the absence of a marker,
 * so adding an exception forces a decision instead of inheriting one.
 */

/** Exceptions that are genuinely OUR fault and deliberately carry no category. */
const DEFECTS = [
    // A sourced rate outside any plausible domain. The data is wrong, not the caller.
    'ImplausibleTaxRate',
    // Components that do not sum to the tax they compose. An invariant we broke.
    'RateComponentsDoNotReconcile',
];

/** @return list<array{0: string, 1: class-string}> */
function taxExceptions(): array
{
    $out = [];

    foreach (glob(dirname(__DIR__, 2).'/src/Exceptions/*.php') ?: [] as $file) {
        $name = basename($file, '.php');
        $class = 'Cbox\\Tax\\Exceptions\\'.$name;

        // The markers themselves are interfaces, not exceptions.
        if (interface_exists($class)) {
            continue;
        }

        $out[] = [$name, $class];
    }

    return $out;
}

it('puts every exception in exactly one category', function (string $name, string $class) {
    $markers = array_values(array_filter(
        [Refusal::class, Transient::class, Malformed::class],
        static fn (string $marker): bool => is_a($class, $marker, true),
    ));

    if (in_array($name, DEFECTS, true)) {
        expect($markers)->toBe([], sprintf(
            '%s is listed as a defect but carries a category marker. A defect is a 500 because we are '
            .'broken; if it is really one of the others, take it off the list.',
            $name,
        ));

        return;
    }

    expect($markers)->toHaveCount(1, sprintf(
        "%s carries %d category markers, needs exactly 1.\n"
        ."Refusal (422, we will not guess), Transient (503, retry), Malformed (400, send something else),\n"
        .'or add it to DEFECTS if it means we are broken. Uncategorised, a consumer gets a 500 and retries '
        .'a question that will never answer differently.',
        $name,
        count($markers),
    ));
})->with(taxExceptions());

// The list is a decision record, not a filter. An entry for a class that no longer
// exists means the decision outlived the thing it was about.
it('lists no defect that has been deleted', function () {
    $names = array_column(taxExceptions(), 0);

    expect(array_values(array_diff(DEFECTS, $names)))->toBe([]);
});
