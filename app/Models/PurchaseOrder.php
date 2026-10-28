<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'issued_on' => 'date'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Case- and punctuation-insensitive lookup so "po-1042" matches "PO 1042". */
    public static function findByNumber(string $number): ?self
    {
        $key = self::normalise($number);

        return static::query()->get()->first(fn (self $po) => self::normalise($po->po_number) === $key);
    }

    public static function normalise(string $number): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $number) ?? '');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        return $query->where(fn (Builder $q) => $q->where('po_number', 'like', $like)->orWhere('supplier_name', 'like', $like));
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
