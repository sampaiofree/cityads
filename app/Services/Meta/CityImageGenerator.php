<?php

namespace App\Services\Meta;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CityImageGenerator
{
    public function generate(string $sourcePath, string $cityName): string
    {
        $image = $this->openImage($sourcePath);
        $width = imagesx($image);
        $text = $this->normalizeText($cityName);
        $textColor = imagecolorallocate($image, 255, 255, 255);

        $fontPath = config('meta.font_path');

        if ($fontPath && is_file($fontPath)) {
            $fontSize = $this->fitFontSize($fontPath, $text, $width);
            $textWidth = $this->measureTextWidth($fontPath, $fontSize, $text);
            $x = max(0, (int) (($width - $textWidth) / 2));
            $y = 50 + $fontSize;
            imagettftext($image, $fontSize, 0, $x, $y, $textColor, $fontPath, $text);
        } else {
            $font = 5;
            $textWidth = imagefontwidth($font) * strlen($text);
            $x = max(0, (int) (($width - $textWidth) / 2));
            imagestring($image, $font, $x, 10, $text, $textColor);
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

    private function fitFontSize(string $fontPath, string $text, int $maxWidth): int
    {
        $fontSize = 80;
        $minFontSize = 16;

        while ($fontSize > $minFontSize) {
            $textWidth = $this->measureTextWidth($fontPath, $fontSize, $text);
            if ($textWidth <= ($maxWidth - 20)) {
                break;
            }
            $fontSize--;
        }

        return $fontSize;
    }

    private function measureTextWidth(string $fontPath, int $fontSize, string $text): int
    {
        $box = imagettfbbox($fontSize, 0, $fontPath, $text);

        return (int) (($box[2] ?? 0) - ($box[0] ?? 0));
    }

    private function normalizeText(string $text): string
    {
        return function_exists('mb_strtoupper')
            ? mb_strtoupper($text, 'UTF-8')
            : strtoupper($text);
    }
}
