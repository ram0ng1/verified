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

    /**
     * Roda o callback dentro de uma transação do connection deste model.
     * Centraliza o pattern `query()->getConnection()->transaction()` para
     * evitar duplicação entre controller, resource e listener — e mantém
     * a resolução de connection junto do model que define a tabela.
     *
     * @template T
     * @param  \Closure(): T  $cb
     * @return T
     */
    public static function runInTransaction(\Closure $cb): mixed
    {
        return self::query()->getConnection()->transaction($cb);
    }
}
