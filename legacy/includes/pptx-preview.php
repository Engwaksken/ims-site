<?php
declare(strict_types=1);

/** A local, simplified PPTX content preview. No files are extracted or fetched. */
final class DocPptxPreview
{
    private const DRAWING = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const PRESENTATION = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    private const RELATIONSHIP = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private int $bytesRead = 0;
    private ZipArchive $zip;

    public function render(string $path): string
    {
        $this->bytesRead = 0;
        $this->zip = new ZipArchive();
        if ($this->zip->open($path) !== true) {
            throw new RuntimeException('Could not open the PowerPoint presentation.');
        }

        try {
            $presentation = $this->xml('ppt/presentation.xml');
            $relationships = $this->relationships('ppt/presentation.xml');
            $slides = $presentation->query('/p:presentation/p:sldIdLst/p:sldId');
            if ($slides === false || $slides->length === 0 || $slides->length > 300) {
                throw new RuntimeException('The presentation has no slides or exceeds the 300-slide preview limit.');
            }

            $body = '<div class="pptx-preview"><p class="pptx-notice">Simplified slide preview: text, tables and embedded images. '
                . 'Layouts, charts, animations and other PowerPoint effects may not appear. Download the original for the full presentation.</p>';
            $body .= '<nav class="pptx-navigation" aria-label="Slides">';
            for ($number = 1; $number <= $slides->length; $number++) {
                $body .= '<a href="#pptx-slide-' . $number . '">Slide ' . $number . '</a> ';
            }
            $body .= '</nav>';
            $number = 0;
            foreach ($slides as $slide) {
                $relationship = $relationships[$slide->getAttributeNS(self::RELATIONSHIP, 'id')] ?? null;
                if ($relationship === null || !str_ends_with($relationship['type'], '/slide')) {
                    throw new RuntimeException('A slide relationship is missing or invalid.');
                }
                $slidePath = $relationship['path'];
                $xpath = $this->xml($slidePath);
                $slideRelationships = $this->relationships($slidePath);
                $content = '';
                foreach ($xpath->query('/p:sld/p:cSld/p:spTree//p:sp | /p:sld/p:cSld/p:spTree//p:pic | /p:sld/p:cSld/p:spTree//a:tbl') as $shape) {
                    if ($shape->localName === 'tbl') {
                        $content .= '<div class="pptx-table"><table>';
                        foreach ($xpath->query('./a:tr', $shape) as $row) {
                            $content .= '<tr>';
                            foreach ($xpath->query('./a:tc', $row) as $cell) {
                                $content .= '<td>' . $this->paragraphs($xpath, $cell) . '</td>';
                            }
                            $content .= '</tr>';
                        }
                        $content .= '</table></div>';
                    } elseif ($shape->localName === 'pic') {
                        $content .= $this->picture($xpath, $shape, $slideRelationships);
                    } else {
                        $content .= $this->paragraphs($xpath, $shape);
                    }
                }
                $number++;
                $body .= '<section class="pptx-slide" id="pptx-slide-' . $number . '"><h2>Slide '
                    . $number . ' of ' . $slides->length . '</h2>'
                    . ($content !== '' ? $content : '<p>No text or supported images on this slide.</p>') . '</section>';
                if (strlen($body) > 32 * 1024 * 1024) {
                    throw new RuntimeException('The presentation exceeds the preview size limit.');
                }
            }
            return $body . '</div>';
        } finally {
            $this->zip->close();
        }
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function read(string $path, int $limit = 4194304): string
    {
        $stat = $this->zip->statName($path);
        if ($stat === false || $stat['size'] > $limit || $this->bytesRead + $stat['size'] > 48 * 1024 * 1024) {
            throw new RuntimeException('A presentation part is missing or exceeds the preview size limit.');
        }
        $this->bytesRead += $stat['size'];
        $data = $this->zip->getFromName($path, $limit + 1);
        if ($data === false || strlen($data) > $limit) {
            throw new RuntimeException('Could not read a presentation part.');
        }
        return $data;
    }

    private function xml(string $path): DOMXPath
    {
        $data = $this->read($path);
        if (stripos($data, '<!DOCTYPE') !== false) {
            throw new RuntimeException('Presentation XML must not contain a doctype.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            if (!$document->loadXML($data, LIBXML_NONET) || $document->doctype !== null) {
                throw new RuntimeException('Invalid presentation XML.');
            }
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('a', self::DRAWING);
            $xpath->registerNamespace('p', self::PRESENTATION);
            $xpath->registerNamespace('r', self::RELATIONSHIP);
            $xpath->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
            return $xpath;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array<string, array{path: string, type: string}> */
    private function relationships(string $part): array
    {
        $path = dirname($part) . '/_rels/' . basename($part) . '.rels';
        if ($this->zip->locateName($path) === false) {
            return [];
        }
        $xpath = $this->xml($path);
        $relationships = [];
        foreach ($xpath->query('/rel:Relationships/rel:Relationship') as $node) {
            if (strcasecmp($node->getAttribute('TargetMode'), 'External') === 0) {
                continue;
            }
            $target = $node->getAttribute('Target');
            if ($target === '' || preg_match('/[\\\\:\x00?#]/', $target)) {
                continue;
            }
            $segments = [];
            $target = str_starts_with($target, '/') ? ltrim($target, '/') : dirname($part) . '/' . $target;
            foreach (explode('/', $target) as $segment) {
                if ($segment === '..') {
                    if ($segments === []) {
                        throw new RuntimeException('Invalid presentation relationship target.');
                    }
                    array_pop($segments);
                } elseif ($segment !== '' && $segment !== '.') {
                    $segments[] = $segment;
                }
            }
            $relationships[$node->getAttribute('Id')] = ['path' => implode('/', $segments), 'type' => $node->getAttribute('Type')];
        }
        return $relationships;
    }

    private function paragraphs(DOMXPath $xpath, DOMNode $shape): string
    {
        $html = '';
        foreach ($xpath->query('.//a:p', $shape) as $paragraph) {
            $text = '';
            foreach ($xpath->query('.//a:t | .//a:br', $paragraph) as $run) {
                $text .= $run->localName === 'br' ? '<br>' : self::escape($run->textContent);
            }
            if ($text !== '') {
                $bullet = $xpath->query('./a:pPr/a:buChar | ./a:pPr/a:buAutoNum', $paragraph)->length > 0;
                $html .= '<p>' . ($bullet ? '&bull; ' : '') . $text . '</p>';
            }
        }
        return $html;
    }

    private function picture(DOMXPath $xpath, DOMElement $shape, array $relationships): string
    {
        $blip = $xpath->query('.//a:blip', $shape)->item(0);
        $relationship = $blip instanceof DOMElement
            ? ($relationships[$blip->getAttributeNS(self::RELATIONSHIP, 'embed')] ?? null) : null;
        if ($relationship === null || !str_ends_with($relationship['type'], '/image')) {
            return '<p class="pptx-notice">Linked or unsupported image: view it in the original presentation.</p>';
        }
        $data = $this->read($relationship['path'], 8 * 1024 * 1024);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            return '<p class="pptx-notice">Unsupported image format: view it in the original presentation.</p>';
        }
        $properties = $xpath->query('.//p:cNvPr', $shape)->item(0);
        $alt = $properties instanceof DOMElement ? $properties->getAttribute('descr') : '';
        return '<img class="pptx-image" alt="' . self::escape($alt !== '' ? $alt : 'Slide image')
            . '" src="data:' . $mime . ';base64,' . base64_encode($data) . '">';
    }
}
