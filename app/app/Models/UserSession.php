<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class UserSession extends Model
{
    protected $table = 'sessions';

    /** Primary key is an app-generated string (UUID), not auto-increment. */
    public $incrementing = false;

    protected $keyType = 'string';

    /** Table only has created_at, no updated_at. */
    const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'user_id',
        'token_hash',
        'device_info',
        'ip_address',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
