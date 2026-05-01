<?php

declare(strict_types=1);

namespace Islambey\SplathashPhp;

use GdImage;
use InvalidArgumentException;
use RuntimeException;

final class SplatHash
{
    public const TARGET_SIZE = 32;
    private const RIDGE_LAMBDA = 0.001;
    private const GAUSS_TABLE_MAX = 1923;
    private const SIGMA_TABLE = [0.025, 0.1, 0.2, 0.35];

    private static bool $initialized = false;
    /** @var array<int, array<int, float>> */
    private static array $gaussLut = [];
    /** @var array<int, array<int, float>> */
    private static array $gaussKernel1D = [];
    /** @var array<int, int> */
    private static array $kernelHw = [0, 0, 0, 0];
    /** @var array<int, float> */
    private static array $gaussPow = [0.0, 0.0, 0.0, 0.0];
    /** @var array<int, float> */
    private static array $linToSrgbLut = [];
    /** @var array<int, float> */
    private static array $srgbLinLut = [];
    /** @var array<int, float> */
    private static array $cbrtLut = [];

    public static function encode(string|GdImage $source): string
    {
        self::init();

        if ($source instanceof GdImage) {
            return self::encodeImage($source);
        }

        if (!is_file($source)) {
            throw new InvalidArgumentException("Image file not found: {$source}");
        }

        $data = @file_get_contents($source);
        if ($data === false) {
            throw new RuntimeException("Unable to read image file: {$source}");
        }

        $image = @imagecreatefromstring($data);
        if (!$image instanceof GdImage) {
            throw new InvalidArgumentException("Unsupported or invalid image: {$source}");
        }

        return self::encodeImage($image);
    }

    public static function encodeRaw(string $rgba, int $width, int $height): string
    {
        self::init();

        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException('Image dimensions must be positive.');
        }

        $expected = $width * $height * 4;
        if (strlen($rgba) !== $expected) {
            throw new InvalidArgumentException("Raw RGBA length must be {$expected} bytes.");
        }

        $grid = self::imageToOklabGrid($rgba, $width, $height, self::TARGET_SIZE, self::TARGET_SIZE);
        $n = self::TARGET_SIZE * self::TARGET_SIZE;

