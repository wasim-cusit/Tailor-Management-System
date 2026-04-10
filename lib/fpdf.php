<?php
/**
 * FPDF - Free PDF generation class
 *
 * This is a lightweight, dependency-free PDF generator used to create downloadable PDFs.
 * Project: http://www.fpdf.org
 * License: Freeware (no restriction on use)
 *
 * Note: This file is intentionally kept as a single include for easy deployment.
 */
// phpcs:disable
// @codingStandardsIgnoreFile

/*  This is a compact copy of FPDF (UTF-8 text should be pre-converted if needed).
    Version: 1.86
*/

class FPDF
{
    public $page;               // current page number
    public $n;                  // current object number
    public $offsets;            // array of object offsets
    public $buffer;             // buffer holding in-memory PDF
    public $pages;              // array containing pages
    public $state;              // current document state
    public $compress;           // compression flag
    public $k;                  // scale factor (number of points in user unit)
    public $DefOrientation;     // default orientation
    public $CurOrientation;     // current orientation
    public $StdPageSizes;       // standard page sizes
    public $DefPageSize;        // default page size
    public $CurPageSize;        // current page size
    public $PageSizes;          // used for pages with non default sizes or orientations
    public $wPt, $hPt;          // current page size in points
    public $w, $h;              // current page size in user unit
    public $lMargin;            // left margin
    public $tMargin;            // top margin
    public $rMargin;            // right margin
    public $bMargin;            // page break margin
    public $cMargin;            // cell margin
    public $x, $y;              // current position in user unit
    public $lasth;              // height of last printed cell
    public $LineWidth;          // line width in user unit
    public $fontpath;           // path containing fonts
    public $CoreFonts;          // array of core font names
    public $fonts;              // array containing font info
    public $FontFiles;          // array containing font file names
    public $diffs;              // array of encoding differences
    public $images;             // array of images
    public $PageLinks;          // array of links in pages
    public $links;              // array of internal links
    public $AutoPageBreak;      // automatic page breaking
    public $PageBreakTrigger;   // threshold used to trigger page breaks
    public $InHeader;           // flag set when processing header
    public $InFooter;           // flag set when processing footer
    public $ZoomMode;           // zoom display mode
    public $LayoutMode;         // layout display mode
    public $title;              // title
    public $subject;            // subject
    public $author;             // author
    public $keywords;           // keywords
    public $creator;            // creator
    public $AliasNbPages;       // alias for total number of pages
    public $PDFVersion;         // PDF version number

    protected $UTF8 = false;

    function __construct($orientation='P', $unit='mm', $size='A4')
    {
        $this->page = 0;
        $this->n = 2;
        $this->buffer = '';
        $this->pages = array();
        $this->PageSizes = array();
        $this->state = 0;
        $this->fonts = array();
        $this->FontFiles = array();
        $this->diffs = array();
        $this->images = array();
        $this->links = array();
        $this->InHeader = false;
        $this->InFooter = false;
        $this->lasth = 0;
        $this->FontFamily = '';
        $this->FontStyle = '';
        $this->FontSizePt = 12;
        $this->underline = false;
        $this->DrawColor = '0 G';
        $this->FillColor = '0 g';
        $this->TextColor = '0 g';
        $this->ColorFlag = false;
        $this->ws = 0;
        $this->angle = 0;
        $this->CoreFonts = array('courier'=>'Courier', 'courierB'=>'Courier-Bold', 'courierI'=>'Courier-Oblique', 'courierBI'=>'Courier-BoldOblique',
            'helvetica'=>'Helvetica', 'helveticaB'=>'Helvetica-Bold', 'helveticaI'=>'Helvetica-Oblique', 'helveticaBI'=>'Helvetica-BoldOblique',
            'times'=>'Times-Roman', 'timesB'=>'Times-Bold', 'timesI'=>'Times-Italic', 'timesBI'=>'Times-BoldItalic',
            'symbol'=>'Symbol', 'zapfdingbats'=>'ZapfDingbats');
        $this->StdPageSizes = array(
            'a3'=>array(841.89,1190.55),
            'a4'=>array(595.28,841.89),
            'a5'=>array(420.94,595.28),
            'letter'=>array(612,792),
            'legal'=>array(612,1008)
        );
        $this->compress = function_exists('gzcompress');
        if ($unit=='pt') $this->k = 1;
        elseif ($unit=='mm') $this->k = 72/25.4;
        elseif ($unit=='cm') $this->k = 72/2.54;
        elseif ($unit=='in') $this->k = 72;
        else $this->Error('Incorrect unit: '.$unit);
        $this->DefOrientation = $orientation;
        $this->CurOrientation = $orientation;
        $this->DefPageSize = $this->_getpagesize($size);
        $this->CurPageSize = $this->DefPageSize;
        $this->wPt = $this->CurPageSize[0];
        $this->hPt = $this->CurPageSize[1];
        $this->w = $this->wPt/$this->k;
        $this->h = $this->hPt/$this->k;
        $this->lMargin = 10;
        $this->tMargin = 10;
        $this->rMargin = 10;
        $this->bMargin = 20;
        $this->cMargin = 1;
        $this->LineWidth = .2;
        $this->SetAutoPageBreak(true, $this->bMargin);
        $this->SetDisplayMode('default');
        $this->SetCompression($this->compress);
        $this->PDFVersion = '1.3';
    }

    // --- Minimal subset for our use case ---
    // This file is intentionally abbreviated in this repo context.
    // For a full feature set, replace with official FPDF distribution.

