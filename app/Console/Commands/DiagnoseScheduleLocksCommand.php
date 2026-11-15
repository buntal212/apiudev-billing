<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\CacheSchedulingMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

#[Signature('schedule:diagnose-locks')]
#[Description('Mendiagnosis atomic lock cache, ketersediaan tabel cache_locks, dan status onOneServer scheduler')]
class DiagnoseScheduleLocksCommand extends Command
{
    public function handle(Schedule $schedule): int
    {
        $schedulingMutex = app()->bound(SchedulingMutex::class)
            ? app(SchedulingMutex::class)
            : new CacheSchedulingMutex(app('cache'));
        $this->info('=== Diagnosis Scheduler onOneServer & Atomic Locks ===');
        $this->newLine();

        $defaultStore = Config::get('cache.default');
        $storeConfig = Config::get("cache.stores.{$defaultStore}", []);
        $driver = $storeConfig['driver'] ?? $defaultStore;
        $connectionName = $storeConfig['connection'] ?? null;
        $lockConnectionName = $storeConfig['lock_connection'] ?? $connectionName;
        $tableName = $storeConfig['table'] ?? 'cache';
        $lockTableName = $storeConfig['lock_table'] ?? 'cache_locks';
        $prefix = Config::get('cache.prefix', '');

        $this->table(['Parameter', 'Nilai'], [
            ['Default Cache Store', (string) $defaultStore],
            ['Cache Driver', (string) $driver],
            ['Cache Prefix', (string) $prefix],
            ['Connection DB', (string) ($connectionName ?: 'default')],
            ['Lock Connection DB', (string) ($lockConnectionName ?: 'default')],
            ['Cache Table', (string) $tableName],
            ['Lock Table', (string) $lockTableName],
        ]);
        $this->newLine();

        // 1. Verifikasi schema database
        $this->components->twoColumnDetail('<fg=cyan>1. Status Tabel Database</>');
        $connection = DB::connection($lockConnectionName);

        $hasCacheTable = false;
        $hasLockTable = false;
        try {
            $hasCacheTable = $connection->getSchemaBuilder()->hasTable($tableName);
            $hasLockTable = $connection->getSchemaBuilder()->hasTable($lockTableName);
            $this->components->twoColumnDetail("Tabel [{$tableName}]", $hasCacheTable ? '<fg=green;options=bold>TERSEDIA</>' : '<fg=red;options=bold>TIDAK DITEMUKAN</>');
            $this->components->twoColumnDetail("Tabel [{$lockTableName}]", $hasLockTable ? '<fg=green;options=bold>TERSEDIA</>' : '<fg=red;options=bold>TIDAK DITEMUKAN</>');
        } catch (Throwable $e) {
            $this->components->error('Gagal memeriksa schema tabel: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $hasLockTable) {
            $this->components->error("Tabel [{$lockTableName}] belum dibuat. Jalankan 'php artisan migrate' untuk membuat tabel cache_locks.");

            return self::FAILURE;
        }

        $this->newLine();

        // 2. Uji probe atomic lock
        $this->components->twoColumnDetail('<fg=cyan>2. Pengujian Atomic Lock Driver</>');
        $storeInstance = app('cache')->store($defaultStore)->getStore();
        $isLockProvider = $storeInstance instanceof LockProvider;
        $this->components->twoColumnDetail('Mendukung LockProvider', $isLockProvider ? '<fg=green>YA</>' : '<fg=yellow>TIDAK</>');

        $probeKey = 'diagnose_lock_probe_'.Str::random(8);
        try {
            $probeLock = app('cache')->store($defaultStore)->lock($probeKey, 10);
            $probeAcquired = $probeLock->acquire();
            if ($probeAcquired) {
                $probeLock->release();
                $this->components->twoColumnDetail('Tes Acquire & Release Lock', '<fg=green;options=bold>PASS</>');
            } else {
                $this->components->twoColumnDetail('Tes Acquire & Release Lock', '<fg=red;options=bold>GAGAL (acquire mengembalikan false)</>');
            }
        } catch (Throwable $e) {
            $this->components->twoColumnDetail('Tes Acquire & Release Lock', '<fg=red;options=bold>ERROR: '.$e->getMessage().'</>');

            return self::FAILURE;
        }

        $this->newLine();

        // 3. Status Events pada Scheduler
        $this->components->twoColumnDetail('<fg=cyan>3. Audit Mutex Scheduler Saat Ini</>');
        $now = Carbon::now();
        $currentHi = $now->format('Hi');
        $this->line("Waktu server evaluasi: <fg=yellow>{$now->toDateTimeString()}</> (Slot format Hi: <fg=yellow>{$currentHi}</>)");
        $this->newLine();

        $events = $schedule->events();
        $eventRows = [];

        foreach ($events as $event) {
            $commandSummary = $event->getSummaryForDisplay();
            $mutexName = $event->mutexName();
            $expectedLockKey = $prefix.$mutexName.$currentHi;

            // Cek withoutOverlapping
            $withoutOverlapping = $event->withoutOverlapping ? 'YA' : 'TIDAK';
            $overlappingActive = $event->withoutOverlapping && $event->mutex->exists($event) ? '<fg=red>AKTIF</>' : '<fg=green>BEBAS</>';

            // Cek onOneServer
            $onOneServer = $event->onOneServer ? 'YA' : 'TIDAK';
            $onOneServerActive = 'BEBAS';

            $lockRow = null;
            if ($hasLockTable) {
                try {
                    $lockRow = $connection->table($lockTableName)->where('key', $expectedLockKey)->first();
                } catch (Throwable) {
                    $lockRow = null;
                }
            }

            if ($lockRow) {
                $remaining = max(0, $lockRow->expiration - $now->getTimestamp());
                $onOneServerActive = "<fg=yellow>TERKUNCI (menit {$currentHi}, sisa {$remaining}s)</>";
            } elseif ($event->onOneServer && $schedulingMutex->exists($event, $now)) {
                $onOneServerActive = "<fg=yellow>TERKUNCI (slot {$currentHi})</>";
            } else {
                $onOneServerActive = '<fg=green>SIAP JALAN</>';
            }

            $eventRows[] = [
                $commandSummary,
                $event->expression,
                $withoutOverlapping." ({$overlappingActive})",
                $onOneServer." ({$onOneServerActive})",
            ];
        }

        $this->table([
            'Command Event',
            'Cron Expr',
            'WithoutOverlapping',
            'OnOneServer Status',
        ], $eventRows);

        $this->newLine();

        // 4. Catatan dan Rekomendasi
        $this->components->twoColumnDetail('<fg=cyan>4. Catatan Operasional & Rekomendasi</>');
        $this->line('• Mutex <fg=yellow>onOneServer()</> sengaja disimpan selama 3600 detik per slot menit (format Hi).');
        $this->line('  Jika scheduler dijalankan kembali dalam menit yang sama, notice <fg=gray>"already ran on another server"</> adalah perilaku NORMAL untuk mencegah eksekusi ganda.');
        $this->line('• Pada pergantian menit baru (misal :01 -> :02), kunci otomatis berganti dan scheduler akan dieksekusi kembali secara normal.');
        $this->line('• Mutex <fg=yellow>withoutOverlapping()</> otomatis dibersihkan begitu command selesai dieksekusi.');
        $this->newLine();

        return self::SUCCESS;
    }
}
