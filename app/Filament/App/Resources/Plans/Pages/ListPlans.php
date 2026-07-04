<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Plans\Pages;

use App\Filament\App\Resources\Plans\PlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlans extends ListRecords
{
    #[\Override]
    protected static string $resource = PlanResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
