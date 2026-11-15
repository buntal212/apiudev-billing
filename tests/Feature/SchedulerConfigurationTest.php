<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchedulerConfigurationTest extends TestCase
{
    use RefreshDatabase;

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
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $reconcileEvents = $events->filter(fn ($e) => str_contains($e->command, 'payments:reconcile-pending'));
        $this->assertCount(1, $reconcileEvents, 'payments:reconcile-pending harus terdaftar tepat 1 kali.');
        $reconcile = $reconcileEvents->first();
        $this->assertSame('* * * * *', $reconcile->expression);
        $this->assertTrue($reconcile->withoutOverlapping);
        $this->assertTrue($reconcile->onOneServer);

        $checkoutEvents = $events->filter(fn ($e) => str_contains($e->command, 'checkouts:dispatch-pending'));
        $this->assertCount(1, $checkoutEvents, 'checkouts:dispatch-pending harus terdaftar tepat 1 kali.');
        $checkout = $checkoutEvents->first();
        $this->assertSame('* * * * *', $checkout->expression);
        $this->assertTrue($checkout->withoutOverlapping);
        $this->assertTrue($checkout->onOneServer);
    }

    public function test_dispatch_pending_checkouts_command_supports_plural_alias(): void
    {
        $this->artisan('checkouts:dispatch-pending', ['--help' => true])
            ->assertExitCode(0);
    }

    public function test_cache_locks_table_supports_database_atomic_lock_for_on_one_server(): void
    {
        $this->assertTrue(Schema::hasTable('cache_locks'), 'Tabel cache_locks harus tersedia di schema.');
        $this->assertTrue(Schema::hasColumn('cache_locks', 'key'));
        $this->assertTrue(Schema::hasColumn('cache_locks', 'owner'));
        $this->assertTrue(Schema::hasColumn('cache_locks', 'expiration'));

        Config::set('cache.default', 'database');
        Config::set('cache.stores.database.table', 'cache');
        Config::set('cache.stores.database.lock_table', 'cache_locks');
        app('cache')->forgetDriver();

        $this->artisan('list');
        $schedule = app(Schedule::class);
        $schedule->useCache('database');
        $event = collect($schedule->events())
            ->first(fn ($e) => str_contains($e->command, 'payments:reconcile-pending'));

        $this->assertNotNull($event);
        $this->assertTrue($event->onOneServer);

        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:05'));

        // Evaluasi pertama pada menit 10:00 harus mengembalikan true dan menyimpan lock
        $canRunFirst = $schedule->serverShouldRun($event, Carbon::now());
        $this->assertTrue($canRunFirst, 'Server pertama harus diizinkan mengeksekusi scheduler.');

        $lockRecord = DB::table('cache_locks')->first();
        $this->assertNotNull($lockRecord, 'Lock record harus tersimpan di cache_locks.');
        $this->assertStringContainsString('1000', $lockRecord->key, 'Kunci lock harus memiliki format Hi (1000).');
        $this->assertGreaterThan(Carbon::now()->getTimestamp(), $lockRecord->expiration);

        // Simulasi server kedua atau proses artisan baru pada menit yang sama (10:00:25)
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:25'));
        $scheduleServer2 = new Schedule();
        $scheduleServer2->useCache('database');
        $canRunSecondInSameMinute = $scheduleServer2->serverShouldRun($event, Carbon::now());
        $this->assertFalse(
            $canRunSecondInSameMinute,
            'Server kedua atau eksekusi ganda pada menit yang sama harus ditolak (deduplikasi antar-server).'
        );

        // Simulasi menit berikutnya (10:01:00) pada proses artisan baru
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:01:00'));
        $scheduleServer3 = new Schedule();
        $scheduleServer3->useCache('database');
        $canRunNextMinute = $scheduleServer3->serverShouldRun($event, Carbon::now());
        $this->assertTrue(
            $canRunNextMinute,
            'Pada pergantian menit (10:01), lock baru harus berhasil diperoleh.'
        );

        Carbon::setTestNow();
    }

    public function test_schedule_run_executes_commands_and_records_on_one_server_locks_with_database_cache(): void
    {
        Config::set('cache.default', 'database');
        Config::set('cache.stores.database.table', 'cache');
        Config::set('cache.stores.database.lock_table', 'cache_locks');
        app('cache')->forgetDriver();

        $schedule = app(Schedule::class);
        $schedule->useCache('database');

        Carbon::setTestNow(Carbon::parse('2026-11-15 11:30:00'));

        $this->artisan('schedule:run')
            ->expectsOutputToContain('payments:reconcile-pending')
            ->expectsOutputToContain('checkouts:dispatch-pending')
            ->assertExitCode(0);

        // Verifikasi bahwa onOneServer mutex tersimpan di cache_locks untuk kedua command
        $locks1130 = DB::table('cache_locks')->where('key', 'like', '%1130%')->get();
        $this->assertCount(2, $locks1130, 'Harus ada 2 kunci onOneServer untuk menit 1130 di cache_locks.');

        // Verifikasi bahwa withoutOverlapping lock sudah dibersihkan setelah selesai eksekusi
        $overlappingLocks = DB::table('cache_locks')->where('key', 'not like', '%1130%')->get();
        $this->assertCount(0, $overlappingLocks, 'Lock withoutOverlapping harus sudah dibersihkan setelah task selesai.');

        Carbon::setTestNow();
    }

    public function test_schedule_run_skips_when_command_already_ran_in_same_minute(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 11:30:00'));

        Config::set('cache.default', 'database');
        Config::set('cache.stores.database.table', 'cache');
        Config::set('cache.stores.database.lock_table', 'cache_locks');
        app('cache')->forgetDriver();
        $this->artisan('list');

        $schedule = app(Schedule::class);
        $schedule->useCache('database');

        // Pertama: simulasikan server 1 sudah mengeksekusi di menit 11:30:00
        foreach ($schedule->events() as $event) {
            $schedule->serverShouldRun($event, Carbon::now());
        }

        $locks = DB::table('cache_locks')->get();
        $this->assertCount(2, $locks, 'Kunci server 1 harus sudah tersimpan di cache_locks.');

        // Kedua: server 2 atau eksekusi kedua di menit 11:30:25 harus skip dengan pesan standar Laravel
        Carbon::setTestNow(Carbon::parse('2026-11-15 11:30:25'));
        $reflection = new \ReflectionClass($schedule);
        $property = $reflection->getProperty('mutexCache');
        $property->setAccessible(true);
        $property->setValue($schedule, []);

        $this->artisan('schedule:run')
            ->expectsOutputToContain('because the command already ran on another server.')
            ->assertExitCode(0);

        Carbon::setTestNow();
    }

    public function test_schedule_run_executes_on_new_minute_even_if_previous_minute_ran(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 11:31:00'));

        Config::set('cache.default', 'database');
        Config::set('cache.stores.database.table', 'cache');
        Config::set('cache.stores.database.lock_table', 'cache_locks');
        app('cache')->forgetDriver();
        $this->artisan('list');

        $schedule = app(Schedule::class);
        $schedule->useCache('database');

        // Simulasikan menit sebelumnya (11:30) sudah pernah berjalan dan meninggalkan lock 1130
        $previousMinute = Carbon::parse('2026-11-15 11:30:00');
        foreach ($schedule->events() as $event) {
            $schedule->serverShouldRun($event, $previousMinute);
        }
        $this->assertCount(2, DB::table('cache_locks')->where('key', 'like', '%1130%')->get());

        // Pada menit baru (11:31:00), scheduler harus kembali menjalankan event
        $reflection = new \ReflectionClass($schedule);
        $property = $reflection->getProperty('mutexCache');
        $property->setAccessible(true);
        $property->setValue($schedule, []);

        $this->artisan('schedule:run')
            ->expectsOutputToContain('payments:reconcile-pending')
            ->expectsOutputToContain('checkouts:dispatch-pending')
            ->assertExitCode(0);

        $locks1131 = DB::table('cache_locks')->where('key', 'like', '%1131%')->get();
        $this->assertCount(2, $locks1131, 'Harus ada 2 kunci baru untuk menit 1131 di cache_locks.');

        Carbon::setTestNow();
    }

    public function test_expired_on_one_server_lock_can_be_reacquired_and_updated(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 11:30:00'));

        Config::set('cache.default', 'database');
        Config::set('cache.stores.database.table', 'cache');
        Config::set('cache.stores.database.lock_table', 'cache_locks');
        app('cache')->forgetDriver();
        $this->artisan('list');

        $schedule = app(Schedule::class);
        $schedule->useCache('database');
        $event = collect($schedule->events())
            ->first(fn ($e) => str_contains($e->command, 'payments:reconcile-pending'));

        // Simulasikan record lock 24 jam lalu (expired) dengan key yang sama untuk menit 1130
        $prefix = config('cache.prefix', '');
        $expectedKey = $prefix.$event->mutexName().'1130';
        DB::table('cache_locks')->insert([
            'key' => $expectedKey,
            'owner' => 'old_owner',
            'expiration' => Carbon::now()->getTimestamp() - 3600, // expired 1 jam lalu
        ]);

        $this->assertDatabaseHas('cache_locks', [
            'key' => $expectedKey,
            'owner' => 'old_owner',
        ]);

        // serverShouldRun harus berhasil mengambil alih (acquire) lock yang sudah expired
        $canRun = $schedule->serverShouldRun($event, Carbon::now());
        $this->assertTrue($canRun, 'Lock yang sudah expired harus dapat diambil alih kembali.');

        $updatedRecord = DB::table('cache_locks')->where('key', $expectedKey)->first();
        $this->assertNotNull($updatedRecord);
        $this->assertNotSame('old_owner', $updatedRecord->owner);
        $this->assertGreaterThan(Carbon::now()->getTimestamp(), $updatedRecord->expiration);

        Carbon::setTestNow();
    }

    public function test_diagnose_schedule_locks_command_executes_successfully(): void
    {
        $this->artisan('schedule:diagnose-locks')
            ->expectsOutputToContain('Diagnosis Scheduler onOneServer & Atomic Locks')
            ->expectsOutputToContain('Status Tabel Database')
            ->expectsOutputToContain('Pengujian Atomic Lock Driver')
            ->expectsOutputToContain('Audit Mutex Scheduler Saat Ini')
            ->assertExitCode(0);
    }
}





