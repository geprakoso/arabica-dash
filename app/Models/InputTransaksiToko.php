<?php

namespace App\Models;

use App\Enums\KategoriAkun;
use App\Models\AkunTransaksi;
use App\Models\JenisAkun;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class InputTransaksiToko extends Model implements HasMedia
{
    use InteractsWithMedia;

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

    /**
     * Gallery bukti transaksi (media library + fallback legacy path).
     *
     * @return array<int, array{url: string, name: string}>
     */
    public function buktiTransaksiGallery(): array
    {
        $items = $this->getMedia('bukti_transaksi')
            ->map(fn ($media) => [
                'url' => $media->getUrl(),
                'name' => $media->name ?? $media->file_name ?? 'Bukti Transaksi',
                'mime_type' => $media->mime_type,
                'file_name' => $media->file_name,
            ])
            ->values()
            ->all();

        if (empty($items) && filled($this->bukti_transaksi)) {
            $disk = Storage::disk('public');

            $items[] = [
                'url' => $disk->url($this->bukti_transaksi),
                'name' => basename($this->bukti_transaksi),
                'mime_type' => $disk->exists($this->bukti_transaksi) ? $disk->mimeType($this->bukti_transaksi) : null,
                'file_name' => basename($this->bukti_transaksi),
            ];
        }

        return $items;
    }
}
