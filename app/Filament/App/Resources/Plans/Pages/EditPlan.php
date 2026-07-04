<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Plans\Pages;

use App\Filament\App\Resources\Plans\PlanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlan extends EditRecord
{
    #[\Override]
    protected static string $resource = PlanResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
