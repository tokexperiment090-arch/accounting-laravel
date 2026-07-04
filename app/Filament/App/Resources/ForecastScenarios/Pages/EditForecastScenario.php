<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ForecastScenarios\Pages;

use App\Filament\App\Resources\ForecastScenarios\ForecastScenarioResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditForecastScenario extends EditRecord
{
    #[\Override]
    protected static string $resource = ForecastScenarioResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
