<?php

declare(strict_types=1);

namespace Islambey\SplathashPhp;

final class Splat
{
    public function __construct(
        public float $x,
        public float $y,
        public float $sigma,
        public float $l,
        public float $a,
        public float $b,
        public bool $isLepton,
    ) {
    }
}
