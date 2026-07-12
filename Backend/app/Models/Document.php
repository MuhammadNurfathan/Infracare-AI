<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
protected $fillable = [
    'title',
    'file_name',
    'file_path',
    'content',
    'file_type',
    'status',
    'total_chunks'
];

    public function knowledgeChunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }
}