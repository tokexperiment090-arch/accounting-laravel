<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\RevenueSchedules\Pages;

use App\Filament\App\Resources\RevenueSchedules\RevenueScheduleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRevenueSchedule extends CreateRecord
{
    #[\Override]
    protected static string $resource = RevenueScheduleResource::class;

    #[\Override]
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            return app(\App\Services\RevenueRecognitionService::class)->createFromInvoice(
                \App\Models\Invoice::findOrFail($data['invoice_id']),
                (int) $data['periods'],
                \App\Models\Account::findOrFail($data['deferred_account_id']),
                \App\Models\Account::findOrFail($data['revenue_account_id']),
            );
        } catch (\InvalidArgumentException $e) {
            \Filament\Notifications\Notification::make()
                ->title('Cannot create revenue schedule')
                ->body($e->getMessage())
                ->danger()
                ->send();
            $this->halt();
        }
    }
}
