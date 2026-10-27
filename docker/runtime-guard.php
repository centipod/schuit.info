<?php
declare(strict_types=1);
namespace Schuit\Security;

// Defense in depth, not a claim to patch the installed libxml2 package.
// These applications have no requirement to resolve external XML entities.
libxml_set_external_entity_loader(static fn () => null);

function unsafeUpload(string $name, string $file): bool {
    if (preg_match('/\.(?:svgz?|xml|xsl|xslt|xhtml|html?|php\d*|phtml|pht|phar|ps|eps|mvg|msl)(?:\.|$)/i', $name)) {
        return true;
    }
    if (!is_file($file)) { return true; }
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
    if (in_array($mime, ['image/svg+xml', 'application/xml', 'text/xml', 'text/html', 'application/xhtml+xml', 'application/postscript', 'text/x-php', 'application/x-php'], true)) {
        return true;
    }
    // Also reject XML declarations/DTDs with a misleading extension or MIME.
    $head = file_get_contents($file, false, null, 0, 4096);
    if ($head === false) { return true; }
    $head = ltrim(str_replace("\0", '', $head), "\xEF\xBB\xBF\xFF\xFE \t\r\n");
    return (bool) preg_match('/^(?:<\?(?:xml|php)|<!DOCTYPE|<svg(?:\s|>))/i', $head);
}

function uploadsAreSafe(array $files): bool {
    $check = static function ($names, $paths, $errors) use (&$check): bool {
        if (is_array($names)) {
            foreach ($names as $key => $name) {
                if (!$check($name, $paths[$key] ?? '', $errors[$key] ?? UPLOAD_ERR_NO_FILE)) { return false; }
            }
            return true;
        }
        if ($errors !== UPLOAD_ERR_OK) { return true; } // Application handles failed/missing uploads.
        return is_string($names) && is_string($paths) && !unsafeUpload($names, $paths);
    };
    foreach ($files as $upload) {
        if (!$check($upload['name'] ?? '', $upload['tmp_name'] ?? '', $upload['error'] ?? UPLOAD_ERR_NO_FILE)) { return false; }
    }
    return true;
}

if (PHP_SAPI !== 'cli' && !uploadsAreSafe($_FILES)) {
    http_response_code(415);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="nl"><meta charset="utf-8"><title>Bestand niet toegestaan</title><main><h1>Bestand niet toegestaan</h1><p>Dit bestandstype kan actieve inhoud bevatten. Gebruik voor afbeeldingen JPG, PNG, GIF, WebP of BMP; voor publicaties PDF; voor stamboomgegevens GEDCOM.</p><p><a href="/">Terug naar de website</a></p></main></html>';
    exit;
}
require_once __DIR__ . '/login-throttle.php';

require_once __DIR__ . '/cognito.php';
\Schuit\Cognito\blockNativeLogin();
