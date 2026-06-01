<?php

namespace App\Filament\Resources\TukarTambahResource\Pages;

use App\Filament\Resources\PenjualanResource;
use App\Filament\Resources\TukarTambahResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\StaticAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ViewTukarTambah extends ViewRecord
{
    protected static string $resource = TukarTambahResource::class;

    public function getRelationManagers(): array
    {
        return [];
    }

    public array $editBlockedPenjualanReferences = [];

    public array $deleteBlockedPenjualanReferences = [];

    public ?string $editBlockedMessage = null;

    public ?string $deleteBlockedMessage = null;

    protected function getHeaderActions(): array
    {
        $record = $this->record;

        return [
            Action::make('upload_dokumen')
                ->label('Upload Foto')
                ->icon('heroicon-m-camera')
                ->color('success')
                ->modalHeading('Upload Foto Dokumentasi')
                ->modalDescription('Upload foto nota, invoice, atau bukti penerimaan barang.')
                ->modalWidth('md')
                ->form([
                    \Filament\Forms\Components\FileUpload::make('foto')
                        ->label('Foto Dokumentasi')
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->imageResizeMode('contain')
                        ->imageResizeTargetWidth('1920')
                        ->imageResizeTargetHeight('1080')
                        ->disk('public')
                        ->directory('tukar-tambah/dokumentasi')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->saveUploadedFileUsing(function (\Filament\Forms\Components\BaseFileUpload $component, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile $file): ?string {
                            return \App\Support\WebpUpload::store($component, $file, 80);
                        }),
                ])
                ->action(function (array $data): void {
                    $existingPhotos = $this->record->foto_dokumen ?? [];
                    $newPhotos = $data['foto'] ?? [];

                    // Merge existing photos with new ones
                    $allPhotos = array_merge($existingPhotos, $newPhotos);

                    $this->record->update([
                        'foto_dokumen' => $allPhotos,
                    ]);

                    Notification::make()
                        ->title('Foto berhasil diupload')
                        ->success()
                        ->send();
                }),

            EditAction::make()
                ->visible(fn () => $record->canEditItems() || $record->canEditPayment()),

            ActionGroup::make([
                Action::make('invoice')
                    ->label('Invoice Detail')
                    ->icon('heroicon-m-printer')
                    ->color('primary')
                    ->url(fn () => route('tukar-tambah.invoice', $this->record))
                    ->openUrlInNewTab(),
                Action::make('invoice_simple')
                    ->label('Invoice Simple')
                    ->icon('heroicon-m-document-text')
                    ->color('warning')
                    ->url(fn () => route('tukar-tambah.invoice.simple', $this->record))
                    ->openUrlInNewTab(),
            ])
                ->label('Invoice')
                ->icon('heroicon-m-printer')
                ->color('primary')
                ->button(),

            Action::make('delete')
                ->label('Hapus')
                ->icon('heroicon-m-trash')
                ->color('danger')
                ->visible(fn() => auth()->user()?->hasRole(['godmode', 'super_admin']))
                ->requiresConfirmation()
                ->modalHeading(fn() => $this->record->canDelete() ? 'Konfirmasi Hapus Tukar Tambah' : 'Tidak Bisa Dihapus')
                ->modalIcon(fn() => $this->record->canDelete() ? 'heroicon-o-trash' : 'heroicon-o-exclamation-triangle')
                ->modalIconColor(fn() => $this->record->canDelete() ? 'danger' : 'warning')
                ->modalDescription(function (): string {
                    $record = $this->record;

                    if (! $record->canDelete()) {
                        $reasons = [];

                        if ($record->isFinal()) {
                            $reasons[] = '• Status sudah final, tidak bisa dihapus';
                        }
                        if ($record->is_locked) {
                            $reasons[] = '• Transaksi sudah terkunci';
                        }

                        $notas = $record->getExternalPenjualanReferences()->pluck('nota')->filter()->values();
                        if ($notas->isNotEmpty()) {
                            $reasons[] = '• Item pembelian dipakai di penjualan: ' . $notas->implode(', ');
                        }

                        return implode("\n", $reasons) ?: 'Tukar tambah ini tidak dapat dihapus.';
                    }

                    return 'Apakah Anda yakin ingin menghapus tukar tambah **' . $record->kode . '**? Langkah ini tidak dapat dibatalkan.';
                })
                ->modalSubmitAction(fn() => $this->record->canDelete() ? null : false)
                ->modalCancelAction(fn(StaticAction $action) => $this->record->canDelete()
                    ? $action->label('Batal')
                    : $action->label('Tutup'))
                ->extraModalFooterActions(function (): array {
                    if ($this->record->canDelete()) {
                        return [];
                    }

                    return $this->record->getExternalPenjualanReferences()
                        ->filter(fn(array $ref) => ! empty($ref['id']))
                        ->map(function (array $ref, int $index) {
                            $nota = $ref['nota'] ?? null;
                            $label = $nota ? 'Lihat ' . $nota : 'Lihat Penjualan';

                            return StaticAction::make('viewPenjualan' . $index)
                                ->button()
                                ->label($label)
                                ->icon('heroicon-m-arrow-top-right-on-square')
                                ->url(PenjualanResource::getUrl('view', ['record' => $ref['id']]))
                                ->openUrlInNewTab()
                                ->color('warning');
                        })
                        ->values()
                        ->all();
                })
                ->action(function (): void {
                    if (! $this->record->canDelete()) {
                        $this->replaceMountedAction('deleteBlocked');
                        return;
                    }

                    $this->replaceMountedAction('deleteStep2');
                }),
        ];
    }

    public function deleteStep2Action(): Action
    {
        return Action::make('deleteStep2')
            ->modalHeading('⚠️ Dampak Penghapusan')
            ->modalDescription(function (): string {
                $record = $this->record;
                $desc = "**Anda akan menghapus tukar tambah {$record->kode}.**\n\n";

                $penjualan = $record->penjualan;
                if ($penjualan) {
                    $desc .= "**Penjualan terkait:** {$penjualan->no_nota}\n";
                    $itemsCount = $penjualan->items()->count();
                    if ($itemsCount > 0) {
                        $desc .= "- {$itemsCount} item penjualan akan dihapus\n";
                    }
                    $jasaCount = $penjualan->jasaItems()->count();
                    if ($jasaCount > 0) {
                        $desc .= "- {$jasaCount} item jasa akan dihapus\n";
                    }
                    $paymentCount = $penjualan->pembayaran()->count();
                    if ($paymentCount > 0) {
                        $desc .= "- {$paymentCount} data pembayaran penjualan akan dihapus\n";
                    }
                    $desc .= "\n";
                }

                $pembelian = $record->pembelian;
                if ($pembelian) {
                    $desc .= "**Pembelian terkait:** {$pembelian->no_po}\n";
                    $itemsCount = $pembelian->items()->count();
                    if ($itemsCount > 0) {
                        $desc .= "- {$itemsCount} item pembelian akan dihapus\n";
                    }
                    $paymentCount = $pembelian->pembayaran()->count();
                    if ($paymentCount > 0) {
                        $desc .= "- {$paymentCount} data pembayaran pembelian akan dihapus\n";
                    }
                    $desc .= "\n";
                }

                $desc .= 'Apakah Anda yakin ingin melanjutkan?';

                return $desc;
            })
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('warning')
            ->modalWidth('md')
            ->modalSubmitActionLabel('Lanjutkan ke Konfirmasi Password')
            ->modalCancelAction(false)
            ->action(function (): void {
                $this->replaceMountedAction('deleteStep3');
            })
            ->color('warning');
    }

    public function deleteStep3Action(): Action
    {
        return Action::make('deleteStep3')
            ->modalHeading('🔐 Konfirmasi Password')
            ->modalDescription('Masukkan password Anda untuk mengkonfirmasi penghapusan. Tindakan ini tidak dapat dibatalkan.')
            ->modalIcon('heroicon-o-lock-closed')
            ->modalIconColor('danger')
            ->modalWidth('md')
            ->form([
                \Filament\Forms\Components\TextInput::make('password')
                    ->label('Password')
                    ->password()
                    ->required()
                    ->placeholder('Masukkan password akun Anda'),
            ])
            ->modalSubmitActionLabel('🔥 Hapus Tukar Tambah')
            ->modalCancelAction(false)
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! $user || ! Hash::check($data['password'], $user->password)) {
                    Notification::make()
                        ->title('Password salah')
                        ->body('Password yang Anda masukkan tidak sesuai.')
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $this->record->delete();
                } catch (ValidationException $exception) {
                    $messages = collect($exception->errors())
                        ->flatten()
                        ->implode(' ');

                    Notification::make()
                        ->title('Gagal menghapus')
                        ->body($messages ?: 'Terjadi kesalahan saat menghapus.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Tukar tambah berhasil dihapus')
                    ->success()
                    ->send();

                $this->redirect(TukarTambahResource::getUrl('index'));
            })
            ->color('danger');
    }

    protected function editBlockedAction(): Action
    {
        return Action::make('editBlocked')
            ->modalHeading('Tidak bisa edit')
            ->modalDescription(fn () => $this->editBlockedMessage ?? 'Tukar tambah tidak bisa diedit.')
            ->modalIcon('heroicon-o-lock-closed')
            ->modalIconColor('warning')
            ->modalWidth('md')
            ->modalAlignment(Alignment::Center)
            ->modalFooterActions(fn () => $this->buildPenjualanFooterActions($this->editBlockedPenjualanReferences))
            ->modalFooterActionsAlignment(Alignment::Center)
            ->modalSubmitAction(false)
            ->modalCancelAction(fn (StaticAction $action) => $action->label('Tutup'))
            ->color('danger');
    }

    protected function deleteBlockedAction(): Action
    {
        return Action::make('deleteBlocked')
            ->modalHeading('Gagal menghapus')
            ->modalDescription(fn () => $this->deleteBlockedMessage ?? 'Gagal menghapus tukar tambah.')
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('danger')
            ->modalWidth('md')
            ->modalAlignment(Alignment::Center)
            ->modalFooterActions(fn () => $this->buildPenjualanFooterActions($this->deleteBlockedPenjualanReferences))
            ->modalFooterActionsAlignment(Alignment::Center)
            ->modalSubmitAction(false)
            ->modalCancelAction(fn (StaticAction $action) => $action->label('Tutup'))
            ->color('danger');
    }

    protected function buildPenjualanFooterActions(array $references): array
    {
        return collect($references)
            ->filter(fn (array $reference) => ! empty($reference['id']))
            ->map(function (array $reference, int $index) {
                $nota = $reference['nota'] ?? null;
                $label = $nota ? 'Lihat '.$nota : 'Lihat Penjualan';

                return StaticAction::make('viewPenjualan'.$index)
                    ->button()
                    ->label($label)
                    ->url(PenjualanResource::getUrl('view', ['record' => $reference['id'] ?? 0]))
                    ->openUrlInNewTab()
                    ->color('danger');
            })
            ->values()
            ->all();
    }
}
