<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ForecastScenarios\Pages;

use App\Filament\App\Resources\ForecastScenarios\ForecastScenarioResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListForecastScenarios extends ListRecords
{
    #[\Override]
    protected static string $resource = ForecastScenarioResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
