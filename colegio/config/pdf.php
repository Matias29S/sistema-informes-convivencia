<?php
/**
 * Generador de PDF liviano (sin Composer). A4, tipografías Helvetica,
 * codificación WinAnsi para caracteres españoles. Diseño administrativo.
 * Permite incrustar imágenes PNG (8 bits, sin interlazado) como XObject.
 */
class PDF {
    private array $pages = [];
    private array $images = [];
    private array $fonts = ['N' => 3, 'B' => 4, 'T' => 5, 'TB' => 6];
    private string $font = 'N';
    private float $size = 11;
    private array $stroke = [0, 0, 0];
    private array $fill = [0, 0, 0];
    private float $lineWidth = 0.6;
    private const W = 595.28;
    private const H = 841.89;

    public function addPage(): void { $this->pages[] = ''; }

    public function setFont(string $font, float $size): void {
        $this->font = isset($this->fonts[$font]) ? $font : 'N';
        $this->size = $size;
    }

    /** Color de trazo para líneas y bordes (RGB 0..1). */
    public function setStroke(float $r, float $g, float $b): void {
        $this->stroke = [$r, $g, $b];
        $this->cmd(sprintf('%.3F %.3F %.3F RG', $r, $g, $b));
    }

    /** Color de relleno; también se aplica al texto. */
    public function setFill(float $r, float $g, float $b): void {
        $this->fill = [$r, $g, $b];
        $this->cmd(sprintf('%.3F %.3F %.3F rg', $r, $g, $b));
    }

    public function setText(float $r, float $g, float $b): void { $this->setFill($r, $g, $b); }

    public function setLineWidth(float $w): void {
        $this->lineWidth = $w;
        $this->cmd(sprintf('%.2F w', $w));
    }

    /** Rectángulo relleno (coordenadas desde la esquina superior izquierda). */
    public function filledRect(float $x, float $y, float $w, float $h): void {
        $this->cmd(sprintf('%.2F %.2F %.2F %.2F re f', $x, $this->y($y + $h), $w, $h));
    }

    private function cmd(string $s): void {
        if (!$this->pages) $this->addPage();
        $this->pages[count($this->pages) - 1] .= $s . "\n";
    }

