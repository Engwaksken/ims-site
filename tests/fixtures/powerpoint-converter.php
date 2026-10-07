<?php
declare(strict_types=1);

// Test double for the external LibreOffice process, including failure modes.
$mode = $argv[1] ?? '';
$outIndex = array_search('--outdir', $argv, true);
if ($outIndex === false || !in_array('pdf:impress_pdf_Export', $argv, true)) {
    exit(2);
}
$directory = $argv[$outIndex + 1];
$source = $argv[count($argv) - 1];
if (!is_file($source) || !str_starts_with(basename($source), 'presentation.')) {
    exit(3);
}
if ($mode === 'timeout') {
    sleep(10);
}
if ($mode === 'fail') {
    fwrite(STDERR, 'Conversion failed for test.');
    exit(1);
}
if ($mode === 'missing') {
    exit(0);
}
file_put_contents($directory . '/presentation.pdf', $mode === 'invalid' ? 'not a PDF' : "%PDF-1.4\n%%EOF\n");
// Real LibreOffice adds nested files to its profile, which must also be removed.
mkdir($directory . '/profile/nested');
file_put_contents($directory . '/profile/nested/lock', 'test');
