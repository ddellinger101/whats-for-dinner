<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class RepeaterItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['item_name', 'frequency_days', 'last_purchased_date', 'next_due_date'];

    protected function casts(): array
    {
        return [
            'frequency_days' => 'integer',
            'last_purchased_date' => 'date',
            'next_due_date' => 'date',
        ];
    }

    /**
     * Spec 4.6: repeaters surface on the grocery list once due, independent of
     * the meal plan. An item never purchased is treated as due now.
     */
    public function scopeDue(Builder $query, ?Carbon $asOf = null): Builder
    {
        $asOf ??= Carbon::today();

        return $query->where(function (Builder $q) use ($asOf) {
            $q->whereNull('next_due_date')->orWhereDate('next_due_date', '<=', $asOf);
        });
    }

    public function markPurchased(?Carbon $on = null): void
    {
        $on ??= Carbon::today();

        $this->update([
            'last_purchased_date' => $on,
            'next_due_date' => $on->copy()->addDays($this->frequency_days),
        ]);
    }
}
