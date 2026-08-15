<?php

namespace Spawn\Laravel\Server;

use Illuminate\Http\UploadedFile;
use TrueAsync\UploadedFile as NativeUploadedFile;

/**
 * Rebuilds the server extension's uploaded files as the objects a Laravel request accepts.
 *
 * Symfony's FileBag takes an array or a Symfony UploadedFile and rejects everything else, so
 * the extension's own class has to be wrapped before the request is constructed. The wrapper
 * points at the temporary file the extension has already written, so an upload of any size
 * costs no copy. That file belongs to the extension and is unlinked when the request ends: a
 * handler that needs the data afterwards has to move it, as it would with a PHP upload.
 */
class UploadedFiles
{
    /* Refusals by the multipart parser itself. PHP has no constant for them, so they are
     * reported through the UPLOAD_ERR_* code closest in meaning; see translateError(). */
    private const NATIVE_ERR_TOO_MANY_FILES = 100;
    private const NATIVE_ERR_INVALID_NAME   = 101;
    private const NATIVE_ERR_TOO_LARGE      = 102;

    /**
     * Converts the array HttpRequest::getFiles() returns.
     *
     * A field name maps to one file, or to a list of them when the field was named `x[]`;
     * both shapes are kept. A field the client submitted empty is dropped rather than
     * reported, which is what PHP's own file bag does with an empty file input, so
     * `$request->file()` answers null for it. A value that is not an upload of the extension
     * is returned unchanged.
     */
    public static function convert(array $files): array
    {
        $converted = [];

        foreach ($files as $name => $file) {
            if (is_array($file)) {
                $list = self::convert($file);

                if ($list !== []) {
                    $converted[$name] = array_is_list($file) ? array_values($list) : $list;
                }

                continue;
            }

            if (!$file instanceof NativeUploadedFile) {
                $converted[$name] = $file;

                continue;
            }

            $upload = self::wrap($file);

            if ($upload !== null) {
                $converted[$name] = $upload;
            }
        }

        return $converted;
    }

    private static function wrap(NativeUploadedFile $file): ?UploadedFile
    {
        $error = self::translateError($file->getError());

        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $path = $error === UPLOAD_ERR_OK ? self::temporaryPath($file) : null;

        if ($error === UPLOAD_ERR_OK && $path === null) {
            $error = UPLOAD_ERR_CANT_WRITE;
        }

        /* Two decisions are locked in here. The last argument turns off the is_uploaded_file()
         * check: the body was parsed by the extension, so PHP's rfc1867 machinery never
         * registered the file and would call every upload invalid. And the object is Laravel's
         * subclass rather than Symfony's, because Request::convertUploadedFiles() re-wraps a
         * Symfony instance with that argument back at false and passes its own subclass
         * through untouched. */
        return new UploadedFile(
            $path ?? '',
            $file->getClientFilename() ?? '',
            $file->getClientMediaType(),
            $error,
            true
        );
    }

    /**
     * Returns the path of the temporary file holding the upload, or null when there is none.
     *
     * The extension spools every uploaded part to disk but exposes no path getter, so the path
     * is read back from the stream it opens over that file.
     */
    private static function temporaryPath(NativeUploadedFile $file): ?string
    {
        $stream = $file->getStream();

        if (!is_resource($stream)) {
            return null;
        }

        $path = stream_get_meta_data($stream)['uri'] ?? null;
        fclose($stream);

        return is_string($path) && is_file($path) ? $path : null;
    }

    /**
     * Maps an extension error code to the UPLOAD_ERR_* constant closest in meaning.
     *
     * Codes below 100 are PHP's own and pass through. The size cap reports as
     * UPLOAD_ERR_INI_SIZE, a rejected filename and the file-count cap as UPLOAD_ERR_EXTENSION;
     * all three leave isValid() false, which is what a caller acts on.
     */
    private static function translateError(int $error): int
    {
        return match ($error) {
            self::NATIVE_ERR_TOO_LARGE => UPLOAD_ERR_INI_SIZE,
            self::NATIVE_ERR_TOO_MANY_FILES, self::NATIVE_ERR_INVALID_NAME => UPLOAD_ERR_EXTENSION,
            default => $error,
        };
    }
}