    /** Convierte UTF-8 a bytes WinAnsi y escapa para PDF. */
    private function enc(string $s): string {
        $map = [
            'á'=>0xE1,'é'=>0xE9,'í'=>0xED,'ó'=>0xF3,'ú'=>0xFA,'ñ'=>0xF1,
            'Á'=>0xC1,'É'=>0xC9,'Í'=>0xCD,'Ó'=>0xD3,'Ú'=>0xDA,'Ñ'=>0xD1,
            'ü'=>0xFC,'Ü'=>0xDC,'°'=>0xB0,'ª'=>0xAA,'º'=>0xBA,'¿'=>0xBF,'¡'=>0xA1,
            '·'=>0xB7,'•'=>0x95,
            '“'=>0x93,'”'=>0x94,'‘'=>0x92,'’'=>0x92,'–'=>0x96,'—'=>0x97,'…'=>0x85,
            '€'=>0x80,'£'=>0xA3,'¢'=>0xA2,'§'=>0xA7,'©'=>0xA9,'®'=>0xAE,'±'=>0xB1,
            '½'=>0xBD,'¼'=>0xBC,'¾'=>0xBE,'×'=>0xD7,'÷'=>0xF7,'µ'=>0xB5,
        ];
        $out = '';
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (isset($map[$ch])) $out .= chr($map[$ch]);
            elseif (strlen($ch) === 1 && ord($ch) < 128) $out .= $ch;
            else $out .= '?';
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $out);
    }

    private function y(float $top): float { return self::H - $top; }

    /** Dibuja texto. $x, $y medidos desde la esquina superior izquierda. */
    public function text(float $x, float $y, string $s): void {
        $f = $this->fonts[$this->font];
        $this->cmd("BT /F{$f} {$this->size} Tf 1 0 0 1 {$x} {$this->y($y)} Tm ({$this->enc($s)}) Tj ET");
    }

    public function textRight(float $right, float $y, string $s): void {
        $this->text($right - $this->width($s), $y, $s);
    }

    public function textCenter(float $center, float $y, string $s): void {
        $this->text($center - $this->width($s) / 2, $y, $s);
    }

    /** Anchos en milésimas (ASCII 32..126) para las cuatro tipografías embebidas. */
    private static function metrics(string $font): array {
        static $cache = [];
        if (isset($cache[$font])) return $cache[$font];
        $helvB = [
            278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,
            556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,
            975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,
            778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,
            556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,
            611,389,556,333,611,556,778,556,556,500,389,280,389,584
        ];
        $helv = [
            278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
            556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
            1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,
            778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,
            556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,
            556,333,500,278,556,500,722,500,500,500,334,260,334,584
        ];
        $timesB = [
            250,333,555,500,500,1000,833,278,333,333,500,570,250,333,250,278,
            500,500,500,500,500,500,500,500,500,500,333,333,570,570,570,500,
            930,722,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
            667,778,722,667,611,722,667,944,667,667,611,333,278,333,581,500,
            333,500,556,444,556,444,333,500,556,278,278,500,278,833,556,500,
            556,556,444,389,333,556,500,722,500,500,444,394,220,394,520
        ];
        $times = [
            250,333,408,500,500,833,778,180,333,333,500,564,250,333,250,278,
            500,500,500,500,500,500,500,500,500,500,278,278,564,564,564,444,
            921,722,667,667,722,611,556,722,722,333,389,722,611,889,722,722,
            556,722,667,556,611,722,722,944,722,722,611,333,278,333,469,500,333,
            444,500,444,500,444,333,500,500,278,278,500,278,778,500,500,500,
            500,333,389,278,500,500,722,500,500,444,480,200,480,541
        ];
        $w = match ($font) {
            'B' => $helvB,
            'T' => $times,
            'TB' => $timesB,
            default => $helv,
        };
        $m = [];
        foreach ($w as $i => $v) $m[32 + $i] = $v / 1000;
        return $cache[$font] = $m;
    }

    /** Ancho real de un texto con la tipografía y tamaño actuales. */
    public function width(string $s): float {
        $m = self::metrics($this->font);
        $base = [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U',
            'ª'=>'a','º'=>'o','°'=>'0','¿'=>'?','¡'=>'!','“'=>'"','”'=>'"',
            '‘'=>"'",'’'=>"'",'–'=>'-','—'=>'-','…'=>'.','€'=>'E','£'=>'L',
            '¢'=>'c','§'=>'S','©'=>'c','®'=>'r','±'=>'+','½'=>'0','¼'=>'0','¾'=>'0',
            '×'=>'x','÷'=>'/','µ'=>'u'
        ];
        $total = 0.0;
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (isset($base[$ch])) $ch = $base[$ch];
            $o = strlen($ch) === 1 ? ord($ch) : 0;
            $total += $m[$o] ?? 0.55;
        }
        return $total * $this->size;
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void {
        $this->cmd("{$x1} {$this->y($y1)} m {$x2} {$this->y($y2)} l S");
    }

    public function rect(float $x, float $y, float $w, float $h): void {
        $this->cmd("{$x} {$this->y($y + $h)} {$w} {$h} re S");
    }

    /** Dibuja un PNG en la posición indicada (medidas desde la esquina superior izquierda). */
    public function image(string $ruta, float $x, float $y, float $w, float $h): bool {
        $bin = @file_get_contents($ruta);
        if ($bin === false || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") return false;

        $ancho = 0; $alto = 0; $bits = 0; $color = 0; $interlace = 0;
        $idat = ''; $plte = ''; $trns = '';
        $pos = 8;
        while ($pos + 12 <= strlen($bin)) {
            $len = unpack('N', substr($bin, $pos, 4))[1];
            $tipo = substr($bin, $pos + 4, 4);
            $datos = substr($bin, $pos + 8, $len);
            if ($tipo === 'IHDR' && $len >= 13) {
                $h2 = unpack('Nw/Nh/Cb/Cc/Ccomp/Cfilt/Cinter', $datos);
                $ancho = (int)$h2['w']; $alto = (int)$h2['h'];
                $bits = (int)$h2['b']; $color = (int)$h2['c']; $interlace = (int)$h2['inter'];
            } elseif ($tipo === 'IDAT') $idat .= $datos;
            elseif ($tipo === 'PLTE') $plte = $datos;
            elseif ($tipo === 'tRNS') $trns = $datos;
            elseif ($tipo === 'IEND') break;
            $pos += 12 + $len;
        }
        if ($ancho < 1 || $alto < 1 || $bits !== 8 || $interlace !== 0) return false;
        if (!in_array($color, [0, 2, 3, 4, 6], true)) return false;

        $canales = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$color];
        $filtro = @gzuncompress($idat);
        if ($filtro === false) return false;
        $fila = $ancho * $canales;
        if (strlen($filtro) < ($fila + 1) * $alto) return false;

        $crudo = '';
        $anterior = str_repeat("\0", $fila);
        $p = 0;
        for ($row = 0; $row < $alto; $row++) {
            $f = ord($filtro[$p++]);
            $linea = substr($filtro, $p, $fila);
            $p += $fila;
            for ($i = 0; $i < $fila; $i++) {
                $a = $i >= $canales ? ord($linea[$i - $canales]) : 0;
                $b = ord($anterior[$i]);
                $c = $i >= $canales ? ord($anterior[$i - $canales]) : 0;
                $x = ord($linea[$i]);
                if ($f === 1) $x += $a;
                elseif ($f === 2) $x += $b;
                elseif ($f === 3) $x += intdiv($a + $b, 2);
                elseif ($f === 4) {
                    $pp = $a + $b - $c;
                    $pa = abs($pp - $a); $pb = abs($pp - $b); $pc = abs($pp - $c);
                    $x += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                }
                $linea[$i] = chr($x & 0xFF);
            }
            $crudo .= $linea;
            $anterior = $linea;
        }

        $rgb = ''; $alpha = '';
        for ($i = 0; $i < strlen($crudo); $i += $canales) {
            if ($color === 3) {
                $idx = ord($crudo[$i]);
                $rgb .= substr($plte, $idx * 3, 3);
                $alpha .= chr(isset($trns[$idx]) ? ord($trns[$idx]) : 255);
            } elseif ($color === 0 || $color === 4) {
                $g = chr(ord($crudo[$i]));
                $rgb .= $g . $g . $g;
                $alpha .= chr($color === 4 ? ord($crudo[$i + 1]) : 255);
            } else {
                $rgb .= substr($crudo, $i, 3);
                $alpha .= chr($color === 6 ? ord($crudo[$i + 3]) : 255);
            }
        }

        $this->images[] = [
            'w' => $ancho, 'h' => $alto,
            'rgb' => gzcompress($rgb, 9),
            'alpha' => gzcompress($alpha, 9),
        ];
        $num = count($this->images);
        $this->cmd(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q', $w, $h, $x, $this->y($y + $h), $num));
        return true;
    }

    /** Devuelve la altura usada. */
    public function paragraph(float $x, float $y, float $ancho, string $texto, float $interlinea = 0): float {
        $lineas = $this->wrap($texto, $ancho);
        $inter = $interlinea ?: $this->size * 1.35;
        foreach ($lineas as $i => $l) $this->text($x, $y + $i * $inter, $l);
        return count($lineas) * $inter;
    }

    public function wrap(string $texto, float $ancho): array {
        $palabras = preg_split('/\s+/u', trim($texto)) ?: [];
        $lineas = []; $actual = '';
        foreach ($palabras as $p) {
            $candidata = $actual === '' ? $p : $actual . ' ' . $p;
            if ($this->width($candidata) > $ancho && $actual !== '') { $lineas[] = $actual; $actual = $p; }
            else $actual = $candidata;
        }
        if ($actual !== '') $lineas[] = $actual;
        return $lineas ?: [''];
    }

    public function output(string $nombre = 'informe.pdf'): void {
        if (!$this->pages) $this->addPage();
        $objs = [];
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $kids = [];
        $n = count($this->pages);
        for ($i = 1; $i <= $n; $i++) $kids[] = (6 + $i) . " 0 R";
        // 2 = Pages, 3..6 = Helvetica, Helvetica-Bold, Times-Roman y Times-Bold,
        // 7..(6+n) = páginas, (7+n).. = contenidos, (7+2n).. = imágenes y máscaras.
        $objs[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count {$n} >>";
        $objs[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objs[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
        $objs[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>";
        $objs[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>";

        $pageObj = 7;
        $contentObj = 7 + $n;
        $imageObj = 7 + 2 * $n;
        $maskObj = $imageObj + count($this->images);

        $xobjects = [];
        foreach ($this->images as $i => $img) {
            $num = $imageObj + $i;
            $stream = $img['rgb'];
            $dict = "<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']}"
                . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length " . strlen($stream);
            if (isset($img['alpha'])) {
                $dict .= " /SMask " . ($maskObj + $i) . " 0 R";
                $mask = $img['alpha'];
                $objs[$maskObj + $i] = "<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']}"
                    . " /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length " . strlen($mask)
                    . " >>\nstream\n{$mask}\nendstream";
            }
            $objs[$num] = $dict . " >>\nstream\n{$stream}\nendstream";
            $xobjects[] = '/Im' . ($i + 1) . ' ' . $num . ' 0 R';
        }

        for ($i = 0; $i < $n; $i++) {
            $stream = $this->pages[$i];
            $objs[$contentObj + $i] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
            $recursos = '/Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R /F4 6 0 R >>';
            if ($xobjects) $recursos .= ' /XObject << ' . implode(' ', $xobjects) . ' >>';
            $objs[$pageObj + $i] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::W . " " . self::H . "] "
                . "/Resources << {$recursos} >> /Contents " . ($contentObj + $i) . " 0 R >>";
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        for ($i = 1; $i <= count($objs); $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= "{$i} 0 obj\n" . $objs[$i] . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objs); $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $nombre . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo $pdf;
        exit;
    }
}
