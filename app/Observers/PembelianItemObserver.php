<?php

namespace App\Observers;

use App\Jobs\SyncStockToWooCommerce;
use App\Models\PembelianItem;
use App\Models\StockBatch;
use Illuminate\Support\Facades\Log;

class PembelianItemObserver
{
    public function created(PembelianItem $pembelianItem): void
    {
        $this->dispatchSyncIfNeeded($pembelianItem, 'created');
    }

    public function updated(PembelianItem $pembelianItem): void
    {
        if (! $pembelianItem->wasChanged('qty_sisa')) {
            return;
        }

        // ✅ Sync qty_sisa ke StockBatch agar tetap konsisten
        // Jika StockBatch belum ada (misal dari data lama), buat baru
        $batch = $pembelianItem->stockBatch;
        if ($batch) {
            $batch->update(['qty_available' => $pembelianItem->qty_sisa]);
        } elseif ($pembelianItem->qty_sisa > 0) {
            $qtySisa = (int) $pembelianItem->qty_sisa;
            $batch = \App\Models\StockBatch::create([
                'pembelian_item_id' => $pembelianItem->id_pembelian_item,
                'produk_id' => $pembelianItem->id_produk ?? $pembelianItem->produk_id ?? $pembelianItem->id_barang,
                'qty_total' => $pembelianItem->qty ?? $qtySisa,
                'qty_available' => $qtySisa,
            ]);

            \App\Models\StockMutation::create([
                'stock_batch_id' => $batch->id,
                'type' => 'initial_sync',
                'qty_change' => $qtySisa,
                'qty_before' => 0,
                'qty_after' => $qtySisa,
                'reference_type' => 'PembelianItem',
                'reference_id' => $pembelianItem->id_pembelian_item,
                'notes' => 'Auto-created missing batch via Observer',
            ]);

            Log::info('PembelianItemObserver: Created missing StockBatch', [
                'pembelian_item_id' => $pembelianItem->id_pembelian_item,
                'stock_batch_id' => $batch->id,
                'qty_available' => $qtySisa,
            ]);
        }

        $this->dispatchSyncIfNeeded($pembelianItem, 'updated');
    }

    protected function dispatchSyncIfNeeded(PembelianItem $pembelianItem, string $event): void
    {
        $produk = $pembelianItem->produk;

        if (! $produk || blank($produk->sku)) {
            Log::debug('PembelianItemObserver: Skipping sync - no valid produk or SKU', [
                'pembelian_item_id' => $pembelianItem->getKey(),
                'event' => $event,
            ]);

            return;
        }

        SyncStockToWooCommerce::dispatch($produk->id);

        Log::info('PembelianItemObserver: SyncStockToWooCommerce dispatched', [
            'pembelian_item_id' => $pembelianItem->getKey(),
            'produk_id' => $produk->id,
            'sku' => $produk->sku,
            'qty_sisa' => $pembelianItem->qty_sisa,
            'event' => $event,
        ]);
    }
}
