<?php

namespace App\Policies;

use App\Models\ApplicationPaymentGateway;
use App\Models\User;

class ApplicationPaymentGatewayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, ApplicationPaymentGateway $gateway): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, ApplicationPaymentGateway $gateway): bool
    {
        return $user->is_admin;
    }
}
