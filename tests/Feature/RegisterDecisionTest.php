<?php

declare(strict_types=1);

use Cbox\Tax\Exceptions\UnresolvedTaxRule;
use Cbox\Tax\Register\Reader\Decision;
use Cbox\Tax\ValueObjects\DecisionFacts;

/*
 * The evaluator for schema 2's published decision trees. Its grammar is small on
 * purpose — `eq`, `in`, `all`, three outcomes — and every case here is about the one
 * property that makes it safe: an absent fact is UNKNOWN, never false.
 */

function leaf(string $fact, string $op, mixed $value): array
{
    return ['fact' => $fact, 'op' => $op, 'value' => $value, 'says' => 'the statute'];
}

function node(array $condition, array $onTrue, array $onFalse, array $onUnknown = ['status' => 'unknown', 'reason' => 'missing']): array
{
    return ['version' => 1, 'condition' => $condition, 'onTrue' => $onTrue, 'onFalse' => $onFalse, 'onUnknown' => $onUnknown];
}

it('follows the branch its condition selects', function (array $facts, string $expected): void {
    $tree = node(leaf('delivery.separatelyStated', 'eq', true), ['status' => 'resolved', 'baseTreatment' => 'excluded'], ['status' => 'resolved', 'baseTreatment' => 'included']);

    $outcome = Decision::evaluate($tree, new DecisionFacts($facts), 'test');

    expect($outcome->status)->toBe($expected === 'unknown' ? 'unknown' : 'resolved')
        ->and($outcome->fields['baseTreatment'] ?? null)->toBe($expected === 'unknown' ? null : $expected);
})->with([
    'stated true' => [['delivery.separatelyStated' => true], 'excluded'],
    'stated false' => [['delivery.separatelyStated' => false], 'included'],
    'not stated' => [[], 'unknown'],
]);

it('treats a missing fact as unknown, not false, inside "all"', function (): void {
    // The whole point. "The host did not say whether freight was separately stated"
    // must not become "it was not", and take the branch that taxes it.
    $tree = node(
        ['all' => [leaf('delivery.purpose', 'eq', 'customer_delivery'), leaf('delivery.isDirectMail', 'eq', false)]],
        ['status' => 'resolved', 'baseTreatment' => 'excluded'],
        ['status' => 'resolved', 'baseTreatment' => 'included'],
    );

    expect(Decision::evaluate($tree, new DecisionFacts(['delivery.purpose' => 'customer_delivery']), 'test')->status)->toBe('unknown')
        // ...but one false part settles it, whatever else is missing.
        ->and(Decision::evaluate($tree, new DecisionFacts(['delivery.purpose' => 'freight_in']), 'test')->fields['baseTreatment'])->toBe('included');
});

it('matches "in" against a published list', function (string $label, string $expected): void {
    $tree = node(leaf('delivery.label', 'in', ['delivery', 'transportation']), ['status' => 'resolved', 'baseTreatment' => 'excluded'], ['status' => 'resolved', 'baseTreatment' => 'included']);

    expect(Decision::evaluate($tree, new DecisionFacts(['delivery.label' => $label]), 'test')->fields['baseTreatment'])->toBe($expected);
})->with([['transportation', 'excluded'], ['shipping and handling', 'included']]);

it('compares without loose coercion', function (): void {
    // "0" is not false, and 1 is not true — PHP's == would say both are.
    $tree = node(leaf('delivery.isDirectMail', 'eq', false), ['status' => 'resolved', 'baseTreatment' => 'excluded'], ['status' => 'resolved', 'baseTreatment' => 'included']);

    expect(Decision::evaluate($tree, new DecisionFacts(['delivery.isDirectMail' => '0']), 'test')->fields['baseTreatment'])->toBe('included');
});

it('walks nested nodes and reports why a branch is unsupported', function (): void {
    $tree = node(
        leaf('delivery.separatelyStated', 'eq', true),
        ['status' => 'resolved', 'baseTreatment' => 'excluded'],
        node(leaf('delivery.containsExemptGoods', 'eq', false), ['status' => 'resolved', 'baseTreatment' => 'included'], ['status' => 'unsupported', 'reason' => 'Mixed goods need an allocation.']),
    );

    $outcome = Decision::evaluate($tree, new DecisionFacts(['delivery.separatelyStated' => false, 'delivery.containsExemptGoods' => true]), 'test');

    expect($outcome->status)->toBe('unsupported')->and($outcome->reason)->toBe('Mixed goods need an allocation.');
});

it('refuses anything outside the grammar rather than skipping it', function (array $tree): void {
    Decision::evaluate($tree, new DecisionFacts(['delivery.label' => 'delivery']), 'test');
})->with([
    'another version' => [['version' => 2, 'condition' => leaf('delivery.label', 'eq', 'delivery'), 'onTrue' => ['status' => 'resolved'], 'onFalse' => ['status' => 'resolved']]],
    'another operator' => [node(leaf('delivery.label', 'starts_with', 'del'), ['status' => 'resolved'], ['status' => 'resolved'])],
    'another combinator' => [node(['any' => [leaf('delivery.label', 'eq', 'delivery')]], ['status' => 'resolved'], ['status' => 'resolved'])],
    'an empty "all"' => [node(['all' => []], ['status' => 'resolved'], ['status' => 'resolved'])],
    'a malformed "all" part' => [node(['all' => ['delivery.label']], ['status' => 'resolved'], ['status' => 'resolved'])],
    'another status' => [node(leaf('delivery.label', 'eq', 'delivery'), ['status' => 'probably'], ['status' => 'resolved'])],
    'a missing branch' => [['version' => 1, 'condition' => leaf('delivery.label', 'eq', 'delivery'), 'onFalse' => ['status' => 'resolved']]],
])->throws(UnresolvedTaxRule::class);

it('accepts only the register\'s dotted fact names', function (): void {
    new DecisionFacts(['separatelyStated' => true]);
})->throws(InvalidArgumentException::class, 'dotted names');
