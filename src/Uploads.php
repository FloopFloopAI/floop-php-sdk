<?php

declare(strict_types=1);

namespace FloopFloop;

final class Uploads
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    private const EXT_TO_MIME = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Presign an upload slot, PUT bytes directly to S3, return the
     * attachment hash ready to drop into `projects()->refine(...)`'s
     * `attachments` array.
     *
     * @return array<string, mixed>  {key, fileName, fileType, fileSize}
     */
    public function create(string $fileName, string $bytes, ?string $fileType = null): array
    {
        if ($fileName === '') {
            throw new Error('VALIDATION_ERROR', 'uploads: fileName is required');
        }
        $resolvedType = $fileType ?? self::guessMimeType($fileName);
        if ($resolvedType === null || !in_array($resolvedType, self::EXT_TO_MIME, true)) {
            throw new Error(
                'VALIDATION_ERROR',
                "uploads: unsupported file type for {$fileName}. Allowed: png, jpg, gif, svg, webp, ico, pdf, txt, csv, doc, docx.",
            );
        }
        $size = strlen($bytes);
        if ($size > self::MAX_UPLOAD_BYTES) {
            $mb = number_format($size / 1024 / 1024, 1);
            throw new Error(
                'VALIDATION_ERROR',
                "uploads: {$fileName} is {$mb} MB — the upload limit is 5 MB.",
            );
        }

        $presign = $this->client->request('POST', '/api/v1/uploads', [
            'fileName' => $fileName,
            'fileType' => $resolvedType,
            'fileSize' => $size,
        ]);
        if (!is_array($presign) || !isset($presign['uploadUrl'], $presign['key'])) {
            throw new Error('UNKNOWN', 'uploads: presign returned unexpected shape');
        }

        $this->client->rawPut((string) $presign['uploadUrl'], $bytes, $resolvedType);

        return [
            'key' => (string) $presign['key'],
            'fileName' => $fileName,
            'fileType' => $resolvedType,
            'fileSize' => $size,
        ];
    }

    /**
     * Convenience: read from disk and upload.  Typical PHP app use
     * case (local filesystem access is the norm, unlike MCP stdio).
     *
     * @return array<string, mixed>
     */
    public function createFromPath(string $filePath, ?string $fileType = null): array
    {
        if (!is_file($filePath)) {
            throw new Error('VALIDATION_ERROR', "uploads: {$filePath} is not a regular file");
        }
        $bytes = @file_get_contents($filePath);
        if ($bytes === false) {
            throw new Error('VALIDATION_ERROR', "uploads: failed to read {$filePath}");
        }
        return $this->create(basename($filePath), $bytes, $fileType);
    }

    public static function guessMimeType(string $fileName): ?string
    {
        $ext = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        return self::EXT_TO_MIME[$ext] ?? null;
    }
}
