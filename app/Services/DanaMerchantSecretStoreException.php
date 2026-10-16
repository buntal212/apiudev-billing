<?php

namespace App\Services;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class DanaMerchantSecretStoreException extends RuntimeException implements ShouldntReport {}
