<?php

namespace App\Models;

use App\Support\CacheHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class Pembelian extends Model
{
    use HasFactory;

    protected $table = 'tb_pembelian';

    protected $primaryKey = 'id_pembelian';

    // Flag to allow TukarTambah cascade deletion
    public static bool $allowTukarTambahDeletion = false;

    protected $fillable = [
        'no_po',
        'nota_supplier',
        'tanggal',
        'harga_jual',
        'catatan',
        'tipe_pembelian',
        'jenis_pembayaran',
        'tgl_tempo',
        'id_karyawan',
        'id_supplier',
        'foto_dokumen',
        'grand_total',
        'total_paid',
        'status_dokumen',
        'is_locked',
        'void_used',
        'posted_at',
        'posted_by_id',
        'voided_at',
        'voided_by_id',
        'no_tt',
    ];

    protected static function booted(): void
    {
        static::creating(function (Pembelian $pembelian): void {
            if (blank($pembelian->no_po)) {
                $pembelian->no_po = self::generateUniquePO();
            }

            if (blank($pembelian->status_dokumen)) {
                $pembelian->status_dokumen = 'draft';
            }
        });

        static::deleting(function (Pembelian $pembelian): void {
            // Clear cache saat dihapus
            $pembelian->clearCalculationCache();

            // Allow deletion if triggered by TukarTambah cascade
            if (! self::$allowTukarTambahDeletion) {
                // Check if this pembelian belongs to a Tukar Tambah
                if ($pembelian->tukarTambah()->exists()) {
                    $ttKode = $pembelian->tukarTambah?->kode ?? 'TT-XXXXX';

                    throw ValidationException::withMessages([
                        'id_pembelian' => "Tidak bisa hapus: Pembelian ini bagian dari Tukar Tambah ({$ttKode}). Hapus dari Tukar Tambah.",
                    ]);
                }
            }

            $externalPenjualanNotas = $pembelian->items()
                ->whereHas('penjualanItems')
                ->with(['penjualanItems.penjualan'])
                ->get()
                ->flatMap(fn ($item) => $item->penjualanItems)
                ->map(fn ($item) => $item->penjualan?->no_nota)
                ->filter()
                ->unique()
                ->values();

            if ($externalPenjualanNotas->isNotEmpty()) {
                $notaList = $externalPenjualanNotas->implode(', ');

                throw ValidationException::withMessages([
                    'id_pembelian' => 'Tidak bisa hapus: item pembelian dipakai transaksi lain. Nota: '.$notaList.'.',
                ]);
            }

            // Delete related items
            $pembelian->items()->get()->each->delete();
            $pembelian->jasaItems()->get()->each->delete();
            $pembelian->pembayaran()->get()->each->delete();
        });

        static::updated(function (Pembelian $pembelian) {
            $pembelian->clearCalculationCache();
        });
    }

    /**
     * Calculate total pembelian with caching
     */
    public function calculateTotalPembelianCached(): float
    {
        return CacheHelper::calculation(
            CacheHelper::TAG_PEMBELIAN,
            $this->id_pembelian,
            function () {
                $itemsTotal = $this->items->sum(fn ($item) => ($item->qty ?? 0) * ($item->hpp ?? 0));
                $jasaTotal = $this->jasaItems->sum(fn ($item) => ($item->qty ?? 0) * ($item->harga ?? 0));

                return $itemsTotal + $jasaTotal;
            }
        );
    }

    /**
     * Calculate total paid with caching
     */
    public function calculateTotalPaidCached(): float
    {
        return CacheHelper::calculation(
            CacheHelper::TAG_PEMBELIAN.':paid',
            $this->id_pembelian,
            function () {
                return (float) $this->pembayaran->sum('jumlah');
            }
        );
    }

    /**
     * Clear calculation cache for this record
     */
    public function clearCalculationCache(): void
    {
        CacheHelper::flush([CacheHelper::TAG_PEMBELIAN, CacheHelper::TAG_PEMBELIAN . ':paid']);
    }

    public static function generatePO(): string
    {
        $date = now()->format('Ym');
        $prefix = 'PO-'.$date.'-';

        $latest = self::where('no_po', 'like', $prefix.'%')
            ->orderBy('no_po', 'desc')
            ->first();

        $next = 1;
        if ($latest && preg_match('/'.preg_quote($prefix, '/').'(\d+)$/', $latest->no_po, $m)) {
            $next = (int) $m[1] + 1;
        }

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    public static function generateUniquePO(int $maxAttempts = 10): string
    {
        $date = now()->format('Ym');
        $prefix = 'PO-'.$date.'-';

        // Query all records for unique constraint check
        $latest = self::where('no_po', 'like', $prefix.'%')
            ->orderByRaw('CAST(SUBSTRING(no_po, '.(strlen($prefix) + 1).') AS UNSIGNED) DESC')
            ->first();

        $next = 1;
        if ($latest && preg_match('/'.preg_quote($prefix, '/').'(\d+)$/', $latest->no_po, $m)) {
            $next = (int) $m[1] + 1;
        }

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $poNumber = $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);

            // Check against ALL records due to unique constraint
            $exists = self::where('no_po', $poNumber)->exists();
            if (! $exists) {
                return $poNumber;
            }

            $next++;
        }

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    protected $casts = [
        'tanggal' => 'date',
        'tgl_tempo' => 'date',
        'harga_jual' => 'decimal:2',
        'foto_dokumen' => 'array',
        'grand_total' => 'decimal:2',
        'total_paid' => 'decimal:2',
        'is_locked' => 'boolean',
        'void_used' => 'boolean',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    /**
     * R06-R07: Status pembayaran (calculated, tidak disimpan di DB)
     * TEMPO = pembayaran < grand_total
     * LUNAS = pembayaran >= grand_total
     */
    public function getStatusAttribute(): string
    {
        $paid = $this->total_paid > 0 ? (float) $this->total_paid : $this->calculateTotalPaidCached();
        $total = $this->grand_total > 0 ? (float) $this->grand_total : $this->calculateTotalPembelianCached();

        return $paid >= $total ? 'LUNAS' : 'TEMPO';
    }

    /**
     * R08: Kelebihan pembayaran (calculated)
     */
    public function getKelebihanAttribute(): float
    {
        $paid = $this->total_paid > 0 ? (float) $this->total_paid : $this->calculateTotalPaidCached();
        $total = $this->grand_total > 0 ? (float) $this->grand_total : $this->calculateTotalPembelianCached();

        return max(0, (float) $paid - (float) $total);
    }

    // ============================================================
    // STATE MACHINE
    // draft → final → locked
    // final → draft (void, 1x only)
    // Item TIDAK bisa diedit, hanya pembayaran yang bisa diedit
    // ============================================================

    public function isDraft(): bool
    {
        return $this->status_dokumen === 'draft';
    }

    public function isFinal(): bool
    {
        return $this->status_dokumen === 'final';
    }

    public function isLocked(): bool
    {
        return $this->status_dokumen === 'locked';
    }

    public function isVoided(): bool
    {
        return $this->status_dokumen === 'voided';
    }

    public function canEditItems(): bool
    {
        return false;
    }

    public function canEditPayment(): bool
    {
        return $this->isDraft() && ! $this->is_locked;
    }

    public function canPost(): bool
    {
        return $this->isDraft() && ! $this->is_locked;
    }

    public function canVoid(): bool
    {
        return ($this->isFinal() || $this->isLocked())
            && ! $this->void_used;
    }

    public function canLock(): bool
    {
        return $this->isFinal() && ! $this->is_locked;
    }

    public function canEdit(): bool
    {
        return $this->canEditPayment();
    }

    public function post(): void
    {
        if (! $this->canPost()) {
            throw new \RuntimeException('Pembelian tidak bisa di-post.');
        }

        if (is_null($this->grand_total) || $this->grand_total <= 0) {
            $this->grand_total = $this->calculateTotalPembelianCached();
        }

        $this->update([
            'status_dokumen' => 'final',
            'posted_at' => now(),
            'posted_by_id' => auth()->id(),
        ]);
    }

    public function voidToDraft(): void
    {
        if (! $this->canVoid()) {
            throw new \RuntimeException('Pembelian tidak bisa di-void.');
        }

        $this->update([
            'status_dokumen' => 'draft',
            'is_locked' => false,
            'void_used' => true,
            'voided_at' => now(),
            'voided_by_id' => auth()->id(),
        ]);
    }

    public function lockFinal(): void
    {
        if (! $this->canLock()) {
            throw new \RuntimeException('Pembelian tidak bisa di-lock.');
        }

        $this->update([
            'status_dokumen' => 'locked',
            'is_locked' => true,
        ]);
    }

    /**
     * R16: Cek apakah bisa dihapus (lock tidak mempengaruhi)
     * Hanya R12 & R13 yang menentukan
     */
    public function canDelete(): bool
    {
        // R12: Cek apakah sudah ada penjualan
        $hasSales = $this->items()
            ->whereHas('penjualanItems')
            ->exists();

        if ($hasSales) {
            return false;
        }

        // R13: Cek apakah ada NO TT
        if (! empty($this->no_tt)) {
            return false;
        }

        return true;
    }

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class, 'id_karyawan');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'id_supplier');
    }

    public function requestOrders()
    {
        return $this->belongsToMany(RequestOrder::class, 'pembelian_request_order', 'pembelian_id', 'request_order_id')
            ->withTimestamps();
    }

    public function items()
    {
        return $this->hasMany(PembelianItem::class, 'id_pembelian', 'id_pembelian');
    }

    public function pembayaran()
    {
        return $this->hasMany(PembelianPembayaran::class, 'id_pembelian', 'id_pembelian');
    }

    public function jasaItems()
    {
        return $this->hasMany(PembelianJasa::class, 'id_pembelian', 'id_pembelian');
    }

    public function isEditLocked(): bool
    {
        $itemTable = (new PembelianItem)->getTable();
        $qtyMasukColumn = PembelianItem::qtyMasukColumn();
        $qtySisaColumn = PembelianItem::qtySisaColumn();

        return $this->items()
            ->where(function ($query) use ($itemTable, $qtyMasukColumn, $qtySisaColumn) {
                $query->whereColumn($itemTable.'.'.$qtySisaColumn, '<', $itemTable.'.'.$qtyMasukColumn)
                    ->orWhereHas('penjualanItems');
            })
            ->exists();
    }

    public function getEditBlockedMessage(): string
    {
        $notaList = $this->getBlockedPenjualanReferences()
            ->pluck('nota')
            ->filter()
            ->values();

        $suffix = $notaList->isNotEmpty()
            ? ' Nota: '.$notaList->implode(', ').'.'
            : '';

        return 'Pembelian tidak bisa diedit karena item sudah dipakai transaksi lain.'.$suffix;
    }

    public function getBlockedPenjualanReferences(): Collection
    {
        return $this->items()
            ->whereHas('penjualanItems')
            ->with(['penjualanItems.penjualan:id_penjualan,no_nota'])
            ->get()
            ->flatMap(fn ($item) => $item->penjualanItems)
            ->map(function ($item) {
                if (! $item->penjualan) {
                    return null;
                }

                return [
                    'id' => (int) $item->penjualan->getKey(),
                    'nota' => $item->penjualan->no_nota,
                ];
            })
            ->filter()
            ->unique('id')
            ->values();
    }

    public function tukarTambah()
    {
        return $this->hasOne(TukarTambah::class, 'pembelian_id', 'id_pembelian');
    }

    protected ?float $cachedTotalPembelian = null;

    public function calculateTotalPembelian(): float
    {
        // Return cached value if available
        if ($this->cachedTotalPembelian !== null) {
            return $this->cachedTotalPembelian;
        }

        // Use loaded relations if available (avoids N+1)
        if ($this->relationLoaded('items') && $this->relationLoaded('jasaItems')) {
            $itemsTotal = (float) $this->items->sum(fn ($item) => ($item->qty ?? 0) * ($item->hpp ?? 0));
            $jasaTotal = (float) $this->jasaItems->sum(fn ($item) => ($item->qty ?? 0) * ($item->harga ?? 0));
        } else {
            // Fallback to database queries
            $itemsTotal = (float) ($this->items()
                ->selectRaw('COALESCE(SUM(qty * hpp), 0) as total')
                ->value('total') ?? 0);
            $jasaTotal = (float) ($this->jasaItems()
                ->selectRaw('COALESCE(SUM(qty * harga), 0) as total')
                ->value('total') ?? 0);
        }

        return $this->cachedTotalPembelian = $itemsTotal + $jasaTotal;
    }

    public function recalculatePaymentStatus(): void
    {
        $total = $this->calculateTotalPembelian();
        $totalPaid = (float) ($this->pembayaran()->sum('jumlah') ?? 0);
        $status = $total <= 0 || $totalPaid >= $total ? 'lunas' : 'tempo';

        $this->forceFill([
            'jenis_pembayaran' => $status,
            'grand_total' => $total,
            'total_paid' => $totalPaid,
        ])->saveQuietly();
    }

    }