        $meanL = 0.0;
        $meanA = 0.0;
        $meanB = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $idx = $i * 3;
            $meanL += $grid[$idx];
            $meanA += $grid[$idx + 1];
            $meanB += $grid[$idx + 2];
        }

        $packedMean = self::packMean($meanL / $n, $meanA / $n, $meanB / $n);
        [$meanL, $meanA, $meanB] = self::unpackMean($packedMean);

        $resL = $resA = $resB = array_fill(0, $n, 0.0);
        for ($i = 0; $i < $n; $i++) {
            $idx = $i * 3;
            $resL[$i] = $grid[$idx] - $meanL;
            $resA[$i] = $grid[$idx + 1] - $meanA;
            $resB[$i] = $grid[$idx + 2] - $meanB;
        }

        $basis = self::findAllSplats($resL, $resA, $resB, self::TARGET_SIZE, self::TARGET_SIZE, 6);
        if ($basis !== []) {
            $basis = self::solveV4Weights($basis, $grid, $meanL, $meanA, $meanB, self::TARGET_SIZE, self::TARGET_SIZE);
        }

        return self::packV4($packedMean, $basis);
    }

    public static function decode(string $hash): string
    {
        self::init();

        if (strlen($hash) !== 16) {
            throw new InvalidArgumentException('Invalid SplatHash: expected 16 bytes.');
        }

        [$meanL, $meanA, $meanB, $splats] = self::unpackV4($hash);
        $w = self::TARGET_SIZE;
        $h = self::TARGET_SIZE;
        $grid = array_fill(0, $w * $h * 3, 0.0);

        for ($i = 0; $i < $w * $h; $i++) {
            $idx = $i * 3;
            $grid[$idx] = $meanL;
            $grid[$idx + 1] = $meanA;
            $grid[$idx + 2] = $meanB;
        }

        foreach ($splats as $splat) {
            self::addSplatToGrid($grid, $splat, $w, $h);
        }

        $out = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $idx = ($y * $w + $x) * 3;
                [$r, $g, $b] = self::oklabToSrgb($grid[$idx], $grid[$idx + 1], $grid[$idx + 2]);
                $out .= chr(self::clampi((int) floor($r * 255 + 0.5), 0, 255));
                $out .= chr(self::clampi((int) floor($g * 255 + 0.5), 0, 255));
                $out .= chr(self::clampi((int) floor($b * 255 + 0.5), 0, 255));
                $out .= chr(255);
            }
        }

        return $out;
    }

    public static function toBase64Url(string $hash): string
    {
        if (strlen($hash) !== 16) {
            throw new InvalidArgumentException('SplatHash must be exactly 16 bytes.');
        }

        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    public static function fromBase64Url(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $hash = base64_decode($padded, true);

        if ($hash === false || strlen($hash) !== 16) {
            throw new InvalidArgumentException('Invalid SplatHash base64url string.');
        }

        return $hash;
    }

    private static function encodeImage(GdImage $image): string
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $rgba = '';

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                if (imageistruecolor($image)) {
                    $r = ($color >> 16) & 0xff;
                    $g = ($color >> 8) & 0xff;
                    $b = $color & 0xff;
                    $a = 255 - (int) round((($color >> 24) & 0x7f) * 255 / 127);
                } else {
                    $parts = imagecolorsforindex($image, $color);
                    $r = $parts['red'];
                    $g = $parts['green'];
                    $b = $parts['blue'];
                    $a = 255 - (int) round(($parts['alpha'] ?? 0) * 255 / 127);
                }
                $rgba .= chr($r) . chr($g) . chr($b) . chr($a);
            }
        }

        return self::encodeRaw($rgba, $width, $height);
    }

    private static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        $w2 = self::TARGET_SIZE * self::TARGET_SIZE;
        foreach (self::SIGMA_TABLE as $si => $sigma) {
            $scale2 = 2.0 * $sigma * $sigma * $w2;
            $lut = [];
            for ($dsq = 0; $dsq < self::GAUSS_TABLE_MAX; $dsq++) {
                $v = exp(-$dsq / $scale2);
                $lut[$dsq] = $v < 1e-7 ? 0.0 : $v;
            }
            self::$gaussLut[$si] = $lut;

            $hw = 0;
            for ($d = 0; $d < self::TARGET_SIZE; $d++) {
                if ($lut[$d * $d] < 1e-7) {
                    break;
                }
                $hw = $d;
            }
            self::$kernelHw[$si] = $hw;

            $kern = [];
            for ($d = 0; $d <= $hw; $d++) {
                $kern[$d] = $lut[$d * $d];
            }
            self::$gaussKernel1D[$si] = $kern;

            $sum1d = 0.0;
            for ($d = -$hw; $d <= $hw; $d++) {
                $v = $kern[abs($d)];
                $sum1d += $v * $v;
            }
            self::$gaussPow[$si] = $sum1d * $sum1d;
        }

        for ($v = 0; $v < 256; $v++) {
            $c = $v / 255.0;
            self::$srgbLinLut[$v] = $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        for ($i = 0; $i < 1024; $i++) {
            $c = $i / 1023.0;
            self::$linToSrgbLut[$i] = $c <= 0.0031308 ? 12.92 * $c : 1.055 * ($c ** (1.0 / 2.4)) - 0.055;
        }

        for ($i = 0; $i <= 1024; $i++) {
            self::$cbrtLut[$i] = ($i / 1024.0) ** (1.0 / 3.0);
        }

        self::$initialized = true;
    }

    /** @return array<int, float> */
    private static function imageToOklabGrid(string $rgba, int $srcW, int $srcH, int $w, int $h): array
    {
        $out = array_fill(0, $w * $h * 3, 0.0);

        for ($y = 0; $y < $h; $y++) {
            $sy = intdiv($y * $srcH + intdiv($srcH, 2), $h);
            for ($x = 0; $x < $w; $x++) {
                $sx = intdiv($x * $srcW + intdiv($srcW, 2), $w);
                $p = ($sy * $srcW + $sx) * 4;
                $r = self::$srgbLinLut[ord($rgba[$p])];
                $g = self::$srgbLinLut[ord($rgba[$p + 1])];
                $b = self::$srgbLinLut[ord($rgba[$p + 2])];
                [$l, $a, $bb] = self::srgbLinToOklab($r, $g, $b);
                $idx = ($y * $w + $x) * 3;
                $out[$idx] = $l;
                $out[$idx + 1] = $a;
                $out[$idx + 2] = $bb;
            }
        }

        return $out;
    }

    /** @param array<int, float> $grid */
    private static function solveV4Weights(array $basis, array $grid, float $meanL, float $meanA, float $meanB, int $w, int $h): array
    {
        $nTotal = count($basis);
        $m = $w * $h;
        $targetL = $targetA = $targetB = array_fill(0, $m, 0.0);

        for ($i = 0; $i < $m; $i++) {
            $idx = $i * 3;
            $targetL[$i] = $grid[$idx] - $meanL;
            $targetA[$i] = $grid[$idx + 1] - $meanA;
            $targetB[$i] = $grid[$idx + 2] - $meanB;
        }

        $activations = [];
        foreach ($basis as $splat) {
            $activations[] = self::computeBasisMap($splat, $w, $h);
        }

        $nBaryons = min($nTotal, 3);
        $xL = self::solveChannel($activations, $targetL, $nTotal, self::RIDGE_LAMBDA);
        $xA = self::solveChannel(array_slice($activations, 0, $nBaryons), $targetA, $nBaryons, self::RIDGE_LAMBDA);
        $xB = self::solveChannel(array_slice($activations, 0, $nBaryons), $targetB, $nBaryons, self::RIDGE_LAMBDA);

        $out = [];
        foreach ($basis as $i => $splat) {
            $out[] = new Splat($splat->x, $splat->y, $splat->sigma, $xL[$i], $i < 3 ? $xA[$i] : 0.0, $i < 3 ? $xB[$i] : 0.0, $splat->isLepton);
        }

        return $out;
    }

    /** @param array<int, array<int, float>> $activations @param array<int, float> $target @return array<int, float> */
    private static function solveChannel(array $activations, array $target, int $n, float $lambda): array
    {
        if ($n === 0) {
            return [];
        }

        $m = count($target);
        $ata = array_fill(0, $n * $n, 0.0);
        $atb = array_fill(0, $n, 0.0);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i; $j < $n; $j++) {
                $sum = 0.0;
                for ($p = 0; $p < $m; $p++) {
                    $sum += $activations[$i][$p] * $activations[$j][$p];
                }
                $ata[$i * $n + $j] = $sum;
                $ata[$j * $n + $i] = $sum;
            }

            $sumB = 0.0;
            for ($p = 0; $p < $m; $p++) {
                $sumB += $activations[$i][$p] * $target[$p];
            }
            $atb[$i] = $sumB;
        }

        for ($i = 0; $i < $n; $i++) {
            $ata[$i * $n + $i] += $lambda;
        }

        return self::solveLinearSystem($ata, $atb, $n);
    }

    /** @param array<int, float> $mat @param array<int, float> $vec @return array<int, float> */
    private static function solveLinearSystem(array $mat, array $vec, int $n): array
    {
        for ($k = 0; $k < $n - 1; $k++) {
            for ($i = $k + 1; $i < $n; $i++) {
                $factor = $mat[$i * $n + $k] / $mat[$k * $n + $k];
                for ($j = $k; $j < $n; $j++) {
                    $mat[$i * $n + $j] -= $factor * $mat[$k * $n + $j];
                }
                $vec[$i] -= $factor * $vec[$k];
            }
        }

        $x = array_fill(0, $n, 0.0);
        for ($i = $n - 1; $i >= 0; $i--) {
            $sum = 0.0;
            for ($j = $i + 1; $j < $n; $j++) {
                $sum += $mat[$i * $n + $j] * $x[$j];
            }
            $x[$i] = ($vec[$i] - $sum) / $mat[$i * $n + $i];
        }

        return $x;
    }

    /** @return array<int, float> */
    private static function computeBasisMap(Splat $splat, int $w, int $h): array
    {
        $out = array_fill(0, $w * $h, 0.0);
        $si = self::sigmaIndex($splat->sigma);
        $hw = self::$kernelHw[$si];
        $cx = (int) ($splat->x * $w);
        $cy = (int) ($splat->y * $h);
        $y0 = self::clampi($cy - $hw, 0, $h - 1);
        $y1 = self::clampi($cy + $hw, 0, $h - 1);
        $x0 = self::clampi($cx - $hw, 0, $w - 1);
        $x1 = self::clampi($cx + $hw, 0, $w - 1);

        for ($y = $y0; $y <= $y1; $y++) {
            $dy = $y - $cy;
            $rowBase = $y * $w;
            for ($x = $x0; $x <= $x1; $x++) {
                $dx = $x - $cx;
                $dsq = $dx * $dx + $dy * $dy;
                if ($dsq < self::GAUSS_TABLE_MAX) {
                    $out[$rowBase + $x] = self::$gaussLut[$si][$dsq];
                }
            }
        }

        return $out;
    }

    /** @param array<int, float> $resL @param array<int, float> $resA @param array<int, float> $resB @return array<int, Splat> */
    private static function findAllSplats(array &$resL, array &$resA, array &$resB, int $w, int $h, int $nSplats): array
    {
        $splats = [];
        $n = $w * $h;
        $tmpL = $tmpA = $tmpB = array_fill(0, $n, 0.0);
        $scoreMap = array_fill(0, $n, -1.0);
        $sigmaMap = array_fill(0, $n, -1);

        while (count($splats) < $nSplats) {
            $isBaryon = count($splats) < 3;
            for ($i = 0; $i < $n; $i++) {
                $scoreMap[$i] = -1.0;
                $sigmaMap[$i] = -1;
            }

            for ($si = 0; $si < 4; $si++) {
                $kern = self::$gaussKernel1D[$si];
                $hw = self::$kernelHw[$si];
                $invGg = 1.0 / self::$gaussPow[$si];

                for ($y = 0; $y < $h; $y++) {
                    $rowOff = $y * $w;
                    for ($x = 0; $x < $w; $x++) {
                        $sL = $kern[0] * $resL[$rowOff + $x];
                        $sA = $kern[0] * $resA[$rowOff + $x];
                        $sB = $kern[0] * $resB[$rowOff + $x];
                        for ($d = 1; $d <= $hw; $d++) {
                            $k = $kern[$d];
                            $xl = $x - $d;
                            if ($xl >= 0) {
                                $sL += $k * $resL[$rowOff + $xl];
                                if ($isBaryon) {
                                    $sA += $k * $resA[$rowOff + $xl];
                                    $sB += $k * $resB[$rowOff + $xl];
                                }
                            }
                            $xr = $x + $d;
                            if ($xr < $w) {
                                $sL += $k * $resL[$rowOff + $xr];
                                if ($isBaryon) {
                                    $sA += $k * $resA[$rowOff + $xr];
                                    $sB += $k * $resB[$rowOff + $xr];
                                }
                            }
                        }
                        $tmpL[$rowOff + $x] = $sL;
                        if ($isBaryon) {
                            $tmpA[$rowOff + $x] = $sA;
                            $tmpB[$rowOff + $x] = $sB;
                        }
                    }
                }

                for ($x = 0; $x < $w; $x++) {
                    for ($y = 0; $y < $h; $y++) {
                        $sL = $kern[0] * $tmpL[$y * $w + $x];
                        $sA = $kern[0] * $tmpA[$y * $w + $x];
                        $sB = $kern[0] * $tmpB[$y * $w + $x];
                        for ($d = 1; $d <= $hw; $d++) {
                            $k = $kern[$d];
                            $yu = $y - $d;
                            if ($yu >= 0) {
                                $sL += $k * $tmpL[$yu * $w + $x];
                                if ($isBaryon) {
                                    $sA += $k * $tmpA[$yu * $w + $x];
                                    $sB += $k * $tmpB[$yu * $w + $x];
                                }
                            }
                            $yd = $y + $d;
                            if ($yd < $h) {
                                $sL += $k * $tmpL[$yd * $w + $x];
                                if ($isBaryon) {
                                    $sA += $k * $tmpA[$yd * $w + $x];
                                    $sB += $k * $tmpB[$yd * $w + $x];
                                }
                            }
                        }
                        $i = $y * $w + $x;
                        $score = $isBaryon ? ($sL * $sL + $sA * $sA + $sB * $sB) * $invGg : $sL * $sL * $invGg;
                        if ($score > $scoreMap[$i]) {
                            $scoreMap[$i] = $score;
                            $sigmaMap[$i] = $si;
                        }
                    }
                }
            }

            $bestScore = -1.0;
            $bestIdx = -1;
            for ($i = 0; $i < $n; $i++) {
                if ($scoreMap[$i] > $bestScore) {
                    $bestScore = $scoreMap[$i];
                    $bestIdx = $i;
                }
            }

            if ($bestIdx < 0 || $bestScore < 1e-9) {
                break;
            }

            $bx = $bestIdx % $w;
            $by = intdiv($bestIdx, $w);
            $si = $sigmaMap[$bestIdx];
            $kern = self::$gaussKernel1D[$si];
            $hw = self::$kernelHw[$si];
            $gg = self::$gaussPow[$si];

            $dotL = $dotA = $dotB = 0.0;
            for ($dy = -$hw; $dy <= $hw; $dy++) {
                $yy = $by + $dy;
                if ($yy < 0 || $yy >= $h) {
                    continue;
                }
                $ky = $kern[abs($dy)];
                for ($dx = -$hw; $dx <= $hw; $dx++) {
                    $xx = $bx + $dx;
                    if ($xx < 0 || $xx >= $w) {
                        continue;
                    }
                    $kv = $ky * $kern[abs($dx)];
                    $off = $yy * $w + $xx;
                    $dotL += $kv * $resL[$off];
                    $dotA += $kv * $resA[$off];
                    $dotB += $kv * $resB[$off];
                }
            }

            $invGg = 1.0 / $gg;
            $splat = new Splat($bx / $w, $by / $h, self::SIGMA_TABLE[$si], $dotL * $invGg, $dotA * $invGg, $dotB * $invGg, !$isBaryon);
            $splats[] = $splat;

            $y0 = self::clampi($by - $hw, 0, $h - 1);
            $y1 = self::clampi($by + $hw, 0, $h - 1);
            $x0 = self::clampi($bx - $hw, 0, $w - 1);
            $x1 = self::clampi($bx + $hw, 0, $w - 1);
            for ($y = $y0; $y <= $y1; $y++) {
                $dy = $y - $by;
                $rowBase = $y * $w;
                for ($x = $x0; $x <= $x1; $x++) {
                    $dx = $x - $bx;
                    $dsq = $dx * $dx + $dy * $dy;
                    if ($dsq >= self::GAUSS_TABLE_MAX) {
                        continue;
                    }
                    $wVal = self::$gaussLut[$si][$dsq];
                    if ($wVal == 0.0) {
                        continue;
                    }
                    $off = $rowBase + $x;
                    $resL[$off] -= $splat->l * $wVal;
                    $resA[$off] -= $splat->a * $wVal;
                    $resB[$off] -= $splat->b * $wVal;
                }
            }
        }

        return $splats;
    }

    /** @param array<int, float> $grid */
    private static function addSplatToGrid(array &$grid, Splat $splat, int $w, int $h): void
    {
        $si = self::sigmaIndex($splat->sigma);
        $hw = self::$kernelHw[$si];
        $cx = (int) ($splat->x * $w);
        $cy = (int) ($splat->y * $h);
        $y0 = self::clampi($cy - $hw, 0, $h - 1);
        $y1 = self::clampi($cy + $hw, 0, $h - 1);
        $x0 = self::clampi($cx - $hw, 0, $w - 1);
        $x1 = self::clampi($cx + $hw, 0, $w - 1);

        for ($y = $y0; $y <= $y1; $y++) {
            $dy = $y - $cy;
            $rowBase = $y * $w * 3;
            for ($x = $x0; $x <= $x1; $x++) {
                $dx = $x - $cx;
                $dsq = $dx * $dx + $dy * $dy;
                if ($dsq >= self::GAUSS_TABLE_MAX) {
                    continue;
                }
                $wVal = self::$gaussLut[$si][$dsq];
                if ($wVal == 0.0) {
                    continue;
                }
                $idx = $rowBase + $x * 3;
                $grid[$idx] += $splat->l * $wVal;
                $grid[$idx + 1] += $splat->a * $wVal;
                $grid[$idx + 2] += $splat->b * $wVal;
            }
        }
    }

    private static function packV4(int $mean, array $splats): string
    {
        $bw = new BitWriter();
        $bw->write($mean, 16);

        $count = 0;
        foreach ($splats as $splat) {
            if ($splat->isLepton) {
                continue;
            }
            if ($count >= 3) {
                break;
            }
            $bw->write(self::clampi((int) floor($splat->x * 15.0 + 0.5), 0, 15), 4);
            $bw->write(self::clampi((int) floor($splat->y * 15.0 + 0.5), 0, 15), 4);
            $bw->write(self::sigmaIndex($splat->sigma), 2);
            $bw->write(self::quant($splat->l, -0.8, 0.8, 4), 4);
            $bw->write(self::quant($splat->a, -0.4, 0.4, 4), 4);
            $bw->write(self::quant($splat->b, -0.4, 0.4, 4), 4);
            $count++;
        }
        while ($count < 3) {
            $bw->write(0, 22);
            $count++;
        }

        $count = 0;
        foreach ($splats as $splat) {
            if (!$splat->isLepton) {
                continue;
            }
            if ($count >= 3) {
                break;
            }
            $bw->write(self::clampi((int) floor($splat->x * 15.0 + 0.5), 0, 15), 4);
            $bw->write(self::clampi((int) floor($splat->y * 15.0 + 0.5), 0, 15), 4);
            $bw->write(self::sigmaIndex($splat->sigma), 2);
            $bw->write(self::quant($splat->l, -0.8, 0.8, 5), 5);
            $count++;
        }
        while ($count < 3) {
            $bw->write(0, 15);
            $count++;
        }

        $bw->write(0, 1);
        return $bw->bytes();
    }

    /** @return array{0: float, 1: float, 2: float, 3: array<int, Splat>} */
    private static function unpackV4(string $hash): array
    {
        $br = new BitReader($hash);
        [$meanL, $meanA, $meanB] = self::unpackMean($br->read(16));
        $splats = [];

        for ($i = 0; $i < 3; $i++) {
            $xi = $br->read(4);
            $yi = $br->read(4);
            $sigI = $br->read(2);
            $lQ = $br->read(4);
            $aQ = $br->read(4);
            $bQ = $br->read(4);
            if ($xi === 0 && $yi === 0 && $lQ === 0 && $aQ === 0 && $bQ === 0) {
                continue;
            }
            $splats[] = new Splat($xi / 15.0, $yi / 15.0, self::SIGMA_TABLE[$sigI], self::unquant($lQ, -0.8, 0.8, 4), self::unquant($aQ, -0.4, 0.4, 4), self::unquant($bQ, -0.4, 0.4, 4), false);
        }

        for ($i = 0; $i < 3; $i++) {
            $xi = $br->read(4);
            $yi = $br->read(4);
            $sigI = $br->read(2);
            $lQ = $br->read(5);
            if ($xi === 0 && $yi === 0 && $lQ === 0) {
                continue;
            }
            $splats[] = new Splat($xi / 15.0, $yi / 15.0, self::SIGMA_TABLE[$sigI], self::unquant($lQ, -0.8, 0.8, 5), 0.0, 0.0, true);
        }

        return [$meanL, $meanA, $meanB, $splats];
    }

    private static function packMean(float $l, float $a, float $b): int
    {
        $li = self::clampi((int) floor($l * 63.5), 0, 63);
        $ai = self::clampi((int) floor((($a + 0.2) / 0.4) * 31.5), 0, 31);
        $bi = self::clampi((int) floor((($b + 0.2) / 0.4) * 31.5), 0, 31);

        return ($li << 10) | ($ai << 5) | $bi;
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function unpackMean(int $packed): array
    {
        $li = ($packed >> 10) & 0x3f;
        $ai = ($packed >> 5) & 0x1f;
        $bi = $packed & 0x1f;

        return [$li / 63.0, ($ai / 31.0) * 0.4 - 0.2, ($bi / 31.0) * 0.4 - 0.2];
    }

    private static function quant(float $value, float $min, float $max, int $bits): int
    {
        $steps = (1 << $bits) - 1;
        $norm = ($value - $min) / ($max - $min);

        return self::clampi((int) floor($norm * $steps + 0.5), 0, $steps);
    }

    private static function unquant(int $value, float $min, float $max, int $bits): float
    {
        $steps = (1 << $bits) - 1;

        return ($value / $steps) * ($max - $min) + $min;
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function srgbLinToOklab(float $r, float $g, float $b): array
    {
        $l1 = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m1 = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s1 = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
        $l_ = self::cbrtFast($l1);
        $m_ = self::cbrtFast($m1);
        $s_ = self::cbrtFast($s1);

        return [
            0.2104542553 * $l_ + 0.7936177850 * $m_ - 0.0040720468 * $s_,
            1.9779984951 * $l_ - 2.4285922050 * $m_ + 0.4505937099 * $s_,
            0.0259040371 * $l_ + 0.7827717662 * $m_ - 0.8086757660 * $s_,
        ];
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function oklabToSrgb(float $l, float $a, float $b): array
    {
        $l_ = $l + 0.3963377774 * $a + 0.2158037573 * $b;
        $m_ = $l - 0.1055613458 * $a - 0.0638541728 * $b;
        $s_ = $l - 0.0894841775 * $a - 1.2914855480 * $b;
        $l3 = $l_ ** 3;
        $m3 = $m_ ** 3;
        $s3 = $s_ ** 3;

        return [
            self::linToSrgbFast(+4.0767416621 * $l3 - 3.3077115913 * $m3 + 0.2309699292 * $s3),
            self::linToSrgbFast(-1.2684380046 * $l3 + 2.6097574011 * $m3 - 0.3413193965 * $s3),
            self::linToSrgbFast(-0.0041960863 * $l3 - 0.7034186147 * $m3 + 1.7076147010 * $s3),
        ];
    }

    private static function cbrtFast(float $x): float
    {
        if ($x <= 0.0) {
            return 0.0;
        }
        if ($x >= 1.0) {
            return self::$cbrtLut[1024];
        }

        return self::$cbrtLut[(int) floor($x * 1024.0 + 0.5)];
    }

    private static function linToSrgbFast(float $c): float
    {
        if ($c <= 0.0) {
            return 0.0;
        }
        if ($c >= 1.0) {
            return 1.0;
        }

        return self::$linToSrgbLut[(int) floor($c * 1023.0 + 0.5)];
    }

    private static function sigmaIndex(float $sigma): int
    {
        $bestI = 0;
        $bestD = abs(self::SIGMA_TABLE[0] - $sigma);
        for ($i = 1; $i < 4; $i++) {
            $d = abs(self::SIGMA_TABLE[$i] - $sigma);
            if ($d < $bestD) {
                $bestD = $d;
                $bestI = $i;
            }
        }

        return $bestI;
    }

    private static function clampi(int $value, int $min, int $max): int
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }

        return $value;
    }
}
