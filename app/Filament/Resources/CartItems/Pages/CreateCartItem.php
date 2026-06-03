<?php

namespace App\Filament\Resources\CartItems\Pages;

use App\Filament\Resources\CartItems\CartItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCartItem extends CreateRecord
{
    protected static string $resource = CartItemResource::class;
}
