<?php
/**
 * Generador de PDF liviano (sin Composer). A4, tipografías Helvetica,
 * codificación WinAnsi para caracteres españoles. Diseño administrativo.
 * Permite incrustar imágenes PNG (8 bits, sin interlazado) como XObject.
 */
class PDF {
    private array $pages = [];
    private array $images = [];
    private array $fonts = ['N' => 3, 'B' => 4];
    private string $font = 'N';
    private float $size = 11;
    private const W = 595.28;
    private const H = 841.89;

    public function addPage(): void { $this->pages[] = ''; }

    public function setFont(string $font, float $size): void {
        $this->font = isset($this->fonts[$font]) ? $font : 'N';
        $this->size = $size;
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

    public function width(string $s): float {
        return mb_strlen($s, 'UTF-8') * $this->size * 0.5;
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
        for ($i = 1; $i <= $n; $i++) $kids[] = (4 + $i) . " 0 R";
        // 2 = Pages, 3 = Helvetica, 4 = Helvetica-Bold, 5..(4+n) = páginas,
        // (5+n).. = contenidos, (5+2n).. = imágenes y máscaras de transparencia.
        $objs[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count {$n} >>";
        $objs[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objs[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $pageObj = 5;
        $contentObj = 5 + $n;
        $imageObj = 5 + 2 * $n;
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
            $recursos = '/Font << /F1 3 0 R /F2 4 0 R >>';
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
