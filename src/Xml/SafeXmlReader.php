<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Xml;

use Closure;
use IntrAgile\TextExtractor\Archive\ArchiveLimitExceeded;
use LogicException;
use XMLReader;

/**
 * Forward-only XML reader hardened against hostile input.
 *
 * Network access, DTD loading and entity substitution are disabled, and any document with a
 * DOCTYPE is refused outright: none of the supported formats use one, and refusing it closes the
 * door on external-entity (XXE) and entity-expansion attacks regardless of the libxml version.
 */
final class SafeXmlReader
{
    private const FLAGS = LIBXML_NONET | LIBXML_COMPACT;

    private bool $closed = false;

    private bool $sawNode = false;

    private function __construct(
        private readonly XMLReader $reader,
        private readonly bool $previousErrorMode,
        private readonly ?Closure $limitExceeded,
    ) {
        $reader->setParserProperty(XMLReader::LOADDTD, false);
        $reader->setParserProperty(XMLReader::DEFAULTATTRS, false);
        $reader->setParserProperty(XMLReader::VALIDATE, false);
        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
    }

    /**
     * @param  Closure(): bool|null  $limitExceeded  Reports whether an underlying byte budget ran out.
     */
    public static function fromUri(string $uri, ?Closure $limitExceeded = null): self
    {
        self::requireExtension();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;

        if (@$reader->open($uri, null, self::FLAGS) !== true) {
            libxml_use_internal_errors($previous);

            throw new MalformedXml('The XML document cannot be opened.');
        }

        return new self($reader, $previous, $limitExceeded);
    }

    public static function fromString(string $xml): self
    {
        self::requireExtension();

        if ($xml === '') {
            throw new MalformedXml('The XML document is empty.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;

        if (@$reader->XML($xml, null, self::FLAGS) !== true) {
            libxml_use_internal_errors($previous);

            throw new MalformedXml('The XML document cannot be parsed.');
        }

        return new self($reader, $previous, null);
    }

    /**
     * Advances to the next node.
     *
     * @throws MalformedXml when the document is not well-formed or declares a DOCTYPE
     * @throws ArchiveLimitExceeded when the underlying archive entry ran over its byte budget
     */
    public function read(): bool
    {
        return $this->advance(@$this->reader->read());
    }

    /**
     * Skips the current node's subtree and moves to its next sibling.
     */
    public function next(): bool
    {
        return $this->advance(@$this->reader->next());
    }

    public function nodeType(): int
    {
        return $this->reader->nodeType;
    }

    public function localName(): string
    {
        return $this->reader->localName;
    }

    public function namespaceUri(): string
    {
        return $this->reader->namespaceURI;
    }

    public function attribute(string $localName, ?string $namespace = null): ?string
    {
        return $namespace === null
            ? $this->reader->getAttribute($localName)
            : $this->reader->getAttributeNs($localName, $namespace);
    }

    public function value(): string
    {
        return $this->reader->value;
    }

    public function isEmptyElement(): bool
    {
        return $this->reader->isEmptyElement;
    }

    public function depth(): int
    {
        return $this->reader->depth;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        @$this->reader->close();
        libxml_clear_errors();
        libxml_use_internal_errors($this->previousErrorMode);
    }

    public function __destruct()
    {
        $this->close();
    }

    private function advance(bool $moved): bool
    {
        if ($moved) {
            if ($this->reader->nodeType === XMLReader::DOC_TYPE) {
                $this->close();

                throw new MalformedXml('XML documents with a DOCTYPE declaration are not accepted.');
            }

            $this->sawNode = true;

            return true;
        }

        $limitExceeded = $this->limitExceeded !== null && ($this->limitExceeded)();
        $error = $this->firstError();
        $empty = ! $this->sawNode;
        $this->close();

        if ($limitExceeded) {
            throw new ArchiveLimitExceeded('The archive entry exceeds the size limit.');
        }

        if ($error !== null) {
            throw new MalformedXml('The XML document is not well-formed: '.$error);
        }

        if ($empty) {
            throw new MalformedXml('The XML document has no content.');
        }

        return false;
    }

    private function firstError(): ?string
    {
        foreach (libxml_get_errors() as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                return trim($error->message);
            }
        }

        return null;
    }

    private static function requireExtension(): void
    {
        if (! extension_loaded('xmlreader')) {
            throw new LogicException('ext-xmlreader is required to read XML.');
        }
    }
}
