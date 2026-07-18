<?php

namespace App\Services\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class ClamAvAttachmentScanner implements AttachmentMalwareScanner
{
    public function __construct(private readonly SecurityAlertService $alerts) {}

    public function scan(UploadedFile $file): void
    {
        if (! config('security.attachments.malware_scanning.enabled')) {
            return;
        }

        if (config('security.attachments.malware_scanning.driver') !== 'clamav') {
            $this->scannerUnavailable($file, 'Unsupported malware scanner driver.');

            return;
        }

        $path = $file->getRealPath();

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new UnsafeAttachmentException(
                'The attachment could not be inspected. Please select the file again.',
                'The temporary uploaded file was unavailable for malware scanning.',
            );
        }

        $process = new Process([
            (string) config('security.attachments.malware_scanning.binary', 'clamscan'),
            '--no-summary',
            '--stdout',
            $path,
        ]);
        $process->setTimeout(max(1, (int) config('security.attachments.malware_scanning.timeout_seconds', 30)));

        try {
            $process->run();
        } catch (Throwable $exception) {
            $this->scannerUnavailable($file, $exception->getMessage());

            return;
        }

        if ($process->getExitCode() === 0) {
            return;
        }

        if ($process->getExitCode() === 1) {
            $this->alerts->alertAdministrators(
                'attachment.malware_detected',
                'Malicious attachment blocked',
                'An uploaded leave attachment failed malware scanning and was not stored.',
                'critical',
                ['original_name' => $file->getClientOriginalName()],
                request()->user(),
            );

            throw new UnsafeAttachmentException(
                'The attachment failed the security scan and was not uploaded.',
                'ClamAV detected malware in an uploaded attachment.',
            );
        }

        $this->scannerUnavailable($file, trim($process->getErrorOutput().' '.$process->getOutput()));
    }

    private function scannerUnavailable(UploadedFile $file, string $error): void
    {
        $this->alerts->alertAdministrators(
            'attachment.scanner_unavailable',
            'Attachment scanner unavailable',
            'The malware scanner could not inspect a leave attachment.',
            'critical',
            ['original_name' => $file->getClientOriginalName()],
            request()->user(),
        );

        Log::critical('The attachment malware scanner was unavailable.', [
            'error' => mb_substr($error, 0, 1_000),
        ]);

        if (config('security.attachments.malware_scanning.fail_closed', true)) {
            throw new UnsafeAttachmentException(
                'The attachment scanner is temporarily unavailable. Please try again later.',
                'The malware scanner was unavailable and fail-closed mode rejected the upload.',
            );
        }
    }
}
