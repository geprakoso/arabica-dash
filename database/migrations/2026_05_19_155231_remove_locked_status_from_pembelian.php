<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tb_pembelian')
            ->where('status_dokumen', 'locked')
            ->update(['status_dokumen' => 'final']);

        DB::statement("ALTER TABLE tb_pembelian MODIFY COLUMN status_dokumen ENUM('draft', 'final', 'voided') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tb_pembelian MODIFY COLUMN status_dokumen ENUM('draft', 'final', 'locked', 'voided') NOT NULL DEFAULT 'draft'");
    }
};
