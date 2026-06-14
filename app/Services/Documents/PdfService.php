<?php

namespace App\Services\Documents;

use Smalot\PdfParser\Parser;

/**
 * Reads uploaded PDFs so the Brain can ingest them: extracts the text content
 * (PDF→markdown-ish). Pure-PHP (smalot/pdfparser), no system binaries. The main
 * use case here is bloodwork / lab-report PDFs.
 */
class PdfService
{
    /** Cap how much extracted text we feed the model (token budget). */
    public const MAX_CHARS = 12000;

    /**
     * Extract the document's text as lightly-formatted markdown.
     *
     * @return array{text:string,pages:int,truncated:bool}
     */
    public function toMarkdown(string $absPath): array
    {
        $pdf = (new Parser())->parseFile($absPath);
        $pages = count($pdf->getPages());

        $text = trim($pdf->getText());
        // Tidy the raw extraction: trim trailing spaces, collapse blank runs.
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        $truncated = false;
        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = rtrim(mb_substr($text, 0, self::MAX_CHARS));
            $truncated = true;
        }

        return ['text' => $text, 'pages' => $pages, 'truncated' => $truncated];
    }
}
