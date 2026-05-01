<?php

declare(strict_types=1);

namespace Islambey\SplathashPhp;

final class BitReader
{
    private int $pos = 0;
    private int $rem = 0;
    private int $curr = 0;

    public function __construct(private readonly string $data)
    {
    }

    public function read(int $bits): int
    {
        $value = 0;

        while ($bits > 0) {
            if ($this->rem === 0) {
                if ($this->pos >= strlen($this->data)) {
                    return $value << $bits;
                }

                $this->curr = ord($this->data[$this->pos]);
                $this->pos++;
                $this->rem = 8;
            }

            $take = min($this->rem, $bits);
            $shift = $this->rem - $take;
            $chunk = ($this->curr >> $shift) & ((1 << $take) - 1);
            $value = ($value << $take) | $chunk;
            $this->rem -= $take;
            $bits -= $take;
        }

        return $value;
    }
}
