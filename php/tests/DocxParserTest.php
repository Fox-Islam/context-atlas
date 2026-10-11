<?php

declare(strict_types=1);

namespace Phox\ContextAtlas\Tests;

use Phox\ContextAtlas\Exceptions\ContextAtlasException;
use Phox\ContextAtlas\Parsing\DocxParser;
use Phox\ContextAtlas\Parsing\Formats;
use Phox\ContextAtlas\Processor;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class DocxParserTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../spec/fixtures/';

    private ?string $temporary = null;

    protected function tearDown(): void
    {
        if ($this->temporary !== null && is_file($this->temporary)) {
            unlink($this->temporary);
        }
    }

    public function test_a_missing_file_cannot_be_opened(): void
    {
        $this->expectExceptionObject(new ContextAtlasException('Cannot open absent.docx as a DOCX document.'));

        DocxParser::parse(self::FIXTURES . 'absent.docx');
    }

    public function test_a_file_that_is_not_a_zip_cannot_be_opened(): void
    {
        $this->expectExceptionObject(new ContextAtlasException('Cannot open docx-not-a-zip.docx as a DOCX document.'));

        DocxParser::parse(self::FIXTURES . 'docx-not-a-zip.docx');
    }

    public function test_a_package_without_its_main_part_is_refused(): void
    {
        $this->expectExceptionObject(new ContextAtlasException('docx-no-document.docx has no main document part (word/document.xml).'));

        DocxParser::parse(self::FIXTURES . 'docx-no-document.docx');
    }

    public function test_malformed_xml_is_refused(): void
    {
        $this->expectExceptionObject(new ContextAtlasException('word/document.xml in docx-malformed.docx is not well-formed XML.'));

        DocxParser::parse(self::FIXTURES . 'docx-malformed.docx');
    }

    public function test_a_doctype_is_refused_before_any_entity_is_read(): void
    {
        $this->expectExceptionObject(new ContextAtlasException('word/document.xml in docx-doctype.docx declares a DOCTYPE, which is not read.'));

        DocxParser::parse(self::FIXTURES . 'docx-doctype.docx');
    }

    public function test_parts_that_unpack_past_the_limit_are_refused(): void
    {
        $this->temporary = (string) tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive();
        $zip->open($this->temporary, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
        $zip->addFromString('word/styles.xml', str_repeat(' ', 50 * 1024 * 1024));
        $zip->close();

        $this->expectExceptionObject(new ContextAtlasException(basename($this->temporary) . ' is too large: its parts unpack to more than 50 MB.'));

        DocxParser::parse($this->temporary);
    }

    public function test_a_part_nested_too_deep_is_refused_before_parsing(): void
    {
        $this->temporary = (string) tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive();
        $zip->open($this->temporary, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . str_repeat('<w:sdt>', 1000) . str_repeat('</w:sdt>', 1000) . '</w:body></w:document>');
        $zip->close();

        $this->expectExceptionObject(new ContextAtlasException('word/document.xml in ' . basename($this->temporary) . ' is nested more than 1000 elements deep.'));

        DocxParser::parse($this->temporary);
    }

    public function test_a_greater_than_sign_in_an_attribute_does_not_count_as_nesting(): void
    {
        $this->temporary = (string) tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive();
        $zip->open($this->temporary, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . str_repeat('<w:bookmarkStart w:name="a>b"/>', 2000) . '<w:p><w:r><w:t>Kept</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        self::assertSame('Kept', DocxParser::parse($this->temporary)[0]->text);
    }

    public function test_an_undeclared_namespace_prefix_is_not_well_formed(): void
    {
        $this->temporary = (string) tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive();
        $zip->open($this->temporary, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Hi</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        $this->expectExceptionObject(new ContextAtlasException('word/document.xml in ' . basename($this->temporary) . ' is not well-formed XML.'));

        DocxParser::parse($this->temporary);
    }

    public function test_docx_is_a_document_format_read_from_a_file(): void
    {
        self::assertSame('docx', Formats::of('Report.DOCX'));
        self::assertContains('docx', Formats::DOCUMENT);

        $this->expectExceptionObject(new ContextAtlasException('DOCX is read from a file: use ingestFile.'));

        (new Processor())->ingest('PK', 'report', 'docx');
    }

    public function test_ingest_file_reads_a_docx_as_prose(): void
    {
        $document = (new Processor())->ingestFile(self::FIXTURES . 'docx-code.docx', 'guide', 'procedural');

        self::assertSame('guide', $document->id);
        self::assertNotSame([], $document->units);
        self::assertStringContainsString('pip install context-atlas', json_encode($document->toArray(), JSON_THROW_ON_ERROR));
    }
}
