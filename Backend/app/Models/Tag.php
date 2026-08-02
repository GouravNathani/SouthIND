<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $fillable = ['branch_id', 'name', 'color'];

    protected $casts = ['branch_id' => 'integer'];

    public function users()
    {
        return $this->belongsToMany(User::class, 'tag_user');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
