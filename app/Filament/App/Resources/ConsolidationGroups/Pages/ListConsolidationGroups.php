<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ConsolidationGroups\Pages;

use App\Filament\App\Resources\ConsolidationGroups\ConsolidationGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConsolidationGroups extends ListRecords
{
    #[\Override]
    protected static string $resource = ConsolidationGroupResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
