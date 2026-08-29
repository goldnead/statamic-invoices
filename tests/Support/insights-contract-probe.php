<?php

/**
 * Reads a contract out of PHP files and prints its shape as JSON.
 *
 * Run in its own process, on purpose and unavoidably: the stand-in and the real
 * package declare the same fully qualified names, and one process can hold only
 * one of them. Two processes can each load one and be compared afterwards,
 * which is the whole trick — the alternative is parsing PHP with regular
 * expressions, and a signature check that is itself approximate proves nothing.
 *
 * No autoloader is registered here, which is why `interface_exists` inside the
 * stand-in comes back false and the guarded declarations actually happen.
 *
 *     php tests/Support/insights-contract-probe.php <file> [<file> …]
 */
$dateien = array_slice($argv, 1);

foreach ($dateien as $datei) {
    if (is_file($datei)) {
        require_once $datei;
    }
}

/** Everything about a method that a caller can be broken by. */
$methoden = static function (ReflectionClass $klasse): array {
    $out = [];

    foreach ($klasse->getMethods(ReflectionMethod::IS_PUBLIC) as $methode) {
        if ($methode->getDeclaringClass()->getName() !== $klasse->getName()) {
            continue;
        }

        $parameter = [];

        foreach ($methode->getParameters() as $p) {
            $parameter[] = [
                'name' => $p->getName(),
                'type' => $p->hasType() ? (string) $p->getType() : null,
                'optional' => $p->isDefaultValueAvailable(),
                // The value, not just the fact of one: `int $limit = 20` and
                // `int $limit = 100` are different promises to a caller.
                'default' => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null,
                'variadic' => $p->isVariadic(),
                'reference' => $p->isPassedByReference(),
            ];
        }

        $out[$methode->getName()] = [
            'static' => $methode->isStatic(),
            'abstract' => $methode->isAbstract(),
            'parameters' => $parameter,
            'returns' => $methode->hasReturnType() ? (string) $methode->getReturnType() : null,
        ];
    }

    ksort($out);

    return $out;
};

$eigenschaften = static function (ReflectionClass $klasse): array {
    $out = [];

    foreach ($klasse->getProperties(ReflectionProperty::IS_PUBLIC) as $eigenschaft) {
        if ($eigenschaft->getDeclaringClass()->getName() !== $klasse->getName()) {
            continue;
        }

        $out[$eigenschaft->getName()] = [
            'type' => $eigenschaft->hasType() ? (string) $eigenschaft->getType() : null,
            'readonly' => $eigenschaft->isReadOnly(),
            'static' => $eigenschaft->isStatic(),
        ];
    }

    ksort($out);

    return $out;
};

$ergebnis = [];

foreach (['Contracts\Metric', 'Contracts\HasBreakdowns', 'Contracts\HasFilterOptions', 'Support\MetricQuery', 'Support\Period', 'Support\Unit'] as $kurz) {
    $name = 'Goldnead\StatamicInsights\\'.$kurz;

    // Without autoload: whatever the required files declared, and nothing else.
    if (! interface_exists($name, false) && ! class_exists($name, false)) {
        $ergebnis[$kurz] = null;

        continue;
    }

    $klasse = new ReflectionClass($name);

    $ergebnis[$kurz] = [
        'methods' => $methoden($klasse),
        'properties' => $eigenschaften($klasse),
        'constants' => $klasse->getConstants(),
    ];
}

echo json_encode($ergebnis, JSON_UNESCAPED_SLASHES);
