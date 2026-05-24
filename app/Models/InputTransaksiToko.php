<?php

namespace App\Models;

use App\Enums\KategoriAkun;
use App\Models\AkunTransaksi;
use App\Models\JenisAkun;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class InputTransaksiToko extends Model
{
    protected $table = 'ak_input_transaksi_tokos';

    protected $fillable = [
        'tanggal_transaksi',
        'kode_jenis_akun_id',
        'kategori_transaksi',
        'nominal_transaksi',
        'keterangan_transaksi',
        'bukti_transaksi',
        'user_id',
        'akun_transaksi_id',
    ];

    protected $casts = [
        'tanggal_transaksi' => 'date',
        'kategori_transaksi' => KategoriAkun::class,
        'nominal_transaksi' => 'decimal:2',
        'bukti_transaksi' => 'array',
    ];

    public function jenisAkun(): BelongsTo
    {
        return $this->belongsTo(JenisAkun::class, 'kode_jenis_akun_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function akunTransaksi(): BelongsTo
    {
        return $this->belongsTo(AkunTransaksi::class);
    }

    public function buktiTransaksiGallery(): array
    {
        $paths = $this->bukti_transaksi ?? [];

        if (empty($paths)) {
            return [];
        }

        $disk = Storage::disk('public');

        return collect($paths)
            ->filter(fn ($path) => filled($path))
            ->map(fn ($path) => [
                'url' => $disk->url($path),
                'name' => pathinfo($path, PATHINFO_FILENAME),
                'mime_type' => $disk->exists($path) ? $disk->mimeType($path) : null,
                'file_name' => basename($path),
            ])
            ->values()
            ->all();
    }
}
