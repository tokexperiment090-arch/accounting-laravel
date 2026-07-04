<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\PurchaseRequests\Pages;

use App\Filament\App\Resources\PurchaseRequests\PurchaseRequestResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseRequest extends CreateRecord
{
    #[\Override]
    protected static string $resource = PurchaseRequestResource::class;
}
