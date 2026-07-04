<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Subscriptions\Pages;

use App\Filament\App\Resources\Subscriptions\SubscriptionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSubscription extends CreateRecord
{
    #[\Override]
    protected static string $resource = SubscriptionResource::class;
}
