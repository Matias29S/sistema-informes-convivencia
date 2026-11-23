<?php
/**
 * Generador de PDF liviano (sin Composer). A4, tipografías Helvetica,
 * codificación WinAnsi para caracteres españoles. Diseño administrativo.
 */
class PDF {
    private array $pages = [];
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
        // 2 = Pages, 3 = Helvetica, 4 = Helvetica-Bold, 5..(4+n) = contenidos, (5+n).. = páginas
        $objs[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count {$n} >>";
        $objs[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objs[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $pageObj = 5;
        $contentObj = 5 + $n;
        for ($i = 0; $i < $n; $i++) {
            $stream = $this->pages[$i];
            $objs[$contentObj + $i] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
            $objs[$pageObj + $i] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::W . " " . self::H . "] "
                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents " . ($contentObj + $i) . " 0 R >>";
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
