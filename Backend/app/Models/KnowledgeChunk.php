<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunk extends Model
{
    protected $fillable = [
        'document_id',
        'chunk_number',
        'content',
        'embedding',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}