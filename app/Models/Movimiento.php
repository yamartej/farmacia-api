<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Movimiento extends Model
{
    use HasFactory;

    protected $fillable = [
        'medicamento_id',
        'tipo',
        'cantidad',
        'fecha',
        'origen',
        'origen_id',
        'descripcion',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function medicamento()
    {
        return $this->belongsTo(Medicamento::class);
    }
}
