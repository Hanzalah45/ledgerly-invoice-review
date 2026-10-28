<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Bootstrap Icon name + tone for the activity timeline. */
    public function icon(): array
    {
        return match ($this->type) {
            'uploaded' => ['bi-cloud-arrow-up', 'info'],
            'processing_started' => ['bi-gear', 'info'],
            'extracted' => ['bi-stars', 'accent'],
            'validated' => ['bi-shield-check', 'accent'],
            'edited' => ['bi-pencil-square', 'warn'],
            'approved' => ['bi-check-circle', 'ok'],
            'rejected' => ['bi-x-circle', 'bad'],
            'failed' => ['bi-exclamation-triangle', 'bad'],
            'reprocessed' => ['bi-arrow-repeat', 'info'],
            'exported' => ['bi-download', 'muted'],
            'deleted' => ['bi-trash', 'bad'],
            default => ['bi-dot', 'muted'],
        };
    }
}
