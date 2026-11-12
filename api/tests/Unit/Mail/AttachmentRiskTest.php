<?php

namespace Tests\Unit\Mail;

use App\Services\Mail\AttachmentRisk;
use PHPUnit\Framework\TestCase;

class AttachmentRiskTest extends TestCase
{
    public function test_dangerous_names_and_types_are_flagged(): void
    {
        foreach ([
            ['setup.exe', 'application/octet-stream'],
            ['invoice.pdf.exe', 'application/pdf'],
            ['photo.jpg   .scr', 'image/jpeg'],
            ['run.js', 'text/plain'],
            ['macro.docm', 'application/vnd.ms-word.document.macroEnabled.12'],
            ['login.html', 'text/html'],
            ['page', 'text/html'],
            ['x.lnk', 'application/octet-stream'],
            ['payload', 'application/x-msdownload'],
            ["report\u{202E}fdp.exe", 'application/pdf'],
            ['Setup.EXE', null],
        ] as [$name, $mime]) {
            $this->assertSame(AttachmentRisk::DANGER, AttachmentRisk::screen($name, $mime)['level'] ?? null, "{$name} should be dangerous");
        }
    }

    public function test_archives_are_caution_not_danger(): void
    {
        foreach ([['archive.zip', 'application/zip'], ['backup.tar.gz', 'application/gzip'], ['SVEUCILISTE.rar', 'application/octet-stream']] as [$name, $mime]) {
            $this->assertSame(AttachmentRisk::CAUTION, AttachmentRisk::screen($name, $mime)['level'] ?? null, "{$name} should be caution");
        }
    }

    public function test_ordinary_documents_pass(): void
    {
        foreach ([
            ['ponuda.pdf', 'application/pdf'],
            ['slika.jpg', 'image/jpeg'],
            ['tablica.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['dopis.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['notes.txt', 'text/plain'],
            ['data.csv', 'text/csv'],
            [null, 'image/png'],
        ] as [$name, $mime]) {
            $this->assertNull(AttachmentRisk::screen($name, $mime), "{$name} should pass");
        }
        // Inline SVG logos in HTML mail are fine; an SVG *attachment* is not.
        $this->assertNull(AttachmentRisk::screen('logo.svg', 'image/svg+xml', isInline: true));
        $this->assertNotNull(AttachmentRisk::screen('logo.svg', 'image/svg+xml'));
    }

    public function test_sniff_catches_executables_and_web_pages_disguised_as_documents(): void
    {
        $dir = sys_get_temp_dir().'/sovera-risk-'.uniqid();
        mkdir($dir);

        file_put_contents("$dir/a", "MZ\x90\x00\x03\x00\x00\x00 fake pe header");
        $this->assertNotNull(AttachmentRisk::sniff("$dir/a", 'invoice.pdf', 'application/pdf'));

        file_put_contents("$dir/b", "\x7fELF\x02\x01\x01");
        $this->assertNotNull(AttachmentRisk::sniff("$dir/b", 'photo.jpg', 'image/jpeg'));

        file_put_contents("$dir/c", "#!/bin/sh\nrm -rf ~");
        $this->assertNotNull(AttachmentRisk::sniff("$dir/c", 'notes.txt', 'text/plain'));

        file_put_contents("$dir/d", '<!DOCTYPE html><html><body><form>');
        $this->assertNotNull(AttachmentRisk::sniff("$dir/d", 'statement.pdf', 'application/pdf'));

        file_put_contents("$dir/e", "%PDF-1.7\n%âãÏÓ\n1 0 obj");
        $this->assertNull(AttachmentRisk::sniff("$dir/e", 'statement.pdf', 'application/pdf'));

        file_put_contents("$dir/f", "plain text notes\n");
        $this->assertNull(AttachmentRisk::sniff("$dir/f", 'notes.txt', 'text/plain'));

        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
}
