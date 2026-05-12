<?php

namespace App\Filament\Resources\TukarTambahResource\Pages;

use App\Filament\Resources\PenjualanResource;
use App\Filament\Resources\TukarTambahResource;
use App\Models\TukarTambah;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\StaticAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

class ListTukarTambahs extends ListRecords
{
    protected static string $resource = TukarTambahResource::class;

    public $deleteRecordId;

    public array $editBlockedPenjualanReferences = [];

    public array $deleteBlockedPenjualanReferences = [];

    public ?string $editBlockedMessage = null;

    public ?string $deleteBlockedMessage = null;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Semua')
                ->icon('heroicon-o-list-bullet')
                ->badge(TukarTambah::count())
                ->badgeColor('gray'),

            'draft' => Tab::make('Draft')
                ->icon('heroicon-o-pencil')
                ->badge(TukarTambah::where('status_dokumen', 'draft')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status_dokumen', 'draft')),

            'final' => Tab::make('Final')
                ->icon('heroicon-o-check-circle')
                ->badge(TukarTambah::where('status_dokumen', 'final')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status_dokumen', 'final')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tukar Tambah')
                ->icon('heroicon-o-plus')
                ->color('primary'),
        ];
    }

    public function deleteStep2Action(): Action
    {
        return Action::make('deleteStep2')
            ->modalHeading('⚠️ Dampak Penghapusan')
            ->modalDescription(function () {
                $record = TukarTambah::find($this->deleteRecordId);
                if (! $record) {
                    return 'Data tidak ditemukan.';
                }

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
            ->action(function () {
                $this->replaceMountedAction('deleteStep3');
            });
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
            ->action(function (array $data) {
                $record = TukarTambah::find($this->deleteRecordId);
                if (! $record) {
                    Notification::make()->title('Data tidak ditemukan')->danger()->send();

                    return;
                }

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
                    \App\Models\Penjualan::$allowTukarTambahDeletion = true;
                    \App\Models\Pembelian::$allowTukarTambahDeletion = true;
                    $record->delete();
                } catch (\Exception $e) {
                    Notification::make()
                        ->title('Gagal menghapus')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                } finally {
                    \App\Models\Penjualan::$allowTukarTambahDeletion = false;
                    \App\Models\Pembelian::$allowTukarTambahDeletion = false;
                }

                Notification::make()
                    ->title('Tukar tambah dihapus')
                    ->success()
                    ->send();
            });
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

    protected function bulkDeleteBlockedAction(): Action
    {
        return Action::make('bulkDeleteBlocked')
            ->modalHeading('Sebagian gagal dihapus')
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
