<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Il tenant: l'azienda che usa la suite. */
class Company extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'companies';

    protected $guarded = ['id'];

    protected $hidden = ['db_password'];

    protected function casts(): array
    {
        return [
            'db_password' => 'encrypted',
            'oam_at' => 'date',
        ];
    }

    /** Capogruppo (holding), se l'azienda fa parte di un gruppo. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Aziende controllate dalla capogruppo. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'company_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** Archivio file documenti: `local`, `google_drive` o `sharepoint`. */
    public function documentsStorage(): string
    {
        return filled($this->documents_storage) ? (string) $this->documents_storage : 'local';
    }

    public function usesGoogleDriveStorage(): bool
    {
        return $this->documentsStorage() === 'google_drive';
    }

    public function usesSharePointStorage(): bool
    {
        return $this->documentsStorage() === 'sharepoint';
    }
}
