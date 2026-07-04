<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Subscriptions\Pages;

use App\Filament\App\Resources\Subscriptions\SubscriptionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSubscription extends EditRecord
{
    #[\Override]
    protected static string $resource = SubscriptionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
