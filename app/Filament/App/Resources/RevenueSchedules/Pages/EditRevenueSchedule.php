<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\RevenueSchedules\Pages;

use App\Filament\App\Resources\RevenueSchedules\RevenueScheduleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRevenueSchedule extends EditRecord
{
    #[\Override]
    protected static string $resource = RevenueScheduleResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