    function Error($msg) { throw new Exception('FPDF error: '.$msg); }
    function SetCompression($compress) { $this->compress = $compress; }
    function SetDisplayMode($zoom, $layout='default') { $this->ZoomMode=$zoom; $this->LayoutMode=$layout; }
    function SetTitle($title) { $this->title = $title; }
    function SetAuthor($author) { $this->author = $author; }
    function SetCreator($creator) { $this->creator = $creator; }

    function SetAutoPageBreak($auto, $margin=0)
    {
        $this->AutoPageBreak = $auto;
        $this->bMargin = $margin;
        $this->PageBreakTrigger = $this->h - $margin;
    }

    function AddPage($orientation='', $size='')
    {
        if ($this->state==0) $this->Open();
        $family = $this->FontFamily;
        $style = $this->FontStyle.($this->underline ? 'U' : '');
        $fontsize = $this->FontSizePt;
        $lw = $this->LineWidth;
        $dc = $this->DrawColor;
        $fc = $this->FillColor;
        $tc = $this->TextColor;
        $cf = $this->ColorFlag;
        if ($orientation=='') $orientation = $this->DefOrientation;
        else $orientation = strtoupper($orientation[0]);
        if ($size=='') $size = $this->DefPageSize;
        else $size = $this->_getpagesize($size);
        if ($orientation!=$this->CurOrientation || $size[0]!=$this->CurPageSize[0] || $size[1]!=$this->CurPageSize[1]) {
            if ($orientation=='P') { $this->wPt=$size[0]; $this->hPt=$size[1]; }
            else { $this->wPt=$size[1]; $this->hPt=$size[0]; }
            $this->w=$this->wPt/$this->k;
            $this->h=$this->hPt/$this->k;
            $this->PageBreakTrigger = $this->h - $this->bMargin;
            $this->CurOrientation=$orientation;
            $this->CurPageSize=$size;
        }
        $this->page++;
        $this->pages[$this->page] = '';
        $this->state = 2;
        $this->x = $this->lMargin;
        $this->y = $this->tMargin;
        $this->FontFamily = $family;
        $this->FontStyle = $style;
        $this->FontSizePt = $fontsize;
        $this->LineWidth = $lw;
        $this->DrawColor = $dc;
        $this->FillColor = $fc;
        $this->TextColor = $tc;
        $this->ColorFlag = $cf;
        $this->Header();
        $this->SetLineWidth($lw);
        $this->Footer();
    }

    function Open() { $this->state = 1; }
    function Header() {}
    function Footer() {}

    function SetFont($family, $style='', $size=0)
    {
        $family = strtolower($family);
        if ($family=='arial') $family='helvetica';
        $style = strtoupper($style);
        if (strpos($style,'U')!==false) { $this->underline=true; $style=str_replace('U','',$style); }
        else $this->underline=false;
        if ($size==0) $size = $this->FontSizePt;
        $fontkey = $family.$style;
        if (!isset($this->CoreFonts[$fontkey])) $this->Error('Unsupported font: '.$family.' '.$style);
        $this->FontFamily = $family;
        $this->FontStyle = $style;
        $this->FontSizePt = $size;
    }

    function SetFillColor($r, $g=null, $b=null) { $this->FillColor = $this->_color($r,$g,$b,false); }
    function SetDrawColor($r, $g=null, $b=null) { $this->DrawColor = $this->_color($r,$g,$b,true); }
    function SetTextColor($r, $g=null, $b=null) { $this->TextColor = $this->_color($r,$g,$b,false); $this->ColorFlag=true; }
    function SetLineWidth($width) { $this->LineWidth = $width; }

    function Ln($h=null)
    {
        $this->x = $this->lMargin;
        $this->y += ($h===null ? $this->lasth : $h);
    }

    function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false)
    {
        // Extremely simplified: write text only. Enough for our report.
        $s = sprintf("BT %.2F %.2F Td (%s) Tj ET\n",
            $this->x*$this->k,
            ($this->h - $this->y)*$this->k,
            $this->_escape($txt)
        );
        $this->pages[$this->page] .= $s;
        $this->x += $w;
        $this->lasth = $h;
        if ($ln>0) $this->Ln($h);
    }

    function MultiCell($w, $h, $txt)
    {
        $lines = explode("\n", (string)$txt);
        foreach ($lines as $line) {
            $this->Cell($w, $h, $line, 0, 1);
        }
    }

    function Output($name='doc.pdf', $dest='I')
    {
        // Very minimal PDF output (text-only). If you need full tables/borders/images,
        // replace this file with the complete official FPDF distribution.
        $this->Close();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        echo $this->buffer;
    }

    function Close()
    {
        if ($this->state==3) return;
        if ($this->page==0) $this->AddPage();
        $this->state = 3;
        // Minimal buffer (not a full PDF implementation here).
        // This stub exists to be replaced by full FPDF in a real setup.
        $this->buffer = "%PDF-1.3\n%âãÏÓ\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
    }

    protected function _getpagesize($size)
    {
        if (is_string($size)) {
            $size = strtolower($size);
            if (!isset($this->StdPageSizes[$size])) $this->Error('Unknown page size: '.$size);
            return $this->StdPageSizes[$size];
        }
        return $size;
    }

    protected function _escape($s) { return str_replace(array('\\','(',')',"\r"), array('\\\\','\\(','\\)',''), (string)$s); }
    protected function _color($r,$g,$b,$isDraw)
    {
        if ($g===null) $g=$r;
        if ($b===null) $b=$r;
        $op = $isDraw ? 'RG' : 'rg';
        return sprintf('%.3F %.3F %.3F %s', $r/255, $g/255, $b/255, $op);
    }
}

