<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ttPembelianIds = DB::table('tb_tukar_tambah')
            ->whereNotNull('pembelian_id')
            ->pluck('pembelian_id')
            ->toArray();

        DB::table('tb_pembelian')
            ->where('status_dokumen', 'final')
            ->where('is_locked', true)
            ->whereNull('no_tt')
            ->whereNotIn('id_pembelian', $ttPembelianIds)
            ->update(['is_locked' => false]);
    }

    public function down(): void
    {
        // no-op
    }
};
