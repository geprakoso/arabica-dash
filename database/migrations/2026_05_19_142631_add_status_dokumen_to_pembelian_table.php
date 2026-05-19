<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_pembelian', function (Blueprint $table): void {
            $table->enum('status_dokumen', ['draft', 'final', 'locked', 'voided'])
                ->default('draft')
                ->after('foto_dokumen');
            $table->boolean('void_used')->default(false)->after('is_locked');
            $table->timestamp('posted_at')->nullable()->after('void_used');
            $table->foreignId('posted_by_id')->nullable()->constrained('users')->nullOnDelete()->after('posted_at');
            $table->timestamp('voided_at')->nullable()->after('posted_by_id');
            $table->foreignId('voided_by_id')->nullable()->constrained('users')->nullOnDelete()->after('voided_at');
        });

        DB::table('tb_pembelian')->where('is_locked', true)->update([
            'status_dokumen' => 'locked',
        ]);

        DB::table('tb_pembelian')->where('is_locked', false)->update([
            'status_dokumen' => 'draft',
        ]);
    }

    public function down(): void
    {
        Schema::table('tb_pembelian', function (Blueprint $table): void {
            $table->dropForeign(['posted_by_id']);
            $table->dropForeign(['voided_by_id']);
            $table->dropColumn([
                'status_dokumen',
                'void_used',
                'posted_at',
                'posted_by_id',
                'voided_at',
                'voided_by_id',
            ]);
        });
    }
};
