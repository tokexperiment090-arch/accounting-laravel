<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\PurchaseRequests\Pages;

use App\Filament\App\Resources\PurchaseRequests\PurchaseRequestResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseRequest extends EditRecord
{
    #[\Override]
    protected static string $resource = PurchaseRequestResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
