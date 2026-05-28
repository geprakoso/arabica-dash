<?php

namespace App\Filament\Resources\PenjualanResource\Pages;

use App\Filament\Resources\PenjualanResource;
use App\Models\Penjualan;
use App\Models\PenjualanItem;
use App\Services\ValidationLogger;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditPenjualan extends EditRecord
{
    protected static string $resource = PenjualanResource::class;

    protected array $itemsToCreate = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Transform existing items to items_temp format for the form
        $data['items_temp'] = collect($this->record->items)
            ->map(fn ($item) => [
                'id_penjualan_item' => $item->id_penjualan_item,
                'id_produk' => $item->id_produk,
                'id_pembelian_item' => $item->id_pembelian_item,
                'kondisi' => $item->kondisi,
                'qty' => $item->qty,
                'hpp' => $item->hpp,
                'harga_jual' => $item->harga_jual,
                'serials' => $item->serials ?? [],
            ])
            ->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Extract items_temp for processing
        $rawItems = $this->form->getRawState()['items_temp'] ?? null;
        if (is_array($rawItems)) {
            $this->itemsToCreate = $rawItems;
        } elseif (isset($data['items_temp']) && is_array($data['items_temp'])) {
            $this->itemsToCreate = $data['items_temp'];
        }

        unset($data['items_temp']);

        // Validasi: minimal harus ada 1 item produk atau 1 jasa
        $hasItems = ! empty($this->itemsToCreate);
        $hasJasa = ! empty($data['jasaItems'] ?? []);

        // Jika items tidak bisa diedit, hitung existing items
        if (! $hasItems && $this->record && ! $this->record->canEditItems()) {
            $hasItems = $this->record->items()->exists();
        }

        // Jika jasa tidak ada di form, hitung existing jasa
        if (! $hasJasa && $this->record) {
            $hasJasa = $this->record->jasaItems()->exists();
        }

        if (! $hasItems && ! $hasJasa) {
            throw ValidationException::withMessages([
                'items_temp' => 'Minimal harus ada 1 item produk atau 1 jasa.',
            ]);
        }

        // Status dokumen tetap (tidak diubah dari form)
        return $data;
    }

    /**
     * Validasi duplikat dan format item (tanpa cek stok total).
     * Stok divalidasi per item oleh observer PenjualanItem.
     */
    protected function validateDuplicatesAndFormat(array $items): void
    {
        ValidationLogger::startBatch();

        if (empty($items)) {
            return;
        }

        $productKeys = [];
        $rowNumber = 0;
        foreach ($items as $item) {
            $rowNumber++;
            $productId = (int) ($item['id_produk'] ?? 0);
            $condition = $item['kondisi'] ?? null;
            $batchId = (int) ($item['id_pembelian_item'] ?? 0);

            if ($productId < 1) {
                ValidationLogger::logRequired(
                    sourceType: 'Penjualan',
                    sourceAction: 'update',
                    fieldName: 'id_produk',
                    fieldLabel: 'Produk',
                    inputData: ['row' => $rowNumber]
                );

                throw ValidationException::withMessages([
                    'items_temp' => 'Produk pada baris '.$rowNumber.' harus dipilih.',
                ]);
            }

            $qty = (int) ($item['qty'] ?? 0);
            if ($qty < 1) {
                $productName = \App\Models\Produk::find($productId)?->nama_produk ?? 'Produk #'.$productId;

                ValidationLogger::logFormat(
                    sourceType: 'Penjualan',
                    sourceAction: 'update',
                    fieldName: 'qty',
                    message: "Qty untuk {$productName} minimal 1",
                    inputData: [
                        'product_id' => $productId,
                        'product_name' => $productName,
                        'qty' => $qty,
                        'row' => $rowNumber,
                    ]
                );

                throw ValidationException::withMessages([
                    'items_temp' => "Qty untuk {$productName} minimal 1.",
                ]);
            }

            // Cek duplikat produk - tidak boleh ada produk+batch+kondisi yang sama persis di baris berbeda
            $key = $productId.'|'.($condition ?? '').'|'.$batchId;
            if (isset($productKeys[$key])) {
                $productName = \App\Models\Produk::find($productId)?->nama_produk ?? 'Produk #'.$productId;
                $batchInfo = $batchId > 0 ? ' (batch sama)' : '';
                $conditionInfo = $condition ? " (kondisi: {$condition})" : '';
                $errorMessage = "Produk '{$productName}'{$conditionInfo}{$batchInfo} sudah ada di baris {$productKeys[$key]}. Hapus duplikat di baris {$rowNumber}. Jika stok tidak cukup, cukup tambahkan qty di baris yang sudah ada.";

                ValidationLogger::logDuplicate(
                    sourceType: 'Penjualan',
                    sourceAction: 'update',
                    productName: $productName,
                    row: $rowNumber,
                    inputData: [
                        'product_id' => $productId,
                        'batch_id' => $batchId,
                        'condition' => $condition,
                        'duplicate_row' => $productKeys[$key],
                        'current_row' => $rowNumber,
                    ]
                );

                Notification::make()
                    ->title('Validasi Gagal - Duplikat Produk')
                    ->body($errorMessage)
                    ->icon('heroicon-o-exclamation-triangle')
                    ->danger()
                    ->persistent()
                    ->send();

                $user = Auth::user();
                if ($user) {
                    Notification::make()
                        ->title('Validasi Gagal - Duplikat Produk')
                        ->body($errorMessage)
                        ->icon('heroicon-o-exclamation-triangle')
                        ->danger()
                        ->sendToDatabase($user);
                }

                throw ValidationException::withMessages([
                    'items_temp' => $errorMessage,
                ]);
            }
            $productKeys[$key] = $rowNumber;
        }
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            /** @var Penjualan $record */
            // Update penjualan record
            $record->update([
                'id_karyawan' => $data['id_karyawan'] ?? $record->id_karyawan,
                'id_member' => $data['id_member'] ?? $record->id_member,
                'tanggal_penjualan' => $data['tanggal_penjualan'] ?? $record->tanggal_penjualan,
                'catatan' => $data['catatan'] ?? $record->catatan,
                'diskon_total' => $data['diskon_total'] ?? $record->diskon_total ?? 0,
            ]);

            // Edit items jika draft dan belum di-lock
            if ($record->canEditItems()) {
                $incomingItems = $this->itemsToCreate ?? [];

                // Validasi duplikat & format (stok divalidasi per item oleh observer)
                $this->validateDuplicatesAndFormat($incomingItems);

                $existingItems = $record->items()->get()->keyBy('id_penjualan_item');

                $incomingIds = collect($incomingItems)
                    ->pluck('id_penjualan_item')
                    ->filter()
                    ->values()
                    ->all();

                // 1. Hapus item yang tidak ada di form (user menghapus row)
                $toDelete = $existingItems->keys()->diff($incomingIds);
                foreach ($toDelete as $deleteId) {
                    $existingItems[$deleteId]->delete(); // stok kembali via observer
                }

                // 2. Update item yang sudah ada, create item baru
                foreach ($incomingItems as $itemData) {
                    $itemId = $itemData['id_penjualan_item'] ?? null;

                    if ($itemId && $existingItems->has($itemId)) {
                        $existingItem = $existingItems[$itemId];
                        $existingItem->update([
                            'id_produk' => $itemData['id_produk'],
                            'id_pembelian_item' => $itemData['id_pembelian_item'],
                            'qty' => $itemData['qty'],
                            'harga_jual' => $itemData['harga_jual'],
                            'kondisi' => $itemData['kondisi'] ?? null,
                            'serials' => $itemData['serials'] ?? null,
                        ]);
                        // Stok adjustment otomatis via observer PenjualanItem::updated
                    } else {
                        PenjualanItem::create([
                            'id_penjualan' => $record->id_penjualan,
                            'id_produk' => $itemData['id_produk'],
                            'id_pembelian_item' => $itemData['id_pembelian_item'],
                            'qty' => $itemData['qty'],
                            'harga_jual' => $itemData['harga_jual'],
                            'kondisi' => $itemData['kondisi'] ?? null,
                            'serials' => $itemData['serials'] ?? null,
                        ]);
                        // Stok deduction otomatis via observer PenjualanItem::created
                    }
                }
            }

            // Recalculate totals
            $record->recalculateTotals();
            $record->recalculatePaymentStatus();

            return $record;
        });
    }

    public function getRelationManagers(): array
    {
        return [];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Penjualan berhasil diupdate.';
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Data berhasil disimpan')
            ->body('Perubahan penjualan sudah tersimpan.');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->label('Simpan')
                ->icon('heroicon-m-check-circle')
                ->formId('form'),
            $this->getCancelFormAction()
                ->label('Batal')
                ->icon('heroicon-m-x-mark')
                ->color('danger')
                ->formId('form'),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
