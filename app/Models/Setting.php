<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace settings stored as key => JSON value. Reads are memoised for the request.
 */
class Setting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    public const DEFAULTS = [
        'company_name' => null,
        'company_legal_name' => null,
        'company_address' => null,
        'company_tax_id' => null,
        'logo_path' => null,
        'approval_threshold' => null,   // reviewers cannot approve invoices above this amount; admins can
        'require_po' => false,          // warn when an invoice has no PO number
        'approval_tiers' => [],         // [{upto, steps}]: empty = single-step approval with the limit above
        'separate_duties' => true,      // with tiers: one person cannot approve two steps of an invoice
        'require_coding' => false,      // department and GL code needed before the first approval
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all_();

        return $all[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** @return array<string,mixed> */
    private static function all_(): array
    {
        if (self::$cache === null) {
            self::$cache = Schema::hasTable('settings')
                ? static::query()->get()->mapWithKeys(fn ($s) => [$s->key => $s->value['v'] ?? null])->all()
                : [];
        }

        return self::$cache;
    }

    public static function workspaceName(): string
    {
        return (string) (self::get('company_name') ?: config('app.name'));
    }
}
