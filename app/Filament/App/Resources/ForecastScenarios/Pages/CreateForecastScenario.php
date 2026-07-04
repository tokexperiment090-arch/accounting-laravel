<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ForecastScenarios\Pages;

use App\Filament\App\Resources\ForecastScenarios\ForecastScenarioResource;
use Filament\Resources\Pages\CreateRecord;

class CreateForecastScenario extends CreateRecord
{
    #[\Override]
    protected static string $resource = ForecastScenarioResource::class;
}
