<?php
/**
 * ============================================================
 * app/Pacs/Mock/Generators/Generic/GenericPlaceholder.php — 通用影像占位生成器
 * ============================================================
 * 用于「CT / DR / MR / US」以外的其他检查模态（如 OT）：生成多帧、
 * 无解剖含义但直观好看的抽象动画影像，避免冒充真实解剖造成误导，同时
 * 保证多帧序列可正常测试（翻帧 / 滚动 / 导出）。
 *
 * 画面：暗底 + 旋转雷达扫掠 + 外扩同心环 + 网格 + 中心脉冲；
 * 每帧以伪随机位置叠加检查项目名称（中文）与「PLACEHOLDER · 模态」水印。
 * 水印文字经 GD 预渲染为蒙版后按亮/暗叠加到 16 位像素缓冲，需 GD + TTF 字体；
 * 两者不可用时退化为无文字动画（不报错）。
 * ============================================================ */
class PvMockGeneric extends PvMockAbstractGenerator {

    /** 检查项目名称（用于画面水印；由调度器经 setContext 注入） */
    protected $label = '';

    public function __construct($seed = 'mock', $weight = 'T1') {
        $this->seed = (string)$seed;
        $this->configure(array(
            'modality' => 'OT', 'orientation' => 'OT',
            'seriesDescription' => '通用占位序列', 'frameCount' => 24,
            'rows' => 512, 'cols' => 512,
            'sliceThickness' => 0.0, 'spacingBetweenSlices' => 0.0,
            'rowSpacing' => 0.7, 'colSpacing' => 0.7,
            'rescaleIntercept' => 0.0, 'rescaleSlope' => 1.0,
            'windowCenter' => 2048.0, 'windowWidth' => 4096.0,
            'noise' => $this->seed . '|generic',
        ));
    }

    /** 占位影像非 HU 值域，供序列元数据与前端展示区分 */
    public function isHU() { return false; }

    /** 注入模态与检查项目名称（由 PvMockDispatcher::createGenerator 调用） */
    public function setContext($modality, $description) {
        $m = strtoupper(trim((string)$modality));
        if ($m !== '') $this->modality = $m;
        $this->label = trim((string)$description);
    }

    /* ---------------- 动画图案（每帧相位推进） ---------------- */
    protected function sample($nx, $ny, $p, $x, $y, $i) {
        $dx = $nx - 0.5; $dy = $ny - 0.5;
        $r = sqrt($dx * $dx + $dy * $dy);
        $ang = atan2($dy, $dx);

        // 暗底 + 径向渐晕
        $v = 520.0 * exp(-2.6 * $r);

        // 外扩同心环（随帧向外移动）
        $v += 260.0 * sin($r * 27.0 - $p * 13.0) * exp(-2.4 * $r);

        // 旋转雷达扫掠（每帧转两圈）
        $sweep = 0.5 + 0.5 * cos($ang + $p * 4.0 * M_PI - $r * 6.0);
        $v += 1100.0 * pow($sweep, 9) * exp(-1.5 * $r);

        // 细网格
        $gx = abs((($nx * 18.0) - floor($nx * 18.0)) - 0.5);
        $gy = abs((($ny * 18.0) - floor($ny * 18.0)) - 0.5);
        if ($gx > 0.485 || $gy > 0.485) $v += 130.0;

        // 中心脉冲（呼吸感）
        $rr = 0.10 + 0.30 * (0.5 + 0.5 * sin($p * 2.0 * M_PI));
        $v += 1700.0 * exp(-pow($r - $rr, 2) / 0.0016);

        // 边缘取景框
        if ($nx < 0.012 || $nx > 0.988 || $ny < 0.012 || $ny > 0.988) $v += 700.0;

        return $v < 0 ? 0.0 : ($v > 4095.0 ? 4095.0 : $v);
    }

    /** 在动画帧上叠加水印文字 */
    public function generateFrame($frameIndex, $totalFrames = null) {
        $bin = parent::generateFrame($frameIndex, $totalFrames);
        return $this->overlayWatermark($bin, (int)$frameIndex);
    }

