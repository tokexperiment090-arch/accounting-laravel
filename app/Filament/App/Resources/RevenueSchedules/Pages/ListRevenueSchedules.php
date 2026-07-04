<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\RevenueSchedules\Pages;

use App\Filament\App\Resources\RevenueSchedules\RevenueScheduleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRevenueSchedules extends ListRecords
{
    #[\Override]
    protected static string $resource = RevenueScheduleResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
