<?php
declare(strict_types=1);

/** Convert an authorized local presentation in an isolated temporary workspace. */
final class DocPowerPointPdf
{
    public function __construct(
        private readonly string $executable = 'soffice',
        private readonly int $timeout = 60,
        private readonly array $commandPrefix = []
    ) {}

    public function convert(string $source, string $extension): string
    {
        if (!in_array($extension, ['ppt', 'pptx'], true) || !is_file($source) || !is_readable($source)) {
            throw new RuntimeException('Invalid PowerPoint source file.');
        }
        if (!function_exists('proc_open') || $this->executable === '') {
            throw new RuntimeException('PowerPoint PDF conversion requires LibreOffice and PHP proc_open.');
        }
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ims_ppt_pdf_' . bin2hex(random_bytes(16));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Could not create the PowerPoint conversion workspace.');
        }

        $process = null;
        try {
            $input = $directory . '/presentation.' . $extension;
            if (!copy($source, $input) || !mkdir($directory . '/profile', 0700)) {
                throw new RuntimeException('Could not prepare the PowerPoint conversion workspace.');
            }
            // Each request has its own profile, avoiding reuse of another user's office process.
            $profile = str_replace('\\', '/', $directory . '/profile');
            $profileUri = 'file://' . (str_starts_with($profile, '/') ? '' : '/')
                . str_replace('%3A', ':', implode('/', array_map('rawurlencode', explode('/', $profile))));
            $command = array_merge([$this->executable], $this->commandPrefix, [
                '-env:UserInstallation=' . $profileUri,
                '--headless', '--nologo', '--nodefault', '--nofirststartwizard', '--norestore',
                '--convert-to', 'pdf:impress_pdf_Export', '--outdir', $directory, $input,
            ]);
            $process = @proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['file', $directory . '/conversion.log', 'w'],
                2 => ['file', $directory . '/error.log', 'w'],
            ], $pipes, $directory, null, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start LibreOffice. Check LIBREOFFICE_BINARY and PHP process permissions.');
            }
            fclose($pipes[0]);
            $deadline = microtime(true) + max(1, min(180, $this->timeout));
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('PowerPoint PDF conversion timed out.');
                }
                usleep(100000);
            } while (true);
            proc_close($process);
            $process = null;

            $pdfPath = $directory . '/presentation.pdf';
            if ($status['exitcode'] !== 0 || !is_file($pdfPath)) {
                $details = (string)file_get_contents($directory . '/error.log', false, null, 0, 4096);
                throw new RuntimeException('LibreOffice did not produce a PDF. ' . trim($details));
            }
            $size = filesize($pdfPath);
            if ($size === false || $size < 5 || $size > 32 * 1024 * 1024) {
                throw new RuntimeException('The converted PDF is empty or exceeds the 32 MB preview limit.');
            }
            $pdf = file_get_contents($pdfPath);
            if ($pdf === false || !str_starts_with($pdf, '%PDF-')) {
                throw new RuntimeException('The converter output is not a PDF.');
            }
            return $pdf;
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
            $this->removeWorkspace($directory);
        }
    }

    private function removeWorkspace(string $directory): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($directory);
    }
}
