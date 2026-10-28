<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Document extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'is_demo' => 'boolean',
            'human_edited' => 'boolean',
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'discount' => 'decimal:2',
            'shipping' => 'decimal:2',
            'confidence' => 'array',
            'validation_issues' => 'array',
            'raw_response' => 'array',
            'processed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Document $document) {
            $document->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(DocumentComment::class)->latest()->latest('id');
    }

    /** The purchase order this invoice references, if one is on file. */
    public function purchaseOrder(): ?PurchaseOrder
    {
        return filled($this->po_number) ? PurchaseOrder::findByNumber($this->po_number) : null;
    }

    /** Account number with all but the last four characters hidden (used in lists and exports). */
    public function maskedBankAccount(): ?string
    {
        $value = preg_replace('/\s+/', '', (string) $this->bank_account);
        if ($value === '') {
            return null;
        }

        return str_repeat('•', max(0, strlen($value) - 4)).substr($value, -4);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(DocumentApproval::class)->orderBy('step_order');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(DocumentLineItem::class)->orderBy('position');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class)->latest('created_at')->latest('id');
    }

    /** Appends an entry to the document's activity timeline. */
    public function record(string $type, string $message, array $meta = [], ?User $user = null): DocumentEvent
    {
        return $this->events()->create([
            'user_id' => $user?->getKey(),
            'type' => $type,
            'message' => $message,
            'meta' => $meta ?: null,
        ]);
    }

    /* ----------------------------- Scopes ----------------------------- */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('supplier_name', 'like', $like)
                ->orWhere('invoice_number', 'like', $like)
                ->orWhere('po_number', 'like', $like)
                ->orWhere('bill_to_name', 'like', $like)
                ->orWhere('original_name', 'like', $like);
        });
    }

    public function scopeWithErrors(Builder $query): Builder
    {
        return $query->where('error_count', '>', 0);
    }

    /* ---------------------------- Accessors --------------------------- */

    /** @return array<int, array{field:?string,severity:string,code:string,message:string}> */
    public function issues(): array
    {
        return $this->validation_issues ?? [];
    }

    public function errorCount(): int
    {
        return (int) $this->error_count;
    }

    public function warningCount(): int
    {
        return (int) $this->warning_count;
    }

    public function fieldConfidence(string $field): ?float
    {
        $value = ($this->confidence ?? [])[$field] ?? null;

        return $value === null ? null : (float) $value;
    }

    /** Mean of the header-field confidences, or null when nothing was scored. */
    public function averageConfidence(): ?float
    {
        $values = collect($this->confidence ?? [])->filter(fn ($v) => is_numeric($v));

        return $values->isEmpty() ? null : round((float) $values->avg(), 2);
    }

    public function sourceLabel(): string
    {
        return match (true) {
            $this->provider === null => 'Not processed',
            $this->is_demo => 'Demo parser (rules, not AI)',
            default => 'Live AI · '.($this->model ?: $this->provider),
        };
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size;
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }

        return max(1, (int) round($bytes / 1024)).' KB';
    }
}
