<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Invoices\RelationManagers;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\DocumentService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('currentVersion.version_number')
                    ->label('Version'),
                TextColumn::make('retention_until')
                    ->date(),
            ])
            ->headerActions([
                Action::make('attach')
                    ->label('Attach')
                    ->form([
                        FileUpload::make('file')
                            ->storeFiles(false)
                            ->required(),
                        DatePicker::make('retention_until'),
                    ])
                    ->action(function (array $data): void {
                        /** @var UploadedFile $file */
                        $file = $data['file'];
                        /** @var string|null $retentionUntil */
                        $retentionUntil = $data['retention_until'] ?? null;

                        app(DocumentService::class)->attach(
                            $this->getOwnerRecord(),
                            $file,
                            filled($retentionUntil) ? Carbon::parse($retentionUntil) : null,
                        );
                    }),
            ])
            ->recordActions([
                Action::make('uploadVersion')
                    ->label('Upload new version')
                    ->form([
                        FileUpload::make('file')
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->action(function (Document $record, array $data): void {
                        /** @var UploadedFile $file */
                        $file = $data['file'];

                        app(DocumentService::class)->addVersion($record, $file);
                    }),
                Action::make('download')
                    ->label('Download')
                    ->action(function (Document $record) {
                        /** @var DocumentVersion|null $version */
                        $version = $record->currentVersion;

                        if ($version === null) {
                            abort(404);
                        }

                        return Storage::disk($record->disk)->download($version->path, $record->name);
                    }),
            ]);
    }
}
