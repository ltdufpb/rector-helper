<?php

class DBDate
{
    public function getDia(string $data): string
    {
        return substr($data, 8, 2);
    }
}
