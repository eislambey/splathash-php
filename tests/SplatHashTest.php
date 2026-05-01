<?php

declare(strict_types=1);

namespace Islambey\SplathashPhp\Tests;

use Islambey\SplathashPhp\BitReader;
use Islambey\SplathashPhp\BitWriter;
use Islambey\SplathashPhp\Splat;
use Islambey\SplathashPhp\SplatHash;
use GdImage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(SplatHash::class)]
#[CoversClass(BitReader::class)]
#[CoversClass(BitWriter::class)]
#[CoversClass(Splat::class)]
final class SplatHashTest extends TestCase
{
    public function testEncodeRawIsDeterministicAndDecodesToRgba(): void
    {
        $rgba = self::gradientRgba(8, 8);

        $hash1 = SplatHash::encodeRaw($rgba, 8, 8);
        $hash2 = SplatHash::encodeRaw($rgba, 8, 8);

        self::assertSame(16, strlen($hash1));
        self::assertSame($hash1, $hash2);
        self::assertSame('92103291ddb2a49b39f1f75b88aad866', bin2hex($hash1));
        self::assertSame(SplatHash::TARGET_SIZE * SplatHash::TARGET_SIZE * 4, strlen(SplatHash::decode($hash1)));
    }

    public function testBase64UrlRoundTrip(): void
    {
        $hash = SplatHash::encodeRaw(self::gradientRgba(4, 4), 4, 4);
        $encoded = SplatHash::toBase64Url($hash);

        self::assertSame(22, strlen($encoded));
        self::assertSame($hash, SplatHash::fromBase64Url($encoded));
    }

    public function testEncodeAcceptsTrueColorGdImageAndFilePath(): void
    {
        $image = imagecreatetruecolor(4, 4);
        self::assertInstanceOf(GdImage::class, $image);

        imagealphablending($image, false);
        imagesavealpha($image, true);
        $color = imagecolorallocatealpha($image, 64, 128, 192, 32);
        imagefill($image, 0, 0, $color);

        $path = tempnam(sys_get_temp_dir(), 'splathash-') . '.png';
        self::assertTrue(imagepng($image, $path));

        try {
            self::assertSame(16, strlen(SplatHash::encode($image)));
            self::assertSame(16, strlen(SplatHash::encode($path)));
        } finally {
            @unlink($path);
        }
    }

    public function testEncodeAcceptsPaletteGdImage(): void
    {
        $image = imagecreate(3, 3);
        self::assertInstanceOf(GdImage::class, $image);

        $transparent = imagecolorallocatealpha($image, 10, 20, 30, 64);
        imagefill($image, 0, 0, $transparent);

        self::assertSame(16, strlen(SplatHash::encode($image)));
    }

    public function testUniformImageProducesMeanOnlyHash(): void
    {
        $hash = SplatHash::encodeRaw(str_repeat(chr(127) . chr(127) . chr(127) . chr(255), 4), 2, 2);

        self::assertSame(16, strlen($hash));
        self::assertSame(SplatHash::TARGET_SIZE * SplatHash::TARGET_SIZE * 4, strlen(SplatHash::decode($hash)));
    }

