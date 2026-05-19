<?php

namespace Ramon\Verified\Models;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property bool $is_verified
 * @property \Carbon\Carbon|null $verified_at
 * @property int|null $verified_by
 * @property string|null $verified_tier
 * @property-read User|null $user
 */
class UserVerification extends AbstractModel
{
    protected $table = 'user_verification';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'is_verified' => 'bool',
        'verified_at' => 'datetime',
        'verified_tier' => 'string',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
