<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TdrDocument extends Model
{
    public const UPDATED_AT = null;

    public const KIND_TDR = 'tdr';

    public const KIND_FINAL = 'final';

    protected $fillable = ['kind', 'version', 'disk', 'path', 'original_name', 'mime_type', 'size', 'uploaded_by'];

    public function tdr(): BelongsTo
    {
        return $this->belongsTo(Tdr::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function humanSize(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1, ',', ' ').' Mo'
            : max(1, (int) round($this->size / 1024)).' Ko';
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }
}
