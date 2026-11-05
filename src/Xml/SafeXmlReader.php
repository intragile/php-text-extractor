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
 * Network access, DTD loading, default attributes and entity substitution are disabled, so an
 * external DTD named by a DOCTYPE is never fetched. A DOCTYPE carrying an internal subset is
 * refused outright: that is where entity declarations live, and refusing it closes the door on
 * external-entity (XXE) and entity-expansion attacks regardless of the libxml version.
 *
 * Only fatal (well-formedness) errors reject a document. Recoverable ones such as an undeclared
 * namespace prefix are ignored, and libxml's error list is drained after every step so that a
 * document producing millions of them cannot exhaust memory.
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

    /**
     * Opens a local file by path. The path is handed to libxml as an escaped file:// URI, so
     * neither percent sequences nor stream-wrapper prefixes in the name are interpreted.
     */
    public static function fromFile(string $path): self
    {
        return self::fromUri(self::fileUri($path));
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
        // XMLReader::next() parses the whole subtree in a single call, during which libxml keeps
        // every recoverable error it meets; walking the subtree step by step lets each step drain
        // the error list. The reader ends up on the same node either way.
        if ($this->closed || $this->nodeType() !== XMLReader::ELEMENT || $this->isEmptyElement()) {
            return $this->read();
        }

        $depth = $this->depth();

        while ($this->read()) {
            if ($this->isEndTagAt($depth)) {
                return $this->read();
            }
        }

        return false;
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

    private function isEndTagAt(int $depth): bool
    {
        return $this->reader->nodeType === XMLReader::END_ELEMENT && $this->reader->depth === $depth;
    }

    private function advance(bool $moved): bool
    {
        $error = $this->drainErrors();

        if ($moved && $error === null) {
            if ($this->reader->nodeType === XMLReader::DOC_TYPE && $this->hasInternalSubset()) {
                $this->close();

                throw new MalformedXml('XML documents with an internal DTD subset are not accepted.');
            }

            $this->sawNode = true;

            return true;
        }

        $limitExceeded = $this->limitExceeded !== null && ($this->limitExceeded)();
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

    /**
     * Returns the first fatal error raised by the last step, then empties libxml's error list.
     *
     * The list only ever holds the errors of a single step, so reading it stays cheap; the last
     * error check skips the copy entirely on the common error-free path.
     */
    private function drainErrors(): ?string
    {
        if (libxml_get_last_error() === false) {
            return null;
        }

        $fatal = null;

        foreach (libxml_get_errors() as $error) {
            if ($error->level === LIBXML_ERR_FATAL) {
                $fatal = trim($error->message);

                break;
            }
        }

        libxml_clear_errors();

        return $fatal;
    }

    /**
     * libxml serialises a DOCTYPE with its internal subset, so the subset shows up either as
     * declarations ("[ ... ]>") or, for comments and processing instructions, as inner markup.
     */
    private function hasInternalSubset(): bool
    {
        $outer = rtrim(@$this->reader->readOuterXml());
        $inner = @$this->reader->readInnerXml();
        libxml_clear_errors();

        return str_ends_with($outer, ']>') || $inner !== '';
    }

    private static function fileUri(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $drive = '';

        if (preg_match('#^[A-Za-z]:/#', $path) === 1) {
            $drive = '/'.substr($path, 0, 2);
            $path = substr($path, 2);
        } elseif (! str_starts_with($path, '/')) {
            $path = str_replace('\\', '/', (string) getcwd()).'/'.$path;
        }

        $segments = array_map('rawurlencode', explode('/', $path));

        return 'file://'.$drive.implode('/', $segments);
    }

    private static function requireExtension(): void
    {
        if (! extension_loaded('xmlreader')) {
            throw new LogicException('ext-xmlreader is required to read XML.');
        }
    }
}
