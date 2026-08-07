<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use PDO;
use RuntimeException;

/**
 * Per-robot image and hover animation.
 *
 * Files are written OUTSIDE the web root and served back through a controller.
 * Uploading into public/ would mean an attacker who slipped a .php past the
 * type check could have Apache execute it; with the store outside DocumentRoot
 * there is no URL that reaches the file directly, so that class of bug cannot
 * exist regardless of how good the validation is.
 *
 * The declared Content-Type from the client is ignored entirely — the type is
 * re-derived from the file's own bytes.
 */
class RobotMedia
{
    public const SLOT_IMAGE = 'image';
    public const SLOT_HOVER = 'hover';

    private const MAX_IMAGE_BYTES = 4 * 1024 * 1024;   // 4 MB still
    private const MAX_HOVER_BYTES = 12 * 1024 * 1024;  // 12 MB gif/video

    /** Sniffed mime => extension. Anything not listed here is rejected. */
    private const STILL_TYPES = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    private const HOVER_TYPES = [
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'video/mp4'  => 'mp4',
        'video/webm' => 'webm',
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly string $storagePath,
    ) {
    }

    public static function defaultStoragePath(): string
    {
        $configured = getenv('MEDIA_STORAGE_PATH');

        return $configured !== false && $configured !== ''
            ? $configured
            : dirname(__DIR__, 2) . '/storage/robot-media';
    }

    /**
     * @param array{tmp_name?: string, size?: int, error?: int, name?: string} $file a $_FILES entry
     * @return array{slot: string, mime: string, bytes: int, filename: string}
     */
    public function store(int $robotId, string $slot, array $file): array
    {
        $this->assertRobotExists($robotId);

        $allowed = $slot === self::SLOT_HOVER ? self::HOVER_TYPES : self::STILL_TYPES;
        $maxSize = $slot === self::SLOT_HOVER ? self::MAX_HOVER_BYTES : self::MAX_IMAGE_BYTES;

        $this->assertUploadOk($file, $maxSize);

        $tmp = $file['tmp_name'] ?? '';
        if (!is_uploaded_file($tmp) && !is_file($tmp)) {
            throw new ValidationException(['file' => 'Upload did not arrive intact.']);
        }

        // Sniff the real type; never trust $file['type'], which the client sets.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmp);

        if ($mime === false || !isset($allowed[$mime])) {
            throw new ValidationException([
                'file' => sprintf(
                    'Detected type %s is not allowed for the %s slot. Accepted: %s.',
                    $mime === false ? 'unknown' : $mime,
                    $slot,
                    implode(', ', array_keys($allowed))
                ),
            ]);
        }

        // Randomised name: the client filename never touches the filesystem.
        $filename = sprintf('%d-%s-%s.%s', $robotId, $slot, bin2hex(random_bytes(8)), $allowed[$mime]);
        $target   = $this->storagePath . '/' . $filename;

        $this->ensureStorage();

        $moved = is_uploaded_file($tmp)
            ? move_uploaded_file($tmp, $target)
            : rename($tmp, $target);

        if ($moved === false) {
            throw new RuntimeException('Could not write uploaded media to storage.');
        }
        chmod($target, 0644);

        $previous = $this->currentFile($robotId, $slot);

        $column     = $slot === self::SLOT_HOVER ? 'hover_file' : 'image_file';
        $mimeColumn = $slot === self::SLOT_HOVER ? 'hover_mime' : 'image_mime';

        $this->db->prepare(
            "UPDATE robots SET {$column} = ?, {$mimeColumn} = ?, media_updated_at = CURRENT_TIMESTAMP WHERE id = ?"
        )->execute([$filename, $mime, $robotId]);

        // Replaced files are removed so the store does not grow without bound.
        if ($previous !== null && $previous !== $filename) {
            @unlink($this->storagePath . '/' . $previous);
        }

        return [
            'slot'     => $slot,
            'mime'     => $mime,
            'bytes'    => (int) filesize($target),
            'filename' => $filename,
        ];
    }

    /**
     * @return array{path: string, mime: string}|null
     */
    public function read(int $robotId, string $slot): ?array
    {
        $column     = $slot === self::SLOT_HOVER ? 'hover_file' : 'image_file';
        $mimeColumn = $slot === self::SLOT_HOVER ? 'hover_mime' : 'image_mime';

        $stmt = $this->db->prepare("SELECT {$column} AS f, {$mimeColumn} AS m FROM robots WHERE id = ?");
        $stmt->execute([$robotId]);
        $row = $stmt->fetch();

        if ($row === false || $row['f'] === null) {
            return null;
        }

        // basename() defends against a stored value containing traversal, even
        // though names are generated rather than user-supplied.
        $path = $this->storagePath . '/' . basename((string) $row['f']);

        return is_file($path) ? ['path' => $path, 'mime' => (string) $row['m']] : null;
    }

    public function delete(int $robotId, string $slot): bool
    {
        $existing = $this->currentFile($robotId, $slot);
        if ($existing === null) {
            return false;
        }

        $column     = $slot === self::SLOT_HOVER ? 'hover_file' : 'image_file';
        $mimeColumn = $slot === self::SLOT_HOVER ? 'hover_mime' : 'image_mime';

        $this->db->prepare("UPDATE robots SET {$column} = NULL, {$mimeColumn} = NULL WHERE id = ?")
            ->execute([$robotId]);

        @unlink($this->storagePath . '/' . basename($existing));

        return true;
    }

    private function currentFile(int $robotId, string $slot): ?string
    {
        $column = $slot === self::SLOT_HOVER ? 'hover_file' : 'image_file';
        $stmt   = $this->db->prepare("SELECT {$column} FROM robots WHERE id = ?");
        $stmt->execute([$robotId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /** @param array<string, mixed> $file */
    private function assertUploadOk(array $file, int $maxSize): void
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException(['file' => 'No file was uploaded.']);
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationException(['file' => 'File exceeds the server upload limit.']);
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => "Upload failed (code {$error}).", ]);
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new ValidationException(['file' => 'Uploaded file is empty.']);
        }
        if ($size > $maxSize) {
            throw new ValidationException([
                'file' => sprintf('File is %.1f MB; the limit is %.0f MB.', $size / 1048576, $maxSize / 1048576),
            ]);
        }
    }

    private function assertRobotExists(int $robotId): void
    {
        $stmt = $this->db->prepare('SELECT 1 FROM robots WHERE id = ?');
        $stmt->execute([$robotId]);

        if ($stmt->fetch() === false) {
            throw NotFoundException::robot($robotId);
        }
    }

    private function ensureStorage(): void
    {
        if (!is_dir($this->storagePath) && !mkdir($this->storagePath, 0775, true) && !is_dir($this->storagePath)) {
            throw new RuntimeException("Could not create media storage at {$this->storagePath}");
        }
    }
}
