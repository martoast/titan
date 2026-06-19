<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Services\Health\AppleHealthImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles the Apple Health export upload from the Devices page. The Health app's
 * "Export All Health Data" produces a (frequently large) export.zip; we hand it to the
 * AppleHealthImporter which stream-parses it and writes into Titan's canonical tables.
 *
 * NOTE on big uploads: the zip can be tens to hundreds of MB. PHP gates uploads with
 *   upload_max_filesize  and  post_max_size  (php.ini). If a user's export exceeds the
 * server limit, PHP silently drops the file and $request->file() is null -- we detect
 * that and return a friendly message telling them to raise the limit. To support large
 * exports set e.g.  upload_max_filesize=512M  post_max_size=512M  (and the web server's
 * client_max_body_size for nginx). The importer itself never loads the XML into memory.
 */
class AppleHealthController extends Controller
{
    /** Validation ceiling in KB (2 GB). The real limit is whatever php.ini allows. */
    private const MAX_KB = 2_097_152;

    public function upload(Request $request, AppleHealthImporter $importer)
    {
        $profile = $request->user()->ensureProfile();

        // Detect a POST that blew past post_max_size: PHP returns an empty $_POST/$_FILES
        // and no validation error fires, so guard explicitly with a friendly message.
        if ($this->exceededPostMax($request)) {
            return back()->with('apple_health_error',
                "That export is larger than this server accepts. Ask your admin to raise PHP's upload_max_filesize / post_max_size.");
        }

        try {
            $request->validate([
                'export' => ['required', 'file', 'max:'.self::MAX_KB],
            ], [
                'export.required' => 'Choose your Apple Health export.zip first.',
                'export.max' => "That file is too large for this server's upload limit.",
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->with('apple_health_error', $e->validator->errors()->first('export'));
        }

        $file = $request->file('export');

        if (! $file || ! $file->isValid()) {
            return back()->with('apple_health_error',
                "The upload did not complete. The file may exceed the server's size limit (php.ini upload_max_filesize).");
        }

        // Accept .zip by extension/mime; Apple's export is always a zip.
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $isZip = $ext === 'zip'
            || in_array($file->getMimeType(), ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true);

        if (! $isZip) {
            return back()->with('apple_health_error',
                'That does not look like a .zip. Upload the file from Health → Profile → Export All Health Data.');
        }

        try {
            $summary = $importer->importZip($profile, $file->getRealPath());
        } catch (\Throwable $e) {
            Log::warning('[AppleHealth] controller import failed', ['error' => $e->getMessage()]);

            return back()->with('apple_health_error', 'Something went wrong reading that export. Please try again.');
        }

        if ($summary['error']) {
            return back()->with('apple_health_error', $summary['error']);
        }

        return back()->with('apple_health_summary', $summary);
    }

    /**
     * True when the request body exceeded post_max_size -- PHP empties superglobals in
     * that case, which we'd otherwise misread as an empty form.
     */
    private function exceededPostMax(Request $request): bool
    {
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        if ($contentLength <= 0) {
            return false;
        }
        $postMax = $this->bytesFromIni((string) ini_get('post_max_size'));

        return $postMax > 0
            && $contentLength > $postMax
            && empty($request->all())
            && $request->file('export') === null;
    }

    /** Convert a php.ini size shorthand ("512M", "2G") to bytes. */
    private function bytesFromIni(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower($value[strlen($value) - 1]);
        $num = (int) $value;

        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $value,
        };
    }
}
