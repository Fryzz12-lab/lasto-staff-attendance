<?php
declare(strict_types=1);

$srcPath = 'C:/Users/LOQ/.cursor/projects/c-XAMP-htdocs-staff-attendance/assets/c__Users_LOQ_AppData_Roaming_Cursor_User_workspaceStorage_empty-window_images_image-592c7a81-713e-4bb3-bc2d-6dc8d374cc22.png';
$outDir = __DIR__ . '/../textures/zodiac';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

$im = imagecreatefrompng($srcPath);
if (!$im) {
    fwrite(STDERR, "Failed to load source image\n");
    exit(1);
}

$w = imagesx($im);
$h = imagesy($im);
$cols = 4;
$rows = 3;
$cw = intdiv($w, $cols);
$ch = intdiv($h, $rows);
$names = [
    ['capricorn', 'aquarius', 'scorpio', 'pisces'],
    ['aries', 'taurus', 'sagittarius', 'gemini'],
    ['cancer', 'leo', 'virgo', 'libra'],
];

$padX = (int) round($cw * 0.04);
$padY = (int) round($ch * 0.06);

for ($r = 0; $r < $rows; $r++) {
    for ($c = 0; $c < $cols; $c++) {
        $sx = $c * $cw + $padX;
        $sy = $r * $ch + $padY;
        $sw = $cw - 2 * $padX;
        $sh = $ch - 2 * $padY;

        $tile = imagecreatetruecolor($sw, $sh);
        imagealphablending($tile, false);
        imagesavealpha($tile, true);
        $transparent = imagecolorallocatealpha($tile, 0, 0, 0, 127);
        imagefilledrectangle($tile, 0, 0, $sw, $sh, $transparent);

        imagealphablending($tile, true);
        imagecopy($tile, $im, 0, 0, $sx, $sy, $sw, $sh);

        imagealphablending($tile, false);
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $rgb = imagecolorat($tile, $x, $y);
                $R = ($rgb >> 16) & 0xFF;
                $G = ($rgb >> 8) & 0xFF;
                $B = $rgb & 0xFF;
                $lum = ($R + $G + $B) / 3;
                $isBlueish = $B >= $R && $B >= ($G - 12);

                if ($lum < 88 && $isBlueish) {
                    imagesetpixel($tile, $x, $y, $transparent);
                } elseif ($lum < 125 && $isBlueish && $B > 35) {
                    $t = ($lum - 70) / 55;
                    $t = max(0, min(1, $t));
                    $alpha = (int) round(127 * (1 - $t));
                    $col = imagecolorallocatealpha($tile, min(255, $R + 20), min(255, $G + 20), min(255, $B + 20), $alpha);
                    imagesetpixel($tile, $x, $y, $col);
                }
            }
        }

        $name = $names[$r][$c];
        $path = $outDir . '/' . $name . '.png';
        imagepng($tile, $path, 6);
        imagedestroy($tile);
        echo $name . ' ' . filesize($path) . PHP_EOL;
    }
}

imagedestroy($im);
echo "done {$w}x{$h}\n";
