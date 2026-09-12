<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Reporting\Pdf;

use RuntimeException;

/**
 * Adds an XMP metadata packet to a rendered PDF, as an incremental update.
 *
 * PDF/UA requires the catalog to carry a `/Metadata` stream, and Chrome does
 * not write one. Without it the report is a properly tagged PDF that still
 * fails validation on clause 7.1-8.
 *
 * An incremental update rather than a rewrite: the original bytes are left
 * untouched and two objects plus a new cross-reference section are appended.
 * Nothing already in the file can be broken by something that only adds to the
 * end of it. Framework-free and pure over bytes, so a test can hand it a
 * small PDF.
 */
final class PdfUaMetadata
{
    public function addTo(string $path, string $title, string $language = 'en'): void
    {
        $pdf = @file_get_contents($path);

        if ($pdf === false || $pdf === '') {
            throw new RuntimeException("There is no PDF at $path to add metadata to.");
        }
        if (str_contains($pdf, '/Metadata')) {
            return;
        }

        // Only classic cross-reference tables. A renderer that writes xref
        // streams needs a different incremental update, and producing an
        // invalid one would be worse than leaving the file as it is.
        if (preg_match('/trailer\s*<<(.*?)>>\s*startxref\s+(\d+)/s', $pdf, $trailer) !== 1) {
            return;
        }

        $previous = (int) $trailer[2];

        if (preg_match('/\/Size\s+(\d+)/', $trailer[1], $size) !== 1
            || preg_match('/\/Root\s+(\d+)\s+\d+\s+R/', $trailer[1], $root) !== 1) {
            return;
        }

        $rootNumber = (int) $root[1];

        // The catalog has to be readable as text to be copied. Inside an object
        // stream it is not, and this stops rather than guesses.
        if (preg_match('/[\r\n]'.$rootNumber.' 0 obj\s*(<<.*?>>)\s*endobj/s', $pdf, $catalog) !== 1) {
            return;
        }

        $metadataNumber = (int) $size[1];
        $packet = $this->packet($title, $language);

        $output = rtrim($pdf, "\r\n")."\n";

        $metadataOffset = strlen($output);
        $output .= "$metadataNumber 0 obj\n"
            .'<< /Type /Metadata /Subtype /XML /Length '.strlen($packet)." >>\n"
            ."stream\n$packet\nendstream\nendobj\n";

        $catalogOffset = strlen($output);
        $output .= "$rootNumber 0 obj\n"
            .preg_replace('/>>\s*$/', " /Metadata $metadataNumber 0 R >>", $catalog[1], 1)
            ."\nendobj\n";

        $xrefOffset = strlen($output);
        $entries = [$rootNumber => $catalogOffset, $metadataNumber => $metadataOffset];
        ksort($entries);

        $output .= "xref\n";
        foreach ($entries as $number => $offset) {
            $output .= "$number 1\n".sprintf("%010d 00000 n \n", $offset);
        }

        $output .= 'trailer'."\n"
            .'<< /Size '.((int) $size[1] + 1)." /Root $rootNumber 0 R /Prev $previous >>\n"
            ."startxref\n$xrefOffset\n%%EOF\n";

        file_put_contents($path, $output);
    }

    /**
     * The packet itself.
     *
     * `pdfuaid:part` claims PDF/UA-1 conformance, which is a claim worth making
     * only because it is checked: the test for this runs the finished file
     * through veraPDF wherever it is installed and refuses anything less than
     * clean.
     */
    private function packet(string $title, string $language): string
    {
        $title = htmlspecialchars($title, ENT_XML1);

        return "<?xpacket begin=\"\u{FEFF}\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n"
            ."<x:xmpmeta xmlns:x=\"adobe:ns:meta/\">\n"
            ." <rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">\n"
            ."  <rdf:Description rdf:about=\"\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\">\n"
            ."   <dc:title><rdf:Alt><rdf:li xml:lang=\"x-default\">$title</rdf:li></rdf:Alt></dc:title>\n"
            ."   <dc:language><rdf:Bag><rdf:li>$language</rdf:li></rdf:Bag></dc:language>\n"
            ."  </rdf:Description>\n"
            ."  <rdf:Description rdf:about=\"\" xmlns:pdfuaid=\"http://www.aiim.org/pdfua/ns/id/\">\n"
            ."   <pdfuaid:part>1</pdfuaid:part>\n"
            ."  </rdf:Description>\n"
            ." </rdf:RDF>\n"
            ."</x:xmpmeta>\n"
            .'<?xpacket end="w"?>';
    }
}
