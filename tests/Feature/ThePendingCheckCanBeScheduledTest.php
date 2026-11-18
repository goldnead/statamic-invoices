<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\ServiceProvider;
use Goldnead\Invoices\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;

/**
 * `invoices.pending.schedule` an: die tägliche Prüfung "bezahlt ohne Rechnung"
 * steht auf dem Scheduler, nur zählend (ohne --write). Aus: nichts.
 */
class ThePendingCheckCanBeScheduledTest extends TestCase
{
    #[Test]
    public function the_pending_check_is_on_the_scheduler_without_write_when_switched_on(): void
    {
        config(['invoices.pending.schedule' => true]);

        $befehle = $this->geplant()->filter(fn ($e) => str_contains((string) $e->command, 'invoices:pending'));

        $this->assertCount(1, $befehle);
        $this->assertStringNotContainsString('--write', (string) $befehle->first()->command);
    }

    #[Test]
    public function nothing_is_scheduled_by_default(): void
    {
        $this->assertFalse((bool) config('invoices.pending.schedule'));
        $this->assertCount(0, $this->geplant()->filter(fn ($e) => str_contains((string) $e->command, 'invoices:pending')));
    }

    private function geplant()
    {
        $schedule = new Schedule;
        $methode = new \ReflectionMethod(ServiceProvider::class, 'schedule');
        $methode->invoke($this->app->getProvider(ServiceProvider::class), $schedule);

        return collect($schedule->events());
    }
}
