<?php

namespace Tests\Feature;

use Tests\TestCase;

class SchedulerConfigurationTest extends TestCase
{
    public function test_payments_reconcile_and_checkout_dispatch_are_scheduled_in_schedule_list(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('payments:reconcile-pending --limit=25')
            ->expectsOutputToContain('checkouts:dispatch-pending --limit=50')
            ->assertExitCode(0);
    }

    public function test_scheduled_events_have_correct_cron_and_overlapping_lock(): void
    {
        $this->artisan('list');
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $events = collect($schedule->events());

        $reconcileEvents = $events->filter(fn ($e) => str_contains($e->command, 'payments:reconcile-pending'));
        $this->assertCount(1, $reconcileEvents, 'payments:reconcile-pending harus terdaftar tepat 1 kali.');
        $reconcile = $reconcileEvents->first();
        $this->assertSame('* * * * *', $reconcile->expression);
        $this->assertTrue($reconcile->withoutOverlapping);

        $checkoutEvents = $events->filter(fn ($e) => str_contains($e->command, 'checkouts:dispatch-pending'));
        $this->assertCount(1, $checkoutEvents, 'checkouts:dispatch-pending harus terdaftar tepat 1 kali.');
        $checkout = $checkoutEvents->first();
        $this->assertSame('* * * * *', $checkout->expression);
        $this->assertTrue($checkout->withoutOverlapping);
    }

    public function test_dispatch_pending_checkouts_command_supports_plural_alias(): void
    {
        $this->artisan('checkouts:dispatch-pending', ['--help' => true])
            ->assertExitCode(0);
    }
}

