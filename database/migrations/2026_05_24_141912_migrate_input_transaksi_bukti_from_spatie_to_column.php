<?php

use App\Models\InputTransaksiToko;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ak_input_transaksi_tokos', function (Blueprint $table) {
            $table->text('bukti_transaksi')->nullable()->change();
        });

        $disk = Storage::disk('public');

        $mediaRecords = DB::table('media')
            ->where('model_type', InputTransaksiToko::class)
            ->where('collection_name', 'bukti_transaksi')
            ->orderBy('order_column')
            ->get()
            ->groupBy('model_id');

        foreach ($mediaRecords as $modelId => $medias) {
            $paths = [];

            foreach ($medias as $media) {
                $relativePath = $media->id . '/' . $media->file_name;

                if ($disk->exists($relativePath)) {
                    $paths[] = $relativePath;
                }
            }

            if (! empty($paths)) {
                DB::table('ak_input_transaksi_tokos')
                    ->where('id', $modelId)
                    ->update(['bukti_transaksi' => json_encode($paths)]);
            }
        }

        $recordsWithLegacyPath = DB::table('ak_input_transaksi_tokos')
            ->whereNotNull('bukti_transaksi')
            ->where('bukti_transaksi', 'not like', '%[%')
            ->get();

        foreach ($recordsWithLegacyPath as $record) {
            $legacyPath = $record->bukti_transaksi;

            if ($disk->exists($legacyPath)) {
                DB::table('ak_input_transaksi_tokos')
                    ->where('id', $record->id)
                    ->update(['bukti_transaksi' => json_encode([$legacyPath])]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('ak_input_transaksi_tokos', function (Blueprint $table) {
            $table->string('bukti_transaksi', 255)->nullable()->change();
        });

        DB::table('ak_input_transaksi_tokos')
            ->whereNotNull('bukti_transaksi')
            ->update(['bukti_transaksi' => null]);
    }
};