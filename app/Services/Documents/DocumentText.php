<?php

namespace App\Services\Documents;

use Throwable;
use ZipArchive;

/**
 * Extracts plain text from an uploaded document so it can be ingested into the
 * Brain. PDFs go through PdfService (smalot/pdfparser); Office files (.docx/.pptx)
 * are OOXML zips we read directly -- no heavy office library. Plain text formats are
 * read as-is. Anything we can't read returns an empty string.
 */
class DocumentText
{
    /** Generous cap; the ingestor truncates further for the model. */
    public const MAX_CHARS = 40000;

    /** Extensions we can pull text out of. */
    public const SUPPORTED = ['pdf', 'docx', 'pptx', 'txt', 'md', 'markdown', 'csv', 'json', 'log', 'rtf'];

    public function __construct(protected PdfService $pdf) {}

    public function supports(string $ext): bool
    {
        return in_array(strtolower($ext), self::SUPPORTED, true);
    }

    /** Extract text from a file on disk. Returns '' when nothing can be read. */
    public function extract(string $absPath, string $ext): string
    {
        $ext = strtolower($ext);
        try {
            $text = match ($ext) {
                'pdf' => $this->pdf->toMarkdown($absPath)['text'] ?? '',
                'docx' => $this->fromDocx($absPath),
                'pptx' => $this->fromPptx($absPath),
                'rtf' => $this->fromRtf((string) file_get_contents($absPath)),
                default => (string) file_get_contents($absPath), // txt/md/csv/json/log
            };
        } catch (Throwable) {
            return '';
        }

        $text = $this->tidy((string) $text);

        return mb_strlen($text) > self::MAX_CHARS ? rtrim(mb_substr($text, 0, self::MAX_CHARS)) : $text;
    }

    /** A .docx is a zip; the body text lives in word/document.xml. */
    private function fromDocx(string $absPath): string
    {
        $xml = $this->zipEntry($absPath, 'word/document.xml');
        if ($xml === null) {
            return '';
        }
        // Paragraph/line/tab breaks → whitespace, then drop all the XML tags.
        $xml = str_replace(['</w:p>', '<w:br/>', '<w:br />', '<w:tab/>'], ["\n", "\n", "\t", "\t"], $xml);

        return $this->stripXml($xml);
    }

    /** A .pptx is a zip with one xml per slide; text is inside <a:t> runs. */
    private function fromPptx(string $absPath): string
    {
        $zip = new ZipArchive;
        if ($zip->open($absPath) !== true) {
            return '';
        }
        // Slides are ppt/slides/slide1.xml, slide2.xml, … -- keep them in order.
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                $slides[(int) $m[1]] = (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();
        ksort($slides);

        $out = [];
        foreach ($slides as $xml) {
            $xml = str_replace(['</a:p>', '</a:br>'], "\n", $xml);
            $out[] = $this->stripXml($xml);
        }

        return implode("\n\n", array_filter($out));
    }

    /** Minimal RTF → text: drop control words/groups, keep the readable bits. */
    private function fromRtf(string $rtf): string
    {
        $rtf = preg_replace('/\\\\[a-z]+-?\d* ?/i', ' ', $rtf) ?? $rtf;
        $rtf = str_replace(['{', '}', '\\'], ' ', $rtf);

        return $rtf;
    }

    private function zipEntry(string $absPath, string $entry): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($absPath) !== true) {
            return null;
        }
        $data = $zip->getFromName($entry);
        $zip->close();

        return $data === false ? null : $data;
    }

    private function stripXml(string $xml): string
    {
        $text = strip_tags($xml);

        return html_entity_decode($text, ENT_QUOTES | ENT_XML1 | ENT_HTML5, 'UTF-8');
    }

    /** Collapse runaway whitespace from extraction into clean prose. */
    private function tidy(string $text): string
    {
        $text = str_replace("\r", '', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ ?\n ?/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
