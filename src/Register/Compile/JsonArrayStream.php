<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Compile;

use Cbox\Tax\Exceptions\DatasetUnreadable;
use Generator;

/**
 * Reads one named array out of a large JSON document without ever holding the
 * document — or the array — in memory.
 *
 * THIS IS WHY IT EXISTS. The register's US region is 48.8 MB of JSON, and
 * `json_decode` on it peaks at **315 MB** of PHP arrays: it does not blow a
 * generous memory limit, it blows the default one, on the machine of anybody who
 * installs this package. There is no version of "decode the document and pick out
 * what we need" that ships.
 *
 * So the document is never decoded. The stream is scanned for the array we want and
 * its elements are handed back one at a time, each decoded on its own, and the
 * caller writes each straight to disk. Peak memory is one record — about 14 KB for
 * the largest rate row in the register — whatever the input weighs.
 *
 * It is deliberately not a general JSON parser. It knows one shape: a top-level
 * object with a key whose value is an array of objects. Anything else it refuses,
 * because a parser that guesses at a shape it was not given is how you get a silent
 * half-read, and a half-read section is indistinguishable from a small one.
 *
 * The string handling is the part that matters. A brace inside a provenance note —
 * and the notes in this register quote statutes, at length, in twenty-three
 * languages — must not be counted as structure, nor must a quote that is escaped,
 * nor a backslash that is itself escaped. That is the whole of the state machine
 * below, and the tests hand it exactly those three cases.
 */
class JsonArrayStream
{
    private string $buffer = '';

    private int $pos = 0;

    private bool $eof = false;

    /** @var int<1, max> */
    private readonly int $chunk;

    /**
     * @param  resource  $stream
     */
    public function __construct(
        private readonly mixed $stream,
        int $chunk = 262144,
    ) {
        // Clamped rather than trusted: a zero-byte read loops for ever, which looks
        // like a hang rather than a bad argument.
        $this->chunk = max(1, $chunk);
    }

