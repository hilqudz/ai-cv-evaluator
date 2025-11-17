<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobDescription extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'skills' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'synced_to_chromadb_at' => 'datetime',
    ];

    /**
     * Scope for active job descriptions
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope by category
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope by level
     */
    public function scopeByLevel($query, string $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Get skills as comma-separated string (for ChromaDB metadata)
     */
    public function getSkillsStringAttribute(): string
    {
        return implode(', ', $this->skills ?? []);
    }

    /**
     * Check if job needs sync to ChromaDB
     */
    public function needsSync(): bool
    {
        return is_null($this->synced_to_chromadb_at) || 
               $this->updated_at > $this->synced_to_chromadb_at;
    }

    /**
     * Mark as synced to ChromaDB
     */
    public function markSynced(): void
    {
        $this->update(['synced_to_chromadb_at' => now()]);
    }
}