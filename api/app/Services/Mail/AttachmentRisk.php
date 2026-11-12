<?php

namespace App\Services\Mail;

/**
 * Lightweight, offline attachment screening. Two stages:
 *
 *  1. assess()  – at sync time, from metadata only (file name + declared type).
 *                 Nothing is downloaded: a mail can never fetch its own payload.
 *  2. sniff()   – after the user explicitly downloads a file, the real content
 *                 is checked against what the mail claimed it was.
 *
 * Two levels:
 *  - DANGER  – executables, scripts, macros, web pages, disguised files. The
 *              attachment is blocked for good (no preview, download or forward)
 *              and the message is locked until the user acknowledges the risk.
 *  - CAUTION – archives: contents unknown until downloaded. Shown in amber;
 *              the message opens normally, downloading asks for confirmation.
 */
final class AttachmentRisk
{
    public const DANGER = 'danger';

    public const CAUTION = 'caution';

    /** Executables, scripts, installers, shortcuts: things that run when opened. */
    private const EXECUTABLE_EXT = [
        'exe', 'dll', 'scr', 'pif', 'com', 'cpl', 'msi', 'msp', 'msc', 'bat', 'cmd', 'ps1', 'psm1', 'vbs', 'vbe', 'js', 'jse',
        'wsf', 'wsh', 'hta', 'jar', 'lnk', 'reg', 'inf', 'sct', 'url', 'app', 'command', 'sh', 'bash', 'zsh', 'pkg', 'dmg', 'apk',
        'iso', 'img', 'vhd', 'vhdx',
    ];

    /** Office documents that can carry macros. */
    private const MACRO_EXT = ['docm', 'dotm', 'xlsm', 'xltm', 'xlam', 'pptm', 'potm', 'ppam', 'sldm'];

    /** Web pages: phishing forms and scripts open straight in the browser. */
    private const WEB_EXT = ['html', 'htm', 'xhtml', 'mht', 'mhtml', 'svg'];

    /** Archives: cannot be looked into without downloading them. */
    private const ARCHIVE_EXT = ['zip', 'rar', '7z', 'gz', 'tgz', 'tar', 'bz2', 'xz', 'z', 'ace', 'arj', 'cab', 'lz', 'lzh'];

    private const EXECUTABLE_MIME = [
        'application/x-msdownload', 'application/x-msdos-program', 'application/x-dosexec', 'application/x-executable',
        'application/x-mach-binary', 'application/x-sharedlib', 'application/x-elf', 'application/vnd.microsoft.portable-executable',
        'application/x-sh', 'application/x-shellscript', 'text/x-shellscript', 'application/x-bat', 'application/x-msi',
        'application/x-ms-installer', 'application/java-archive', 'application/x-java-archive', 'text/javascript',
        'application/javascript', 'application/x-javascript', 'application/x-ms-shortcut', 'application/x-mswinurl', 'application/hta',
        'application/vnd.android.package-archive', 'application/x-apple-diskimage', 'application/x-iso9660-image',
    ];

    /**
     * Metadata-only verdict. Null = looks fine.
     *
     * @return array{level: 'danger'|'caution', reason: string}|null
     */
    public static function screen(?string $filename, ?string $mime, bool $isInline = false): ?array
    {
        $name = trim((string) $filename);
        $mime = strtolower(trim((string) $mime));
        $ext = self::extension($name);

        $danger = fn (string $reason) => ['level' => self::DANGER, 'reason' => $reason];

        if ($name !== '' && preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $name)) {
            return $danger('File name contains hidden direction-override characters, a trick used to disguise executables.');
        }
        if (in_array($mime, self::EXECUTABLE_MIME, true)) {
            return $danger("Declared type {$mime} is an executable or script.");
        }
        if (in_array($ext, self::EXECUTABLE_EXT, true)) {
            return $danger("\".{$ext}\" files run code when opened.");
        }
        if (in_array($ext, self::MACRO_EXT, true)) {
            return $danger("\".{$ext}\" is an Office document that can contain macros.");
        }
        if (self::hasDoubleExtension($name)) {
            return $danger('File name hides a second extension (e.g. "invoice.pdf.exe").');
        }
        if (in_array($ext, self::WEB_EXT, true) && ! ($isInline && $ext === 'svg')) {
            return $danger("\".{$ext}\" opens in the browser and can contain scripts or a phishing form.");
        }
        if ($mime === 'text/html' || $mime === 'application/xhtml+xml') {
            return $danger('HTML attachment: can contain scripts or a phishing form.');
        }
        if (in_array($ext, self::ARCHIVE_EXT, true) || str_ends_with(strtolower($name), '.tar.gz')) {
            return ['level' => self::CAUTION, 'reason' => "Archive (.{$ext}): its contents cannot be checked before you download it. Open it only if you expected it from this sender."];
        }

        return null;
    }

    /**
     * Content check after an explicit download: does the file match what the mail said?
     * Null = consistent.
     */
    public static function sniff(string $path, ?string $filename, ?string $declaredMime): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 512);
        $declared = strtolower(trim((string) $declaredMime));
        $ext = self::extension((string) $filename);

        $detected = strtolower((string) (@finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path) ?: ''));

        $looksExecutable = str_starts_with($head, 'MZ')
            || str_starts_with($head, "\x7fELF")
            || str_starts_with($head, '#!')
            || str_starts_with($head, "\xcf\xfa\xed\xfe") || str_starts_with($head, "\xca\xfe\xba\xbe") // Mach-O / universal
            || in_array($detected, self::EXECUTABLE_MIME, true);

        if ($looksExecutable) {
            return 'The file content is an executable or script although the mail declared it as '.($declared !== '' ? $declared : 'something else').'.';
        }

        $isHtml = $detected === 'text/html' || preg_match('/^\s*<(!doctype\s+html|html|script)/i', $head);
        if ($isHtml && ! in_array($ext, self::WEB_EXT, true) && ! str_starts_with($declared, 'text/html')) {
            return 'The file content is a web page although the mail declared it as '.($declared !== '' ? $declared : 'something else').'.';
        }

        return null;
    }

    private static function extension(string $name): string
    {
        $name = strtolower(rtrim($name));

        return pathinfo($name, PATHINFO_EXTENSION);
    }

    private static function hasDoubleExtension(string $name): bool
    {
        $lower = strtolower($name);
        $risky = implode('|', array_merge(self::EXECUTABLE_EXT, self::MACRO_EXT, self::WEB_EXT));

        // "report.pdf.exe", "photo.jpg   .scr" (padding spaces), "invoice.pdf.js"
        return (bool) preg_match('/\.(pdf|docx?|xlsx?|pptx?|jpe?g|png|gif|txt|csv|rtf|odt)\s*\.(?:'.$risky.')$/', $lower);
    }
}
