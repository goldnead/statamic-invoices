<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;

/**
 * `delivery.retry.schedule` false: a host that schedules `invoices:retry`
 * itself gets no second, competing entry from the addon.
 */
class TheRetryScheduleCanBeSwitchedOffTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('invoices.delivery.retry.schedule', false);
    }

    #[Test]
    public function nothing_is_registered_on_the_scheduler(): void
    {
        $befehle = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'invoices:retry'));

        $this->assertCount(0, $befehle);
    }
}
