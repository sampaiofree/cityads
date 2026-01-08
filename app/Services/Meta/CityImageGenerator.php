<?php

namespace App\Services\Meta;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CityImageGenerator
{
    public function generate(string $sourcePath, array $overlay): string
    {
        $image = $this->openImage($sourcePath);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $text = trim((string) ($overlay['text'] ?? ''));

        if ($text !== '') {
            $this->drawTextBlock($image, $text, $overlay);
        }

        Storage::disk('local')->makeDirectory('meta_ads/generated');
        $output = sprintf('meta_ads/generated/%s.png', uniqid('city_', true));
        $outputPath = Storage::disk('local')->path($output);
        imagepng($image, $outputPath);
        imagedestroy($image);

        return $output;
    }

    private function openImage(string $path)
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => imagecreatefromjpeg($path),
            'png' => imagecreatefrompng($path),
            'webp' => imagecreatefromwebp($path),
            default => throw new RuntimeException('Unsupported image type.'),
        };
    }

    private function drawTextBlock($image, string $text, array $overlay): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $padding = 2;
        $radius = 10;
        $alpha = 38;

        $fontPath = $this->resolveFontPath(config('meta.font_path'));
        $textColor = $this->allocateColor($image, (string) ($overlay['text_color'] ?? '#ffffff'));
        $backgroundColor = $this->allocateColor($image, (string) ($overlay['bg_color'] ?? '#000000'), $alpha);
        $posX = $this->normalizePercent($overlay['position_x'] ?? 50);
        $posY = $this->normalizePercent($overlay['position_y'] ?? 12);

        $lines = $this->normalizeLines($text);
        if (!$lines) {
            return;
        }

        if ($fontPath && is_file($fontPath)) {
            $maxWidth = max(0, $width - ($padding * 2));
            $maxHeight = max(0, $height - ($padding * 2));
            $fontSize = $this->fitFontSizeForLines($fontPath, $lines, $maxWidth, $maxHeight);

            [$maxLineWidth, $maxLineHeight] = $this->measureLines($fontPath, $fontSize, $lines);
            $lineHeight = $maxLineHeight + 2;
            $rectWidth = (int) ceil($maxLineWidth + ($padding * 2));
            $rectHeight = (int) ceil(($lineHeight * count($lines)) + ($padding * 2));

            [$rectX, $rectY] = $this->positionRect($width, $height, $rectWidth, $rectHeight, $posX, $posY);
            $this->drawRoundedRectangle($image, $rectX, $rectY, $rectWidth, $rectHeight, $radius, $backgroundColor);

            $textY = $rectY + $padding + $maxLineHeight;
            foreach ($lines as $line) {
                [$lineWidth] = $this->measureTextBox($fontPath, $fontSize, $line);
                $textX = $rectX + (int) round(($rectWidth - $lineWidth) / 2);
                imagettftext($image, $fontSize, 0, $textX, (int) $textY, $textColor, $fontPath, $line);
                $textY += $lineHeight;
            }

            return;
        }

        $font = 5;
        $maxWidth = max(0, $width - ($padding * 2));
        $maxLineWidth = $this->measureBitmapLines($font, $lines);
        while ($font > 1 && $maxLineWidth > $maxWidth) {
            $font--;
            $maxLineWidth = $this->measureBitmapLines($font, $lines);
        }

        $lineHeight = imagefontheight($font) + 2;
        $rectWidth = (int) ceil($maxLineWidth + ($padding * 2));
        $rectHeight = (int) ceil(($lineHeight * count($lines)) + ($padding * 2));

        [$rectX, $rectY] = $this->positionRect($width, $height, $rectWidth, $rectHeight, $posX, $posY);
        $this->drawRoundedRectangle($image, $rectX, $rectY, $rectWidth, $rectHeight, $radius, $backgroundColor);

        $textY = $rectY + $padding;
        foreach ($lines as $line) {
            $lineWidth = imagefontwidth($font) * strlen($line);
            $textX = $rectX + (int) round(($rectWidth - $lineWidth) / 2);
            imagestring($image, $font, $textX, $textY, $line, $textColor);
            $textY += $lineHeight;
        }
    }

    private function normalizeLines(string $text): array
    {
        $line = preg_replace('/\s+/', ' ', $text) ?? '';
        $line = trim($line);

        return $line === '' ? [] : [$line];
    }

    private function fitFontSizeForLines(string $fontPath, array $lines, int $maxWidth, int $maxHeight): int
    {
        $fontSize = 180;
        $minFontSize = 8;

        while ($fontSize > $minFontSize) {
            [$lineWidth, $lineHeight] = $this->measureLines($fontPath, $fontSize, $lines);
            $totalHeight = $lineHeight * count($lines);

            if ($lineWidth <= $maxWidth && $totalHeight <= $maxHeight) {
                break;
            }
            $fontSize--;
        }

        return $fontSize;
    }

    private function measureLines(string $fontPath, int $fontSize, array $lines): array
    {
        $maxWidth = 0;
        $maxHeight = 0;

        foreach ($lines as $line) {
            [$width, $height] = $this->measureTextBox($fontPath, $fontSize, $line);
            $maxWidth = max($maxWidth, $width);
            $maxHeight = max($maxHeight, $height);
        }

        return [$maxWidth, $maxHeight];
    }

    private function measureTextBox(string $fontPath, int $fontSize, string $text): array
    {
        $box = imagettfbbox($fontSize, 0, $fontPath, $text);
        $width = abs(($box[2] ?? 0) - ($box[0] ?? 0));
        $height = abs(($box[7] ?? 0) - ($box[1] ?? 0));

        return [$width, $height];
    }

    private function measureBitmapLines(int $font, array $lines): int
    {
        $maxWidth = 0;
        foreach ($lines as $line) {
            $maxWidth = max($maxWidth, imagefontwidth($font) * strlen($line));
        }

        return $maxWidth;
    }

    private function positionRect(int $imageWidth, int $imageHeight, int $rectWidth, int $rectHeight, float $posX, float $posY): array
    {
        $centerX = $imageWidth * ($posX / 100);
        $centerY = $imageHeight * ($posY / 100);

        $x = (int) round($centerX - ($rectWidth / 2));
        $y = (int) round($centerY - ($rectHeight / 2));

        $x = (int) $this->clamp($x, 0, max(0, $imageWidth - $rectWidth));
        $y = (int) $this->clamp($y, 0, max(0, $imageHeight - $rectHeight));

        return [$x, $y];
    }

    private function drawRoundedRectangle($image, int $x, int $y, int $width, int $height, int $radius, int $color): void
    {
        $radius = (int) min($radius, $width / 2, $height / 2);

        imagefilledrectangle($image, $x + $radius, $y, $x + $width - $radius, $y + $height, $color);
        imagefilledrectangle($image, $x, $y + $radius, $x + $width, $y + $height - $radius, $color);

        imagefilledellipse($image, $x + $radius, $y + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x + $width - $radius, $y + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x + $radius, $y + $height - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x + $width - $radius, $y + $height - $radius, $radius * 2, $radius * 2, $color);
    }

    private function allocateColor($image, string $hex, int $alpha = 0): int
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6) {
            $hex = '000000';
        }

        $int = hexdec($hex);
        $r = ($int >> 16) & 255;
        $g = ($int >> 8) & 255;
        $b = $int & 255;

        $alpha = $this->clamp($alpha, 0, 127);

        return imagecolorallocatealpha($image, $r, $g, $b, $alpha);
    }

    private function normalizePercent($value): float
    {
        $value = is_numeric($value) ? (float) $value : 0.0;

        return (float) $this->clamp($value, 0, 100);
    }

    private function clamp($value, $min, $max)
    {
        return max($min, min($value, $max));
    }

    private function resolveFontPath(?string $configuredPath): ?string
    {
        $candidates = array_filter([
            $configuredPath,
            resource_path('fonts/meta-ads-bold.ttf'),
            base_path('resources/fonts/meta-ads-bold.ttf'),
            'C:\\Windows\\Fonts\\arialbd.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ]);

        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
