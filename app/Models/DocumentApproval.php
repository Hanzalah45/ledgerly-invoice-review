<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentApproval extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['acted_at' => 'datetime'];
    }

    public const LABELS = ['reviewer' => 'Reviewer', 'department_manager' => 'Department manager', 'admin' => 'Admin'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    public function label(): string
    {
        return self::LABELS[$this->step_type] ?? ucfirst($this->step_type);
    }
}
