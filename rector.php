<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\BooleanNot\NegatedAndsToPositiveOrsRector;
use Rector\CodeQuality\Rector\BooleanNot\SimplifyDeMorganBinaryRector;
use Rector\CodeQuality\Rector\BooleanOr\RepeatedOrEqualToInArrayRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodeQuality\Rector\If_\CombineIfRector;
use Rector\CodeQuality\Rector\Ternary\SwitchNegatedTernaryRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\FunctionLike\NarrowWideUnionReturnTypeRector;
use Rector\DeadCode\Rector\If_\ReduceAlwaysFalseIfOrRector;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\DeadCode\Rector\MethodCall\RemoveNullArgOnNullDefaultParamRector;
use Rector\Php71\Rector\FuncCall\RemoveExtraParametersRector;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;

/*
 * The PHP 8.4 set and the dead-code, code-quality and type-declaration sets, less
 * the rules that trade this codebase's reading order for another style — or that
 * would remove a guard the code keeps on purpose.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/bin',
        __DIR__.'/config',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        // Style: `=== null` reads as the question the code asks; an instanceof of the
        // other type, a flipped ternary or a De Morgan rewrite asks it backwards.
        FlipTypeControlToUseExclusiveTypeRector::class,
        SwitchNegatedTernaryRector::class,
        NegatedAndsToPositiveOrsRector::class,
        SimplifyDeMorganBinaryRector::class,
        CombineIfRector::class,
        LocallyCalledStaticMethodToNonStaticRector::class,
        // An explicit null argument says the caller chose it.
        RemoveNullArgOnNullDefaultParamRector::class,
        // Guards at the JSON boundary stay even where a docblock promises the shape.
        ReduceAlwaysFalseIfOrRector::class,
        RemoveAlwaysTrueIfConditionRector::class,
        // A cast to quiet an unknown type hides it; the fix is the type.
        NullToStrictStringFuncCallArgRector::class,
        // A test double may declare the contract's own return type.
        NarrowWideUnionReturnTypeRector::class,
        // The byte scanner compares characters with === on purpose: it runs per byte.
        RepeatedOrEqualToInArrayRector::class => [__DIR__.'/src/Register/Compile/JsonArrayStream.php'],
        // It found real bugs — Pest's expect() takes one argument, and the labels
        // passed as a second were never shown — which are fixed where they are, not
        // deleted.
        RemoveExtraParametersRector::class,
    ]);
