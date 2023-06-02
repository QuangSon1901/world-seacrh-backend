<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HistorySearch extends Model
{
    use HasFactory;
    protected $table = 'history_search';

    protected $fillable = [
        'content',
        'type',
        'id_user',
        'updated_at',
        'created_at',
    ];
}
