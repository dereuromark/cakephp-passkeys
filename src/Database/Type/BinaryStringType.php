<?php

declare(strict_types=1);

namespace CakePasskeys\Database\Type;

use Cake\Database\Driver;
use Cake\Database\Type\BinaryType;

/**
 * Binary column that reads as a raw byte string.
 *
 * The core `binary` type returns a stream resource, which the WebAuthn
 * library cannot parse. Typing the columns as `string` returns bytes but
 * also binds them as text, and PostgreSQL rejects arbitrary bytes sent as
 * text to a `bytea` column. This type binds as a LOB and reads as a string.
 */
class BinaryStringType extends BinaryType
{
    /**
     * @param mixed $value The value to convert.
     * @param \Cake\Database\Driver $driver The driver instance to convert with.
     *
     * @return string|null
     */
    public function toPHP(mixed $value, Driver $driver): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_resource($value)) {
            $bytes = stream_get_contents($value);

            return $bytes === false ? null : $bytes;
        }

        return (string)$value;
    }
}
