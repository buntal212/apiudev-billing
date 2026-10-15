<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\ApplicationPaymentGateway;
use App\Services\DanaGatewayMerchantCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('application:dana-gateway:map {application : Kode aplikasi UDEV} {merchant : Kode merchant pada DANA Gateway} {--environment=sandbox : Environment gateway}')]
#[Description('Memetakan aplikasi UDEV ke merchant DANA Gateway tanpa menyimpan API key')]
class MapApplicationDanaGateway extends Command
{
    public function handle(DanaGatewayMerchantCatalog $merchantCatalog): int
    {
        $application = Application::query()->where('code', $this->argument('application'))->firstOrFail();
        $environment = $this->option('environment');
        $merchantCatalog->ensureUsable($this->argument('merchant'), $environment);

        ApplicationPaymentGateway::query()->updateOrCreate(
            [
                'application_id' => $application->getKey(),
                'provider' => 'dana',
                'environment' => $environment,
            ],
            [
                'gateway_merchant_code' => $this->argument('merchant'),
                'is_active' => true,
            ],
        );

        $this->info('Mapping merchant DANA Gateway tersimpan tanpa API key.');

        return self::SUCCESS;
    }
}