    public function testRejectsInvalidInputs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SplatHash::encodeRaw('', 0, 1);
    }

    public function testRejectsWrongRawLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SplatHash::encodeRaw("\0\0\0", 1, 1);
    }

    public function testRejectsMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SplatHash::encode(__DIR__ . '/missing.png');
    }

    public function testRejectsInvalidImageFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'splathash-invalid-');
        self::assertIsString($path);
        file_put_contents($path, 'not an image');

        try {
            $this->expectException(InvalidArgumentException::class);
            SplatHash::encode($path);
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsUnreadableImageFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'splathash-unreadable-');
        self::assertIsString($path);
        file_put_contents($path, 'not readable');
        chmod($path, 0000);

        try {
            $this->expectException(\RuntimeException::class);
            SplatHash::encode($path);
        } finally {
            chmod($path, 0600);
            @unlink($path);
        }
    }

    public function testRejectsInvalidHashAndBase64Values(): void
    {
        try {
            SplatHash::decode(str_repeat("\0", 15));
            self::fail('Invalid hash length should throw.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Invalid SplatHash: expected 16 bytes.', $exception->getMessage());
        }

        try {
            SplatHash::toBase64Url(str_repeat("\0", 15));
            self::fail('Invalid binary hash length should throw.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('SplatHash must be exactly 16 bytes.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        SplatHash::fromBase64Url('invalid');
    }

    public function testBitWriterAndReaderHandlePartialBytesAndExhaustion(): void
    {
        $writer = new BitWriter();
        $writer->write(0b101, 3);
        $writer->write(0b11110000, 8);

        $bytes = $writer->bytes();
        self::assertSame(2, strlen($bytes));

        $reader = new BitReader($bytes);
        self::assertSame(0b101, $reader->read(3));
        self::assertSame(0b11110000, $reader->read(8));
        self::assertSame(0, $reader->read(12));
    }

    public function testSplatStoresConstructorValues(): void
    {
        $splat = new Splat(0.1, 0.2, 0.35, 0.4, -0.1, 0.05, true);

        self::assertSame(0.1, $splat->x);
        self::assertSame(0.2, $splat->y);
        self::assertSame(0.35, $splat->sigma);
        self::assertSame(0.4, $splat->l);
        self::assertSame(-0.1, $splat->a);
        self::assertSame(0.05, $splat->b);
        self::assertTrue($splat->isLepton);
    }

    public function testPrivatePackingAndMathHelpers(): void
    {
        SplatHash::encodeRaw(str_repeat("\0\0\0\xff", 1), 1, 1);

        $baryons = [
            new Splat(0.0, 0.0, 0.025, -2.0, -2.0, -2.0, false),
            new Splat(0.5, 0.5, 0.1, 0.0, 0.0, 0.0, false),
            new Splat(1.0, 1.0, 0.2, 2.0, 2.0, 2.0, false),
            new Splat(0.25, 0.25, 0.35, 0.1, 0.1, 0.1, false),
        ];
        $leptons = [
            new Splat(0.0, 0.0, 0.025, -2.0, 0.0, 0.0, true),
            new Splat(0.5, 0.5, 0.1, 0.0, 0.0, 0.0, true),
            new Splat(1.0, 1.0, 0.2, 2.0, 0.0, 0.0, true),
            new Splat(0.25, 0.25, 0.35, 0.1, 0.0, 0.0, true),
        ];

        $hash = self::callPrivate('packV4', [self::callPrivate('packMean', [2.0, -2.0, 2.0]), array_merge($baryons, $leptons)]);
        self::assertSame(16, strlen($hash));
        self::assertCount(4, self::callPrivate('unpackV4', [$hash])[3]);

        $hashWithBaryonPadding = self::callPrivate('packV4', [self::callPrivate('packMean', [0.5, 0.0, 0.0]), [$baryons[1]]]);
        self::assertSame(16, strlen($hashWithBaryonPadding));

        self::assertSame([], self::callPrivate('solveChannel', [[], [], 0, 0.001]));
        self::assertSame([2.0], self::callPrivate('solveLinearSystem', [[2.0], [4.0], 1]));
        self::assertSame(0.0, self::callPrivate('cbrtFast', [-1.0]));
        self::assertSame(1.0, self::callPrivate('cbrtFast', [2.0]));
        self::assertSame(0.0, self::callPrivate('linToSrgbFast', [-1.0]));
        self::assertSame(1.0, self::callPrivate('linToSrgbFast', [2.0]));
        self::assertSame(3, self::callPrivate('sigmaIndex', [0.34]));
    }

    public function testPrivateGridHelpers(): void
    {
        SplatHash::encodeRaw(str_repeat("\xff\0\0\xff", 4), 2, 2);

        $splat = new Splat(0.5, 0.5, 0.1, 0.2, 0.1, -0.1, false);
        $map = self::callPrivate('computeBasisMap', [$splat, 4, 4]);
        self::assertCount(16, $map);

        $grid = array_fill(0, 4 * 4 * 3, 0.0);
        $arguments = [&$grid, $splat, 4, 4];
        self::callPrivateByReference('addSplatToGrid', $arguments);
        self::assertNotSame(array_fill(0, 4 * 4 * 3, 0.0), $grid);
    }

    private static function gradientRgba(int $width, int $height): string
    {
        $rgba = '';
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba .= chr($x * 32) . chr($y * 32) . chr(($x + $y) * 16) . chr(255);
            }
        }

        return $rgba;
    }

    /** @param list<mixed> $arguments */
    private static function callPrivate(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionClass(SplatHash::class);
        $reflectedMethod = $reflection->getMethod($method);

        return $reflectedMethod->invokeArgs(null, $arguments);
    }

    /** @param list<mixed> $arguments */
    private static function callPrivateByReference(string $method, array &$arguments): mixed
    {
        $reflection = new ReflectionClass(SplatHash::class);
        $reflectedMethod = $reflection->getMethod($method);

        return $reflectedMethod->invokeArgs(null, $arguments);
    }
}
