<?php

declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Configure and return a standard Dompdf instance.
 */
function createPdfInstance(): Dompdf
{
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    return new Dompdf($options);
}

/**
 * Render HTML content to PDF binary string.
 */
function renderPdf(string $html, string $paper = 'A4', string $orientation = 'portrait'): ?string
{
    try {
        $dompdf = createPdfInstance();
        $dompdf->loadHtml($html);
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();

        return $dompdf->output();
    } catch (Throwable $e) {
        error_log('PDF Render Error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Output PDF directly to browser (inline view or download attachment).
 */
function streamPdf(string $html, string $filename = 'document.pdf', bool $download = false, string $paper = 'A4', string $orientation = 'portrait'): void
{
    try {
        $dompdf = createPdfInstance();
        $dompdf->loadHtml($html);
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();

        $dompdf->stream($filename, [
            'Attachment' => $download ? 1 : 0
        ]);
        exit;
    } catch (Throwable $e) {
        error_log('PDF Stream Error: ' . $e->getMessage());
        http_response_code(500);
        echo 'Error generating PDF document: ' . htmlspecialchars($e->getMessage());
        exit;
    }
}

/**
 * Render and save PDF to a specific file path on disk.
 */
function savePdfToFile(string $html, string $destinationPath, string $paper = 'A4', string $orientation = 'portrait'): bool
{
    $content = renderPdf($html, $paper, $orientation);
    if ($content === null) {
        return false;
    }
    return file_put_contents($destinationPath, $content) !== false;
}
