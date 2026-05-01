# SplatHash PHP

PHP port of [SplatHash](https://github.com/junevm/splathash): compress any image to exactly 16 bytes and reconstruct a 32x32 RGBA placeholder.

## Requirements

- PHP 8.2+
- `ext-gd` for `SplatHash::encode()` from image files or `GdImage`

## Install

```bash
composer require eislambey/splathash-php
```

## Usage

```php
<?php

use Islambey\SplathashPhp\SplatHash;

require __DIR__ . '/vendor/autoload.php';

$hash = SplatHash::encode('photo.jpg');      // 16 raw bytes
$string = SplatHash::toBase64Url($hash);     // 22-char base64url string

$sameHash = SplatHash::fromBase64Url($string);
$rgba = SplatHash::decode($sameHash);        // 32 * 32 * 4 raw RGBA bytes
```

For already decoded pixels:

```php
$hash = SplatHash::encodeRaw($rgbaBytes, $width, $height);
```

## API

- `SplatHash::encode(string|GdImage $source): string`
- `SplatHash::encodeRaw(string $rgba, int $width, int $height): string`
- `SplatHash::decode(string $hash): string`
- `SplatHash::toBase64Url(string $hash): string`
- `SplatHash::fromBase64Url(string $value): string`

## Development

```bash
composer install
php tests/run.php
```

## License

MIT
