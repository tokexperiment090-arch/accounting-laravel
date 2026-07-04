<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\SalesOrders\Pages;

use App\Filament\App\Resources\SalesOrders\SalesOrderResource;
use Filament\Resources\Pages\EditRecord;

class EditSalesOrder extends EditRecord
{
    #[\Override]
    protected static string $resource = SalesOrderResource::class;
}
