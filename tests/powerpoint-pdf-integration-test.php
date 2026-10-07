<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy/includes/powerpoint-pdf.php';

// Requires a real LibreOffice installation. Creates an ODP fixture, exports it
// to both PowerPoint formats, then exercises the same PDF converter as View.
$binary = $argv[1] ?? 'soffice';
$directory = sys_get_temp_dir() . '/ims_ppt_integration_' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Could not create the integration workspace.');
}
try {
    $zip = new ZipArchive();
    if ($zip->open($directory . '/sample.odp', ZipArchive::CREATE) !== true) {
        throw new RuntimeException('Could not create the presentation fixture.');
    }
    $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.presentation');
    $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
    $zip->addFromString('META-INF/manifest.xml', '<?xml version="1.0"?>'
        . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">'
        . '<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.presentation"/>'
        . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
        . '</manifest:manifest>');
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
        . ' xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0"'
        . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
        . ' xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"'
        . ' office:version="1.2"><office:body><office:presentation>';
    foreach (['PowerPoint PDF preview', 'Second slide conversion check'] as $index => $title) {
        $xml .= '<draw:page draw:name="Slide' . ($index + 1) . '">'
            . '<draw:frame svg:x="2cm" svg:y="2cm" svg:width="20cm" svg:height="3cm">'
            . '<draw:text-box><text:p>' . $title . '</text:p></draw:text-box></draw:frame></draw:page>';
    }
    $zip->addFromString('content.xml', $xml . '</office:presentation></office:body></office:document-content>');
    $zip->close();

    foreach (['ppt' => 'ppt:MS PowerPoint 97', 'pptx' => 'pptx:Impress MS PowerPoint 2007 XML'] as $extension => $filter) {
        $process = proc_open([$binary, '--headless', '--norestore', '--convert-to', $filter,
            '--outdir', $directory, $directory . '/sample.odp'], [
            0 => ['pipe', 'r'], 1 => ['file', $directory . '/export.log', 'w'],
            2 => ['file', $directory . '/export-error.log', 'w'],
        ], $pipes, $directory, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start LibreOffice for fixture generation.');
        }
        fclose($pipes[0]);
        if (proc_close($process) !== 0 || !is_file($directory . '/sample.' . $extension)) {
            throw new RuntimeException('Could not export the real PowerPoint fixture: ' . $extension);
        }
        $pdf = (new DocPowerPointPdf($binary))->convert($directory . '/sample.' . $extension, $extension);
        if (!str_starts_with($pdf, '%PDF-') || preg_match_all('#/Type\s*/Page\b#', $pdf) !== 2) {
            throw new RuntimeException('The converted PDF must contain both slides: ' . $extension);
        }
        echo strtoupper($extension) . " to PDF: two pages verified.\n";
    }
} finally {
    foreach (new DirectoryIterator($directory) as $file) {
        if ($file->isFile()) {
            unlink($file->getPathname());
        }
    }
    rmdir($directory);
}