    /** 将检查项目名称 + «PLACEHOLDER · 模态» 以伪随机位置叠加到 16 位像素缓冲 */
    private function overlayWatermark($bin, $frameIndex) {
        if (!function_exists('imagettftext') || !function_exists('imagecreatetruecolor')) return $bin;
        $font = self::findFont();
        if ($font === '') return $bin;

        $w = (int)$this->cols; $h = (int)$this->rows;
        $lines = array();
        if ($this->label !== '') $lines[] = $this->label;
        $lines[] = 'PLACEHOLDER · ' . ($this->modality !== '' ? $this->modality : 'OT');

        $size = 26; $lh = 36; $pad = 12;
        $widths = array(); $tw = 0;
        foreach ($lines as $ln) {
            $b = @imagettfbbox($size, 0, $font, $ln);
            $lw = $b ? max(abs($b[2] - $b[0]), abs($b[0] - $b[2])) : 0;
            $widths[] = $lw;
            if ($lw > $tw) $tw = $lw;
        }
        $th = $lh * count($lines);

        // 每帧伪随机位置（确定性）
        $rng = PvMockProceduralNoise::rng(PvMockProceduralNoise::seed($this->seed . '|wm|' . $frameIndex));
        $margin = 26;
        $maxX = max($margin, $w - $tw - $margin);
        $maxY = max($margin, $h - $th - $margin);
        $bx = (int)round($margin + $rng() * max(1, $maxX - $margin));
        $by = (int)round($margin + $rng() * max(1, $maxY - $margin));

        // 预渲染文字蒙版：白色字芯 + 深色描边
        $mask = imagecreatetruecolor($w, $h);
        if (!$mask) return $bin;
        $black = imagecolorallocate($mask, 0, 0, 0);
        imagefilledrectangle($mask, 0, 0, $w, $h, $black);
        $outline = imagecolorallocate($mask, 120, 120, 120);
        $white = imagecolorallocate($mask, 255, 255, 255);
        foreach ($lines as $k => $ln) {
            $tx = $bx; $ty = $by + 28 + $k * $lh;
            foreach (array(array(-1, 0), array(1, 0), array(0, -1), array(0, 1)) as $d) {
                @imagettftext($mask, $size, 0, $tx + $d[0], $ty + $d[1], $outline, $font, $ln);
            }
            @imagettftext($mask, $size, 0, $tx, $ty, $white, $font, $ln);
        }

        // 仅处理文字包围盒区域，叠加到 16 位缓冲
        $x0 = max(0, $bx - $pad); $y0 = max(0, $by - $pad);
        $x1 = min($w - 1, $bx + $tw + $pad); $y1 = min($h - 1, $by + $th + $pad);
        for ($yy = $y0; $yy <= $y1; $yy++) {
            for ($xx = $x0; $xx <= $x1; $xx++) {
                $rgb = imagecolorat($mask, $xx, $yy);
                $a = ($rgb >> 16) & 0xFF;                 // 红色通道作为不透明度
                if ($a <= 0) continue;
                $idx = ($yy * $w + $xx) * 2;
                $cur = ord($bin[$idx]) | (ord($bin[$idx + 1]) << 8);
                $tint = ($a >= 200) ? 3950.0 : 180.0;      // 字芯亮、描边暗
                $nv = (int)round($cur * (1.0 - $a / 255.0) + $tint * ($a / 255.0));
                if ($nv > 65535) $nv = 65535;
                $bin[$idx] = chr($nv & 0xFF);
                $bin[$idx + 1] = chr(($nv >> 8) & 0xFF);
            }
        }
        if (PHP_VERSION_ID < 80500) imagedestroy($mask);
        return $bin;
    }

    /** 查找可用的中文字体（缓存；优先常见的跨平台中文字体） */
    private static function findFont() {
        static $font = null;
        if ($font !== null) return $font;
        $cands = array(
            '/System/Library/Fonts/PingFang.ttc',
            '/System/Library/Fonts/Hiragino Sans GB.ttc',
            '/System/Library/Fonts/STHeiti Medium.ttc',
            '/Library/Fonts/Arial Unicode.ttf',
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJKsc-Regular.otf',
            '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
            '/usr/share/fonts/truetype/arphic/uming.ttc',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            'C:/Windows/Fonts/msyh.ttc',
            'C:/Windows/Fonts/simhei.ttf',
        );
        $font = '';
        foreach ($cands as $f) {
            if (!is_file($f)) continue;
            $b = @imagettfbbox(12, 0, $f, 'A');
            if ($b !== false) { $font = $f; break; }
        }
        return $font;
    }
}
