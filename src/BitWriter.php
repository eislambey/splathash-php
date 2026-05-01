<?php

declare(strict_types=1);

namespace Islambey\SplathashPhp;

final class BitWriter
{
    private string $buffer = '';
    private int $acc = 0;
    private int $n = 0;

    public function write(int $value, int $bits): void
    {
        $this->acc = ($this->acc << $bits) | ($value & ((1 << $bits) - 1));
        $this->n += $bits;

        while ($this->n >= 8) {
            $shift = $this->n - 8;
            $this->buffer .= chr(($this->acc >> $shift) & 0xff);
            $this->n -= 8;
        }
    }

    public function bytes(): string
    {
        if ($this->n > 0) {
            $this->buffer .= chr(($this->acc << (8 - $this->n)) & 0xff);
            $this->n = 0;
        }

        return $this->buffer;
    }
}
