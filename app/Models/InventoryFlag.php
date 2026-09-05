<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryFlag extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['ingredient_id', 'has_stock', 'note', 'last_updated'];

    protected function casts(): array
    {
        return [
            'has_stock' => 'boolean',
            'last_updated' => 'datetime',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
