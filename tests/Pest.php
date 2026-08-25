<?php

use Goldnead\Invoices\Tests\TestCase;

/*
 * Pest kann PHPUnit-Klassen mitlaufen lassen, aber nur wenn es der Runner ist.
 * Deshalb ist `vendor/bin/pest` hier der einzige Weg: die Feature-Tests sind
 * klassenbasiert, die Steuerregeln in Pest-Syntax geschrieben.
 */
uses(TestCase::class)->in('Feature');
