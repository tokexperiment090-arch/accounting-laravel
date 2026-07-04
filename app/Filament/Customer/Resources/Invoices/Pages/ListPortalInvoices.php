<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\Invoices\Pages;

use App\Filament\Customer\Resources\Invoices\PortalInvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListPortalInvoices extends ListRecords
{
    #[\Override]
    protected static string $resource = PortalInvoiceResource::class;
}
