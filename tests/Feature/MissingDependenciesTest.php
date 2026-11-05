<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Feature;

use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Status;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\TextExtractor;

/**
 * Runs in CI jobs that deliberately remove a tool or an extension, to prove the outcome is a
 * Result rather than an exception.
 */
final class MissingDependenciesTest extends TestCase
{
    public function test_without_tools_pdf_and_images_fail_with_missing_tool(): void
    {
        if (getenv('TEXT_EXTRACTOR_EXPECT_NO_TOOLS') !== '1') {
            $this->markTestSkipped('Set TEXT_EXTRACTOR_EXPECT_NO_TOOLS=1 on a machine without pdftotext and tesseract.');
        }

        foreach (['pdf/text.pdf', 'image/text.png'] as $fixture) {
            $result = TextExtractor::create()->extract(Fixtures::committed($fixture));

            $this->assertSame(Status::Failed, $result->status, $fixture);
            $this->assertSame(Reason::MissingTool, $result->reason, $fixture);
        }
    }

    public function test_without_zip_office_files_fail_with_missing_extension(): void
    {
        if (getenv('TEXT_EXTRACTOR_EXPECT_NO_ZIP') !== '1') {
            $this->markTestSkipped('Set TEXT_EXTRACTOR_EXPECT_NO_ZIP=1 on a PHP build without ext-zip.');
        }

        $this->assertFalse(extension_loaded('zip'));

        // Built by hand: the fixture helpers need ext-zip, which is exactly what is missing here.
        $docx = Fixtures::file("PK\x03\x04".str_repeat("\0", 26).'word/document.xml', 'docx');
        $result = TextExtractor::create()->extract($docx);

        $this->assertSame(Status::Failed, $result->status);
        $this->assertSame(Reason::MissingExtension, $result->reason);
    }

    public function test_the_text_formats_still_work(): void
    {
        $this->assertSame('plain', TextExtractor::create()->extract(Fixtures::file('plain', 'txt'))->text);
    }
}
