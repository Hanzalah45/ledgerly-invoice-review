<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function label(): string
    {
        return $this->code.' · '.$this->name;
    }
}
