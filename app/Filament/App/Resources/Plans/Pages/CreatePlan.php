<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Plans\Pages;

use App\Filament\App\Resources\Plans\PlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlan extends CreateRecord
{
    #[\Override]
    protected static string $resource = PlanResource::class;
}
