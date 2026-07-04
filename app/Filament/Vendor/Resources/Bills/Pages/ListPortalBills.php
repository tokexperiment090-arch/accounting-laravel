<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\Bills\Pages;

use App\Filament\Vendor\Resources\Bills\PortalBillResource;
use Filament\Resources\Pages\ListRecords;

class ListPortalBills extends ListRecords
{
    #[\Override]
    protected static string $resource = PortalBillResource::class;
}
