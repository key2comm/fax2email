<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Application-wide settings that admins change from the portal. Each value is
 * an array encrypted with the APP_KEY, so secrets such as SMTP passwords are
 * never stored in plain text.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted:array',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function read(string $key): ?array
    {
        return self::find($key)?->value;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function write(string $key, array $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
