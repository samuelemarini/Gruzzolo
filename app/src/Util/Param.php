<?php

namespace App\Util;

use Symfony\Component\HttpFoundation\ParameterBag;

final class Param
{
    /**
     * Intero da query o form: "" (select su "Nessuno") e valori non numerici valgono $default.
     * In Symfony 8 InputBag::getInt() li rifiuta con un 400, qui non devono rompere il salvataggio.
     */
    public static function int(ParameterBag $bag, string $key, int $default = 0): int
    {
        $value = $bag->has($key) ? $bag->all()[$key] : null;

        return is_scalar($value) && is_numeric($value) ? (int) $value : $default;
    }
}
