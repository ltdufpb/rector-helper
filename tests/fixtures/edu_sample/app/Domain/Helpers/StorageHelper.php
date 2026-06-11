<?php

namespace App\Domain\Helpers;

class StorageHelper
{
    public function path(string $arquivo): string
    {
        return '/storage/' . $arquivo;
    }
}
