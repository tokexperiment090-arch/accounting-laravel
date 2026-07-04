<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorCredits\Pages;

use App\Filament\Vendor\Resources\VendorCredits\PortalVendorCreditResource;
use Filament\Resources\Pages\ListRecords;

class ListPortalVendorCredits extends ListRecords
{
    #[\Override]
    protected static string $resource = PortalVendorCreditResource::class;
}
