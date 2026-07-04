<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ConsolidationGroups\Pages;

use App\Filament\App\Resources\ConsolidationGroups\ConsolidationGroupResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateConsolidationGroup extends CreateRecord
{
    #[\Override]
    protected static string $resource = ConsolidationGroupResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The current tenant team owns the group.
        $data['owner_team_id'] = Filament::getTenant()?->getKey();

        return $data;
    }

    protected function afterCreate(): void
    {
        ConsolidationGroupResource::enforceAllowedMembers($this->record);
    }
}
