<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ConsolidationGroups\Pages;

use App\Filament\App\Resources\ConsolidationGroups\ConsolidationGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditConsolidationGroup extends EditRecord
{
    #[\Override]
    protected static string $resource = ConsolidationGroupResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        ConsolidationGroupResource::enforceAllowedMembers($this->record);
    }
}
