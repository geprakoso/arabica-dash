<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE stock_mutations MODIFY COLUMN type ENUM('purchase','sale','sale_tt','opname','adjustment','rma_return','initial_sync','sale_return') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE stock_mutations MODIFY COLUMN type ENUM('purchase','sale','opname','adjustment','rma_return','initial_sync','sale_return') NOT NULL");
    }
};
