<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /**
     * @use HasFactory<UserFactory>
     */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'avatar',
        'email_verified_at',
        'password',
        'role',
        'google_id',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    // Surfaced to the SPA so it never has to rebuild the storage path itself.
    protected $appends = [
        'avatar_url',
        'initials',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * The avatar as an absolute path from the app root, e.g. "/storage/avatars/x.jpg".
     *
     * Cloudinary assets are already absolute HTTPS URLs and are returned
     * untouched; prefixing those would build the broken
     * "/storage/https://res.cloudinary.com/..." path.
     *
     * Local paths stay deliberately host-less: `Storage::url()` would bake in
     * APP_URL, which is frequently stale (this project's .env still says
     * http://localhost while the API is served from 127.0.0.1:8000). The Vue
     * app's imageUrl() helper prefixes its own VITE_BACKEND_URL, so a relative
     * path stays correct on every environment. Null when the user has no avatar.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (blank($this->avatar)) {
                return null;
            }

            $value = trim($this->avatar);

            // Already a full URL (Cloudinary, or an external image): hand it over
            // as-is. The SPA's imageUrl() passes absolute values straight to <img>.
            if (preg_match('#^(https?:)?//#i', $value) === 1) {
                return $value;
            }

            $path = ltrim($value, '/');

            return str_starts_with($path, 'storage/') ? '/'.$path : '/storage/'.$path;
        });
    }

    /**
     * First name plus last initial, used as the avatar fallback.
     */
    protected function initials(): Attribute
    {
        return Attribute::get(function (): string {
            $words = preg_split('/\s+/', trim((string) $this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($words === []) {
                return '?';
            }

            $first = mb_substr($words[0], 0, 1);
            $last = count($words) > 1 ? mb_substr(end($words), 0, 1) : '';

            return mb_strtoupper($first.$last);
        });
    }
}
