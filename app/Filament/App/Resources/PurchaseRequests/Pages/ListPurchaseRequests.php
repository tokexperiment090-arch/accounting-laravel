<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\PurchaseRequests\Pages;

use App\Filament\App\Resources\PurchaseRequests\PurchaseRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseRequests extends ListRecords
{
    #[\Override]
    protected static string $resource = PurchaseRequestResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
