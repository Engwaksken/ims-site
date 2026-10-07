<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy/includes/powerpoint-pdf.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function workspaces(): array
{
    $paths = glob(sys_get_temp_dir() . '/ims_ppt_pdf_*') ?: [];
    sort($paths);
    return $paths;
}

$source = tempnam(sys_get_temp_dir(), 'ims_test_presentation_');
check($source !== false, 'Could not create test source.');
file_put_contents($source, 'presentation test input');
$before = workspaces();
try {
    foreach (['ppt', 'pptx'] as $extension) {
        $converter = new DocPowerPointPdf(PHP_BINARY, 5, [__DIR__ . '/fixtures/powerpoint-converter.php', 'success']);
        check(str_starts_with($converter->convert($source, $extension), '%PDF-'), 'Both PowerPoint formats must produce PDFs.');
        check(workspaces() === $before, 'Successful conversion must remove its workspace.');
        check(file_get_contents($source) === 'presentation test input', 'The original presentation must not change.');
    }
    foreach (['fail', 'missing', 'invalid', 'timeout'] as $mode) {
        $converter = new DocPowerPointPdf(PHP_BINARY, 1, [__DIR__ . '/fixtures/powerpoint-converter.php', $mode]);
        $failed = false;
        try {
            $converter->convert($source, 'ppt');
        } catch (RuntimeException $exception) {
            $failed = true;
            if ($mode === 'timeout') {
                check(str_contains($exception->getMessage(), 'timed out'), 'A hung converter must time out.');
            }
        }
        check($failed, 'Failed, missing, invalid or timed-out output must not be served as PDF: ' . $mode);
        check(workspaces() === $before, 'Failure must remove its workspace: ' . $mode);
    }
    $failed = false;
    try {
        (new DocPowerPointPdf(PHP_BINARY))->convert($source, 'exe');
    } catch (RuntimeException $exception) {
        $failed = true;
    }
    check($failed, 'Only PowerPoint extensions may be converted.');
} finally {
    unlink($source);
}
echo "PowerPoint PDF conversion tests passed.\n";
