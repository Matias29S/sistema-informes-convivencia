<?php
/** PDF mínimo: A4, Times-Roman/Times-Bold, texto, líneas y el escudo PNG. Sin dependencias. */
class PDF {
    private array $pages = [];
    private array $images = [];
    private string $font = 'T';
    private float $size = 9;
    private const W = 595.28;
    private const H = 841.89;

    public function addPage(): void { $this->pages[] = ''; }

    public function setFont(string $font, float $size): void {
        $this->font = $font === 'B' ? 'B' : 'T';
        $this->size = $size;
    }

    private function cmd(string $s): void {
        if (!$this->pages) $this->addPage();
        $this->pages[count($this->pages) - 1] .= $s . "\n";
    }

    /** Todas las posiciones se dan desde la esquina superior izquierda. */
    private function y(float $top): float { return self::H - $top; }

    /** UTF-8 a bytes WinAnsi para los caracteres que usa el acta. */
    private function enc(string $s): string {
        $map = [
            'á'=>0xE1,'é'=>0xE9,'í'=>0xED,'ó'=>0xF3,'ú'=>0xFA,'ñ'=>0xF1,
            'Á'=>0xC1,'É'=>0xC9,'Í'=>0xCD,'Ó'=>0xD3,'Ú'=>0xDA,'Ñ'=>0xD1,
            'ü'=>0xFC,'Ü'=>0xDC,'°'=>0xB0,'ª'=>0xAA,'º'=>0xBA,'¿'=>0xBF,'¡'=>0xA1,
            '·'=>0xB7,'•'=>0x95,'“'=>0x93,'”'=>0x94,'‘'=>0x92,'’'=>0x92,
            '–'=>0x96,'—'=>0x97,'…'=>0x85,'€'=>0x80,'£'=>0xA3,'¢'=>0xA2,
            '§'=>0xA7,'©'=>0xA9,'®'=>0xAE,'±'=>0xB1,'½'=>0xBD,'¼'=>0xBC,
            '¾'=>0xBE,'×'=>0xD7,'÷'=>0xF7,'µ'=>0xB5,
        ];
        $out = '';
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (isset($map[$ch])) $out .= chr($map[$ch]);
            elseif (strlen($ch) === 1 && ord($ch) < 128) $out .= $ch;
            else $out .= '?';
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $out);
    }

    /** Anchos Times en milésimas (ASCII 32..126). */
    private function metrics(): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $b = [
            250,333,555,500,500,1000,833,278,333,333,500,570,250,333,250,278,
            500,500,500,500,500,500,500,500,500,500,333,333,570,570,570,500,
            930,722,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
            667,778,722,667,611,722,667,944,667,667,611,333,278,333,581,500,
            333,500,556,444,556,444,333,500,556,278,278,500,278,833,556,500,
            556,556,444,389,333,556,500,722,500,500,444,394,220,394,520
        ];
        $n = [
            250,333,408,500,500,833,778,180,333,333,500,564,250,333,250,278,
            500,500,500,500,500,500,500,500,500,500,278,278,564,564,564,444,
            921,722,667,667,722,611,556,722,722,333,389,722,611,889,722,722,
            556,722,667,556,611,722,722,944,722,722,611,333,278,333,469,500,
            333,444,500,444,500,444,333,500,500,278,278,500,278,778,500,500,
            500,500,333,389,278,500,500,722,500,500,444,480,200,480,541
        ];
        $cache = ['T' => [], 'B' => []];
        foreach ($n as $i => $v) $cache['T'][32 + $i] = $v / 1000;
        foreach ($b as $i => $v) $cache['B'][32 + $i] = $v / 1000;
        return $cache;
    }

    public function width(string $s): float {
        $m = $this->metrics()[$this->font];
        $simple = [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U',
            'ª'=>'a','º'=>'o','°'=>'0','¿'=>'?','¡'=>'!','·'=>'.','•'=>'.',
            '“'=>'"','”'=>'"','‘'=>"'",'’'=>"'",'–'=>'-','—'=>'-','…'=>'.',
            '€'=>'E','£'=>'L','¢'=>'c','§'=>'S','©'=>'c','®'=>'r','±'=>'+',
            '½'=>'0','¼'=>'0','¾'=>'0','×'=>'x','÷'=>'/','µ'=>'u'
        ];
        $total = 0.0;
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (isset($simple[$ch])) $ch = $simple[$ch];
            $o = strlen($ch) === 1 ? ord($ch) : 0;
            $total += $m[$o] ?? 0.5;
        }
        return $total * $this->size;
    }

    public function text(float $x, float $y, string $s): void {
        $f = $this->font === 'B' ? 4 : 3;
        $this->cmd(sprintf('BT /F%d %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET',
            $f, $this->size, $x, $this->y($y), $this->enc($s)));
    }

    public function textCenter(float $center, float $y, string $s): void {
        $this->text($center - $this->width($s) / 2, $y, $s);
    }

    public function textRight(float $right, float $y, string $s): void {
        $this->text($right - $this->width($s), $y, $s);
    }

    public function fill(float $r, float $g, float $b): void {
        $this->cmd(sprintf('%.3F %.3F %.3F rg', $r, $g, $b));
    }

    public function stroke(float $r, float $g, float $b): void {
        $this->cmd(sprintf('%.3F %.3F %.3F RG', $r, $g, $b));
    }

    public function widthLine(float $w): void { $this->cmd(sprintf('%.2F w', $w)); }

    public function rect(float $x, float $y, float $w, float $h): void {
        $this->cmd(sprintf('%.2F %.2F %.2F %.2F re S', $x, $this->y($y + $h), $w, $h));
    }

    public function filledRect(float $x, float $y, float $w, float $h): void {
        $this->cmd(sprintf('%.2F %.2F %.2F %.2F re f', $x, $this->y($y + $h), $w, $h));
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void {
        $this->cmd(sprintf('%.2F %.2F m %.2F %.2F l S', $x1, $this->y($y1), $x2, $this->y($y2)));
    }

    public function wrap(string $texto, float $ancho): array {
        $palabras = preg_split('/\s+/u', trim($texto)) ?: [];
        $lineas = []; $actual = '';
        foreach ($palabras as $p) {
            $candidata = $actual === '' ? $p : $actual . ' ' . $p;
            if ($actual !== '' && $this->width($candidata) > $ancho) {
                $lineas[] = $actual; $actual = $p;
            } else $actual = $candidata;
        }
        if ($actual !== '') $lineas[] = $actual;
        return $lineas ?: [''];
    }

    /** Incrusta un PNG RGBA de 8 bits sin interlazado (el escudo). */
    public function image(string $ruta, float $x, float $y, float $w, float $h): bool {
        $bin = @file_get_contents($ruta);
        if ($bin === false || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") return false;
        $hdr = unpack('Nw/Nh/Cb/Cc/Ccomp/Cfilt/Cinter', substr($bin, 16, 13));
        if (!$hdr || $hdr['b'] !== 8 || $hdr['c'] !== 6 || $hdr['inter'] !== 0) return false;
        $iw = (int)$hdr['w']; $ih = (int)$hdr['h'];

        $idat = '';
        for ($pos = 8; $pos + 12 <= strlen($bin);) {
            $n = unpack('N', substr($bin, $pos, 4))[1];
            $tipo = substr($bin, $pos + 4, 4);
            if ($tipo === 'IDAT') $idat .= substr($bin, $pos + 8, $n);
            if ($tipo === 'IEND') break;
            $pos += 12 + $n;
        }
        $raw = @gzuncompress($idat);
        if ($raw === false) return false;

        $stride = $iw * 4;
        $prev = str_repeat("\0", $stride);
        $pixels = ''; $p = 0;
        for ($row = 0; $row < $ih; $row++) {
            $f = ord($raw[$p++]);
            $linea = substr($raw, $p, $stride);
            $p += $stride;
            for ($i = 0; $i < $stride; $i++) {
                $a = $i >= 4 ? ord($linea[$i - 4]) : 0;
                $b = ord($prev[$i]);
                $c = $i >= 4 ? ord($prev[$i - 4]) : 0;
                $v = ord($linea[$i]);
                if ($f === 1) $v += $a;
                elseif ($f === 2) $v += $b;
                elseif ($f === 3) $v += intdiv($a + $b, 2);
                elseif ($f === 4) {
                    $q = $a + $b - $c;
                    $pa = abs($q - $a); $pb = abs($q - $b); $pc = abs($q - $c);
                    $v += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                }
                $linea[$i] = chr($v & 0xFF);
            }
            $pixels .= $linea; $prev = $linea;
        }

        $rgb = ''; $alpha = '';
        for ($i = 0; $i < strlen($pixels); $i += 4) {
            $rgb .= substr($pixels, $i, 3);
            $alpha .= $pixels[$i + 3];
        }
        $rgb = gzcompress($rgb, 9);
        $alpha = gzcompress($alpha, 9);

        $num = count($this->images) + 1;
        $this->images[] = [$iw, $ih, $rgb, $alpha];
        $this->cmd(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q',
            $w, $h, $x, $this->y($y + $h), $num));
        return true;
    }

    public function output(string $nombre = 'informe.pdf'): void {
        if (!$this->pages) $this->addPage();
        $n = count($this->pages);
        $objs = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [' . implode(' ', array_map(fn($i) => (5 + $i) . ' 0 R', range(1, $n))) . "] /Count {$n} >>",
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $contentObj = 5 + $n;
        $imageObj = 5 + 2 * $n;
        $maskObj = $imageObj + count($this->images);
        $xobjects = [];

        foreach ($this->images as $i => $img) {
            [$iw, $ih, $rgb, $alpha] = $img;
            $num = $imageObj + $i;
            $dict = "<< /Type /XObject /Subtype /Image /Width {$iw} /Height {$ih}"
                . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode"
                . " /Length " . strlen($rgb) . " /SMask " . ($maskObj + $i) . " 0 R >>";
            $objs[$num] = $dict . "\nstream\n{$rgb}\nendstream";
            $objs[$maskObj + $i] = "<< /Type /XObject /Subtype /Image /Width {$iw} /Height {$ih}"
                . " /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode"
                . " /Length " . strlen($alpha) . " >>\nstream\n{$alpha}\nendstream";
            $xobjects[] = '/Im' . ($i + 1) . ' ' . $num . ' 0 R';
        }

        for ($i = 0; $i < $n; $i++) {
            $stream = $this->pages[$i];
            $objs[$contentObj + $i] = '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}endstream";
            $recursos = '/Font << /F3 3 0 R /F4 4 0 R >>';
            if ($xobjects) $recursos .= ' /XObject << ' . implode(' ', $xobjects) . ' >>';
            $objs[5 + $i] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::W . ' ' . self::H . '] '
                . "/Resources << {$recursos} >> /Contents " . ($contentObj + $i) . ' 0 R >>';
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        for ($i = 1; $i <= count($objs); $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= "{$i} 0 obj\n" . $objs[$i] . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref\n0 ' . (count($objs) + 1) . "\n0000000000 65535 f \n";
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
