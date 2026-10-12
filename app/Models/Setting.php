<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** Simple key/value store for app settings. Keys listed in ENCRYPTED are stored encrypted. */
class Setting extends Model
{
    public const ENCRYPTED = ['ai_api_key'];

    public const DEFAULTS = [
        'ai_provider' => 'openai',
        'ai_model' => '',
        'ai_base_url' => '',
        'ai_api_key' => '',
        'ai_instructions' => '',
        'ai_reply_delay' => '4',
        'ai_pause_hours' => '12',
        'ai_handoff_message' => 'Thank you! Our team member will reply to you shortly.',
        'quick_replies' => "Namaste! How can we help you?\n"
            ."Please share your phone number and full address to place the order.\n"
            .'Thank you for your order! It will be delivered in 2-4 days.',
    ];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        self::$cache ??= self::query()->pluck('value', 'key')->all();
        $value = self::$cache[$key] ?? null;

        if ($value !== null && $value !== '' && in_array($key, self::ENCRYPTED, true)) {
            $value = Crypt::decryptString($value);
        }

        return ($value === null || $value === '') ? ($default ?? self::DEFAULTS[$key] ?? null) : $value;
    }

    public static function put(string $key, ?string $value): void
    {
        $stored = ($value !== null && $value !== '' && in_array($key, self::ENCRYPTED, true))
            ? Crypt::encryptString($value)
            : $value;

        self::query()->updateOrCreate(['key' => $key], ['value' => $stored]);
        self::$cache = null;
    }

    public static function flushCache(): void
    {
        self::$cache = null;
    }
}
