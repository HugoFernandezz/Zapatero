<?php

declare(strict_types=1);

namespace App\BackOffice;

/** Gráficos SVG generados en el servidor (sin JavaScript ni librerías externas). Todo el texto se escapa. */
final class Charts
{
    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Barras verticales (p. ej. pedidos por día).
     *
     * @param list<string> $labels
     * @param list<int|float> $values
     */
    public static function verticalBars(array $labels, array $values, string $ariaLabel, string $yTitle = ''): string
    {
        $n = count($values);
        $w = 760;
        $h = 270;
        $left = 44;
        $right = 10;
        $top = 20;
        $bottom = 48;
        $plotW = $w - $left - $right;
        $plotH = $h - $top - $bottom;
        $max = max(1, $n > 0 ? (int) ceil(max($values)) : 1);
        $step = max(1, (int) ceil($max / 4));
        $axisMax = $step * (int) ceil($max / $step);
        $slot = $n > 0 ? $plotW / $n : $plotW;
        $barW = max(2.0, $slot * 0.68);
        $every = $n <= 16 ? 1 : (int) ceil($n / 12);

        $svg = '<svg role="img" viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:auto;max-width:100%" aria-label="' . self::e($ariaLabel) . '">';
        $svg .= '<title>' . self::e($ariaLabel) . '</title>';
        for ($v = 0; $v <= $axisMax; $v += $step) {
            $y = $top + $plotH - ($v / $axisMax) * $plotH;
            $svg .= '<line x1="' . $left . '" y1="' . round($y, 1) . '" x2="' . ($w - $right) . '" y2="' . round($y, 1) . '" stroke="#ded8ce" stroke-width="1"/>';
            $svg .= '<text x="' . ($left - 6) . '" y="' . round($y + 4, 1) . '" text-anchor="end" font-size="11" fill="#5f6368">' . $v . '</text>';
        }
        foreach ($values as $i => $val) {
            $bh = $axisMax > 0 ? ($val / $axisMax) * $plotH : 0;
            $x = $left + $i * $slot + ($slot - $barW) / 2;
            $y = $top + $plotH - $bh;
            $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($barW, 1) . '" height="' . round($bh, 1) . '" rx="2" fill="#006d77"><title>' . self::e($labels[$i] ?? '') . ': ' . self::e($val) . '</title></rect>';
            if ($val > 0 && $n <= 20) {
                $svg .= '<text x="' . round($x + $barW / 2, 1) . '" y="' . round($y - 4, 1) . '" text-anchor="middle" font-size="11" font-weight="700" fill="#171717">' . self::e($val) . '</text>';
            }
            if ($i % $every === 0) {
                $svg .= '<text x="' . round($x + $barW / 2, 1) . '" y="' . ($top + $plotH + 14) . '" text-anchor="end" font-size="10" fill="#5f6368" transform="rotate(-40 ' . round($x + $barW / 2, 1) . ' ' . ($top + $plotH + 14) . ')">' . self::e(substr((string) ($labels[$i] ?? ''), 5)) . '</text>';
            }
        }
        if ($yTitle !== '') {
            $svg .= '<text x="12" y="' . ($top + $plotH / 2) . '" text-anchor="middle" font-size="11" fill="#5f6368" transform="rotate(-90 12 ' . ($top + $plotH / 2) . ')">' . self::e($yTitle) . '</text>';
        }

        return $svg . '</svg>';
    }

    /**
     * Barras horizontales (estados, productos, embudo, errores).
     *
     * @param array<string, int|float> $items etiqueta => valor
     */
    public static function horizontalBars(array $items, string $ariaLabel, string $color = '#006d77', string $suffix = ''): string
    {
        $rowH = 30;
        $w = 640;
        $labelW = 210;
        $valueW = 70;
        $n = max(1, count($items));
        $h = $n * $rowH + 10;
        $max = max(1.0, $items === [] ? 1.0 : (float) max($items));
        $barMax = $w - $labelW - $valueW;

        $svg = '<svg role="img" viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:auto;max-width:100%" aria-label="' . self::e($ariaLabel) . '">';
        $svg .= '<title>' . self::e($ariaLabel) . '</title>';
        $i = 0;
        foreach ($items as $label => $value) {
            $y = 5 + $i * $rowH;
            $bw = ($value / $max) * $barMax;
            $text = mb_strlen((string) $label) > 30 ? mb_substr((string) $label, 0, 29) . '…' : (string) $label;
            $svg .= '<text x="' . ($labelW - 8) . '" y="' . ($y + 18) . '" text-anchor="end" font-size="12" fill="#171717">' . self::e($text) . '</text>';
            $svg .= '<rect x="' . $labelW . '" y="' . ($y + 4) . '" width="' . round(max(0.0, $bw), 1) . '" height="' . ($rowH - 12) . '" rx="3" fill="' . self::e($color) . '"><title>' . self::e($label) . ': ' . self::e($value) . '</title></rect>';
            $svg .= '<text x="' . round($labelW + max(0.0, $bw) + 6, 1) . '" y="' . ($y + 18) . '" font-size="12" font-weight="700" fill="#171717">' . self::e($value) . self::e($suffix) . '</text>';
            $i++;
        }

        return $svg . '</svg>';
    }
}
