<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The running strip on the user home page of one branch ("Live Casino Not
 * Allowed"). Hidden completely while `enabled` is off or the text is empty.
 */
class BranchTicker extends Model
{
    protected $fillable = ['branch_id', 'enabled', 'text', 'updated_by'];

    protected $casts = [
        'branch_id' => 'integer',
        'enabled' => 'boolean',
    ];

    public function isShown(): bool
    {
        return $this->enabled && trim((string) $this->text) !== '';
    }
}
