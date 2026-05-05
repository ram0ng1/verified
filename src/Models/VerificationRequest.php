<?php

namespace Ramon\Verified\Models;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $status
 * @property string|null $document_type
 * @property string|null $document_path
 * @property string|null $reason
 * @property string|null $admin_note
 * @property int|null $handled_by
 * @property \Carbon\Carbon|null $handled_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class VerificationRequest extends AbstractModel
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $table = 'verification_requests';

    protected $casts = [
        'handled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'user_id',
        'status',
        'document_type',
        'document_path',
        'reason',
        'admin_note',
        'handled_by',
        'handled_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
