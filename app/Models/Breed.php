<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A reusable breed suggestion. NOT a foreign key on pets — pets keep their breed
 * as a free TEXT column; this table only powers the autocomplete + case-insensitive
 * dedup. `type` (default 'dog') exists for a future pet-type hierarchy.
 */
class Breed extends Model
{
    protected $fillable = ['name', 'type'];

    /**
     * Case-insensitive find-or-create. Trims, returns null for blank, reuses an
     * existing row regardless of casing ("frenchie" reuses "French Bulldog"), and
     * only inserts a genuinely new breed. The single point of truth so every entry
     * path (autocomplete create, pet save) dedups identically.
     */
    public static function findOrCreateByName(?string $name, string $type = 'dog'): ?self
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }

        $existing = static::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            return $existing;
        }

        // firstOrCreate as a race-safe backstop against the unique index.
        return static::query()->firstOrCreate(
            ['name' => $name],
            ['type' => $type],
        );
    }

    /**
     * Search suggestions for the autocomplete: name => name (the breed string is
     * both the option value AND label, since pets store the string). Capped.
     *
     * @return array<string,string>
     */
    public static function searchNames(string $search): array
    {
        $search = trim($search);

        return static::query()
            ->when($search !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->orderBy('name')
            ->limit(50)
            ->pluck('name', 'name')
            ->all();
    }
}