    /**
     * Open a file (or any stream wrapper PHP can read) and walk one array in it.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public static function fromFile(string $path, string $key): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw DatasetUnreadable::cannotOpen($path);
        }

        try {
            yield from new self($handle)->objects($key, $path);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Every object in the array at the top-level key, in document order.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function objects(string $key, string $what = 'document'): Generator
    {
        if (! $this->seekToArray($key)) {
            throw DatasetUnreadable::noSuchSection($what, $key);
        }

        $index = 0;

        while (true) {
            $this->skipInsignificant();
            $next = $this->peek();

            if ($next === null) {
                throw DatasetUnreadable::truncated($what, $key);
            }

            if ($next === ']') {
                return;
            }

            // An array of OBJECTS or an array of ARRAYS — the rate sections are the
            // first and a boundary `sets` table is the second. A SCALAR here means
            // the document is not what the caller thinks it is, and skipping it
            // quietly would return a section short by however many it contained.
            if ($next !== '{' && $next !== '[') {
                throw DatasetUnreadable::unexpectedElement($what, $key, $next);
            }

            yield $index++ => $this->readObject($what, $key);
        }
    }

    /**
     * Open a file and walk one top-level OBJECT in it, as `name => value`.
     *
     * @return Generator<string, array<string, mixed>>
     */
    public static function membersOfFile(string $path, string $key): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw DatasetUnreadable::cannotOpen($path);
        }

        try {
            yield from new self($handle)->members($key, $path);
        } finally {
            fclose($handle);
        }
    }

    /**
     * One SCALAR at a top-level key — a version number, a state code — read without
     * decoding the document around it.
     *
     * Exists for the postal layer: its `formatVersion` has to travel with the pieces a
     * compile splits it into, and decoding a 3.6 MB file to read one integer is the
     * thing this class is here to avoid.
     */
    public static function scalarOfFile(string $path, string $key): string|int|float|bool|null
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw DatasetUnreadable::cannotOpen($path);
        }

        try {
            return new self($handle)->scalar($key, $path);
        } finally {
            fclose($handle);
        }
    }

    private function scalar(string $key, string $what): string|int|float|bool|null
    {
        if (! $this->seekToValue($key, null)) {
            throw DatasetUnreadable::noSuchSection($what, $key, 'value');
        }

        $next = $this->peek();

        if ($next === '"') {
            $this->take();

            return $this->readStringBody();
        }

        if ($next === null || $next === '{' || $next === '[') {
            throw DatasetUnreadable::unexpectedElement($what, $key, (string) $next);
        }

        $token = '';

        while (($char = $this->peek()) !== null && ! in_array($char, [',', '}', ']', ' ', "\n", "\r", "\t"], true)) {
            $token .= $this->take();
        }

        $value = json_decode($token, flags: JSON_BIGINT_AS_STRING);

        if ($value === null && $token !== 'null' || ! (is_scalar($value) || $value === null)) {
            throw DatasetUnreadable::unexpectedElement($what, $key, $token);
        }

        return $value;
    }

    /**
     * Every member of the OBJECT at a top-level key, as `name => value`.
     *
     * The street artifacts are keyed by ZIP rather than listed, and Georgia's is
     * 38 MB — the same reason the arrays are streamed applies here, one ZIP at a
     * time instead of one record.
     *
     * @return Generator<string, array<string, mixed>>
     */
    public function members(string $key, string $what = 'document'): Generator
    {
        if (! $this->seekToValue($key, null)) {
            throw DatasetUnreadable::noSuchSection($what, $key, 'object');
        }

        // AN EMPTY MAP MAY ARRIVE AS `[]`. That is how PHP encodes one, and Michigan's
        // postal table is exactly that: a state with no ZIP rows. An empty list is
        // read as an empty object; a list with anything in it is not a map at all.
        if ($this->peek() === '[') {
            $this->take();
            $this->skipWhitespace();

            if ($this->take() !== ']') {
                throw DatasetUnreadable::unexpectedElement($what, $key, '[');
            }

            return;
        }

        if ($this->take() !== '{') {
            throw DatasetUnreadable::noSuchSection($what, $key, 'object');
        }

        while (true) {
            $this->skipInsignificant();
            $next = $this->peek();

            if ($next === null) {
                throw DatasetUnreadable::truncated($what, $key);
            }

            if ($next === '}') {
                return;
            }

            if ($next !== '"') {
                throw DatasetUnreadable::unexpectedElement($what, $key, $next);
            }

            $this->take();
            $name = $this->readStringBody();

            $this->skipInsignificant();

            if ($this->take() !== ':') {
                throw DatasetUnreadable::truncated($what, $key);
            }

            $this->skipInsignificant();

            // An object or a list. The street layer maps a ZIP to an object of streets;
            // the postal layer maps a ZIP to a list of span rows. A scalar here is a
            // document that is not the shape asked for.
            if ($this->peek() !== '{' && $this->peek() !== '[') {
                throw DatasetUnreadable::unexpectedElement($what, $key, (string) $this->peek());
            }

            yield $name => $this->readObject($what, $key);
        }
    }

    /**
     * Position the cursor just inside the array belonging to `$key`, at DEPTH 1 of
     * the document — a key of the same name nested inside a record is not the
     * section, and matching it would start reading in the middle of one.
     */
    private function seekToArray(string $key): bool
    {
        return $this->seekToValue($key, '[');
    }

    /** Position the cursor just inside the `[` or `{` belonging to `$key`. */
    private function seekToValue(string $key, ?string $opener): bool
    {
        $depth = 0;

        while (true) {
            // NOTHING BEFORE THE CURSOR IS EVER READ AGAIN on this walk, so the
            // bytes already scanned are dropped rather than accumulated. Without
            // this the buffer grows to hold everything scanned before the key —
            // which is survivable when the key is found early and fatal when it is
            // missing, because then "everything scanned" is the whole document. A
            // key a release has renamed must surface as a clean "no such section",
            // not as an out-of-memory in the middle of a sync.
            if ($this->pos >= $this->chunk) {
                $this->compact();
            }

            $char = $this->scanTo('"{}[]');

            if ($char === null) {
                return false;
            }

            $this->pos++;

            if ($char === '"') {
                $string = $this->readStringBody();

                if ($depth === 1 && $string === $key) {
                    // ONLY WHITESPACE MAY BE CONSUMED HERE. A string equal to the
                    // key is not necessarily the key — `{"description":"rates"}`
                    // holds it as a VALUE — and `skipInsignificant()` also eats
                    // commas, so on a value it swallowed the separator and the
                    // following `take()` ate the next key's opening quote. From
                    // there the scanner reads that key's body as though it were
                    // structure and every match after it is nonsense. Whitespace is
                    // the only thing that can sit between a key and its colon, so
                    // it is the only thing safe to skip before one is confirmed.
                    $this->skipWhitespace();

                    if ($this->peek() !== ':') {
                        continue;
                    }

                    $this->take();
                    $this->skipWhitespace();

                    // No opener asked for: the caller wants whatever value follows.
                    if ($opener === null) {
                        return true;
                    }

                    if ($this->peek() === $opener) {
                        $this->take();

                        return true;
                    }
                }

                continue;
            }

            if ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
            }
        }
    }

    /**
     * Capture one balanced object and decode it alone.
     *
     * Structural bytes are found in BULK with `strcspn` rather than by stepping a
     * byte at a time. That is not a micro-optimisation: byte-stepping the US region
     * took 42 seconds, because almost every byte of this document is the inside of a
     * quoted statute and none of it is structure. Jumping between the bytes that can
     * change state is the difference between a sync you wait for and one you notice.
     *
     * @return array<string, mixed>
     */
    private function readObject(string $what, string $key): array
    {
        $start = $this->pos;
        $depth = 0;

        while (true) {
            $char = $this->scanTo('"{}[]');

            if ($char === null) {
                throw DatasetUnreadable::truncated($what, $key);
            }

            $this->pos++;

            if ($char === '"') {
                $this->skipStringBody();

                continue;
            }

            if ($char === '{' || $char === '[') {
                $depth++;

                continue;
            }

            if ($depth-- === 1) {
                break;
            }
        }

        $raw = substr($this->buffer, $start, $this->pos - $start);
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw DatasetUnreadable::undecodableElement($what, $key);
        }

        $this->compact();

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Consume the rest of a string literal, the opening quote already taken, and
     * return what was inside it.
     *
     * A backslash escapes the NEXT byte whatever it is, which is what keeps `\"`
     * from ending the string and `\\` from escaping the quote that follows it.
     */
    private function readStringBody(): string
    {
        $body = '';

        while (true) {
            $char = $this->take();

            if ($char === null || $char === '"') {
                return $body;
            }

            if ($char === '\\') {
                $body .= $this->take() ?? '';

                continue;
            }

            $body .= $char;
        }
    }

    /**
     * The same walk, without building the string — used when capturing an object,
     * where the bytes are already being kept in the buffer.
     */
    private function skipStringBody(): void
    {
        while (true) {
            $char = $this->scanTo('"\\');

            if ($char === null) {
                return;
            }

            $this->pos++;

            if ($char === '"') {
                return;
            }

            // A backslash: step over whatever it escapes, so an escaped quote or an
            // escaped backslash cannot be mistaken for the end of the string.
            if ($this->ensure(1)) {
                $this->pos++;
            }
        }
    }

    /**
     * Advance to the next byte that is one of `$chars`, refilling as needed, and
     * return it WITHOUT consuming it. Null at end of stream.
     */
    private function scanTo(string $chars): ?string
    {
        while (true) {
            $this->pos += strcspn($this->buffer, $chars, $this->pos);

            if ($this->pos < strlen($this->buffer)) {
                return $this->buffer[$this->pos];
            }

            if (! $this->fill()) {
                return null;
            }
        }
    }

    /** Pull one more chunk in. False once the stream is done. */
    private function fill(): bool
    {
        if ($this->eof) {
            return false;
        }

        $read = fread($this->stream, $this->chunk);

        if ($read === false || $read === '') {
            $this->eof = true;

            return false;
        }

        $this->buffer .= $read;

        return true;
    }

    /**
     * Whitespace only — never the commas `skipInsignificant()` also drops. Used
     * where a comma still carries meaning, which is anywhere a key has not yet been
     * confirmed as a key.
     */
    private function skipWhitespace(): void
    {
        while (true) {
            $char = $this->peek();

            if ($char === ' ' || $char === "\n" || $char === "\r" || $char === "\t") {
                $this->take();

                continue;
            }

            return;
        }
    }

    private function skipInsignificant(): void
    {
        while (true) {
            $char = $this->peek();

            if ($char === null) {
                return;
            }

            if ($char === ' ' || $char === "\n" || $char === "\r" || $char === "\t" || $char === ',') {
                $this->take();

                continue;
            }

            return;
        }
    }

    private function peek(): ?string
    {
        return $this->ensure(1) ? $this->buffer[$this->pos] : null;
    }

    private function take(): ?string
    {
        return $this->ensure(1) ? $this->buffer[$this->pos++] : null;
    }

    /** Whether `$n` more bytes are available, reading from the stream if needed. */
    private function ensure(int $n): bool
    {
        while (! $this->eof && strlen($this->buffer) - $this->pos < $n) {
            $read = fread($this->stream, $this->chunk);

            if ($read === false || $read === '') {
                $this->eof = true;

                break;
            }

            $this->buffer .= $read;
        }

        return strlen($this->buffer) - $this->pos >= $n;
    }

    /**
     * Drop what has been consumed, so the buffer tracks the current record rather
     * than the document. Without this the "streaming" reader would still finish
     * holding all 48 MB.
     */
    private function compact(): void
    {
        if ($this->pos > 0) {
            $this->buffer = substr($this->buffer, $this->pos);
            $this->pos = 0;
        }
    }
}
