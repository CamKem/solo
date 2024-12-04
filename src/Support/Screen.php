<?php

namespace AaronFrancis\Solo\Support;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HigherOrderCollectionProxy;
use SplQueue;

class Screen
{
    const ANSI_CODE_REGEX = '/
    (
        \e\[
            [0-9;?]*      # Parameters: digits, semicolons, question marks
            [a-zA-Z]      # Command: a single letter
        |
        \e[78]            # Standalone ESC 7 or ESC 8
    )
/x';

    const ANSI_CODE_PARTS_REGEX = '/
        (?P<params>   
            [0-9;?]*    # Capture the params
        )     
        (?P<command>  
            [a-zA-Z78]  # Capture the command (alphabetic characters and 7,8)
        )    
/x';

    public AnsiTracker $ansi;

    public Buffer $buffer;

    /**
     * A higher-order collection of both the Screen and ANSI buffers
     * so we can call methods on both of them at once. The type-
     * hint doesn't match the actual property type on purpose.
     *
     * @var Buffer
     *
     * @noinspection PhpDocFieldTypeMismatchInspection
     */
    public HigherOrderCollectionProxy $bothBuffers;

    public int $cursorRow = 0;

    public int $cursorCol = 0;

    public int $linesOffScreen = 0;

    public int $width;

    public int $height;

    protected bool $unflushedAnsi = false;

    protected array $stashedCursor = [];

    public function __construct(int $width, int $height)
    {
        $this->width = $width;
        $this->height = $height;
        $this->ansi = new AnsiTracker;
        $this->buffer = new Buffer(usesStrings: true);

        $this->bothBuffers = collect([$this->ansi->buffer, $this->buffer])->each;
    }

    public function output(): string
    {
        // Get the most minimal representation of the ANSI
        // buffer possible, eliminating all duplicates.
        $ansi = $this->ansi->compressedAnsiBuffer();

        $buffer = $this->buffer->getBuffer();

        foreach ($buffer as $k => &$line) {
            // At this point, the keys represent the column where the ANSI code should
            // be placed in the string and the values are the ANSI strings.
            $ansiForLine = $ansi[$k] ?? [];

            // Sort them in reverse by position so that we can start at the end of the
            // string and work backwards so that all positions remain valid.
            krsort($ansiForLine);

            // Now, work backwards through the line inserting the codes.
            foreach ($ansiForLine as $pos => $code) {
                $line = mb_substr($line, 0, $pos, 'UTF-8') . $code . mb_substr($line, $pos, null, 'UTF-8');
            }

        }

        return implode(PHP_EOL, $buffer);
    }

    public function write(string $content): void
    {
        // Carriage returns get replaced with a code to move to column 0.
        $content = str_replace("\r", "\e[G", $content);

        // Split the line by ANSI codes. Each item in the resulting array
        // will be a set of printable characters or an ANSI code.
        $parts = preg_split(
            static::ANSI_CODE_REGEX, $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        $i = 0;

        while ($i < count($parts)) {
            $part = $parts[$i];


            if (str_starts_with($part, "\e")) {
                // Split out the ANSI code by its command and optional parameters.
                preg_match(static::ANSI_CODE_PARTS_REGEX, $part, $matches);

                if (Arr::has($matches, ['command', 'params'])) {
                    $this->handleAnsiCode($matches['command'], $matches['params']);
                } else {
                    Log::error('Unknown ANSI match:', [
                        'line' => $content,
                        'part' => $part,
                    ]);
                }
            } else {
                $lines = explode(PHP_EOL, $part);

                foreach ($lines as $index => $line) {
                    $this->handlePrintableCharacters($line);

                    if ($index < count($lines) - 1) {
                        $this->newlineWithScroll();
                    }
                }
            }

            $i++;
        }

        // There may be some ANSI codes that we're keeping track of that have
        // not been written into the buffer, since they are written during
        // the `handlePrintableCharacters` method. Any ANSI codes not
        // followed by printable characters will never get written.
        // We can fix that here by checking the flag.
        if ($this->unflushedAnsi) {
            // Add the ANSI at the exact point of the cursor.
            $this->ansi->fillBufferWithActiveFlags(
                row: $this->cursorRow,
                startCol: $this->cursorCol,
                endCol: $this->cursorCol,
            );

            $this->unflushedAnsi = false;
        }
    }

    protected function handleAnsiCode($command, $param)
    {
        // Some commands have a default of zero and some have a default of one. Just
        // make both options and decide within the body of the if statement.
        // We could do a match here but it doesn't seem worth it.
        $paramDefaultZero = ($param !== '' && is_numeric($param)) ? intval($param) : 0;
        $paramDefaultOne = ($param !== '' && is_numeric($param)) ? intval($param) : 1;

        if ($command === 'A') {
            // Cursor up
            $this->moveCursorRow(relative: -$paramDefaultOne);

        } elseif ($command === 'B') {
            // Cursor down
            $this->moveCursorRow(relative: $paramDefaultOne);

        } elseif ($command === 'C') {
            // Cursor forward
            $this->moveCursorCol(relative: $paramDefaultOne);

        } elseif ($command === 'D') {
            // Cursor backward
            $this->moveCursorCol(relative: -$paramDefaultOne);

        } elseif ($command === 'E') {
            // Cursor to beginning of line, a number of lines down
            $this->moveCursorRow(relative: $paramDefaultOne);
            $this->moveCursorCol(absolute: 0);

        } elseif ($command === 'F') {
            // Cursor to beginning of line, a number of lines up
            $this->moveCursorRow(relative: -$paramDefaultOne);
            $this->moveCursorCol(absolute: 0);

        } elseif ($command === 'G') {
            // Cursor to column #, accounting for one-based indexing.
            $this->moveCursorCol($paramDefaultOne - 1);

        } elseif ($command === 'H') {
            // Cursor home
            $this->moveCursorRow(absolute: 0);
            $this->moveCursorCol(absolute: 0);

        } elseif ($command === 'J') {
            $this->handleEraseDisplay($paramDefaultZero);

        } elseif ($command === 'K') {
            $this->handleEraseInLine($paramDefaultZero);

        } elseif ($command === 'l' || $command === 'h') {
            // Show/hide cursor. We simply ignore these.

        } elseif ($command === 'm') {
            // Colors / graphics mode
            $this->handleSGR($param);
        } elseif ($command === '7') {
            $this->saveCursor();
        } elseif ($command === '8') {
            $this->restoreCursor();
        }

        // @TODO Unhandled ansi command. Throw an error? Log it?
    }

    protected function newlineWithScroll()
    {
        if ($this->cursorRow >= $this->height - 1) {
            $this->linesOffScreen++;
        }

        $this->moveCursorRow(relative: 1);
        $this->moveCursorCol(absolute: 0);
    }

    protected function handlePrintableCharacters(string $text): void
    {
        $this->buffer->expand($this->cursorRow);

        $lineContent = $this->buffer[$this->cursorRow];

        // If cursorCol is beyond current line length, pad with spaces.
        $paddingRequired = $this->cursorCol - mb_strlen($lineContent, 'UTF-8');
        if ($paddingRequired > 0) {
            $lineContent .= str_repeat(' ', $paddingRequired);
        }

        $spaceRemaining = $this->width - $this->cursorCol;

        // Text that doesn't fit on this line. We'll recursively call
        // this function at the very end to add it to a new line.
        $remainder = mb_substr($text, $spaceRemaining, null, 'UTF-8');

        // The text that can fit on this line.
        $text = mb_substr($text, 0, $spaceRemaining, 'UTF-8');

        // The part of the line before the cursor.
        $before = mb_substr($lineContent, 0, $this->cursorCol, 'UTF-8');

        // The part of the line after the cursor *and* after our new content.
        // It's possible we overwrote some characters, which is correct,
        // but we might not have overwritten everything, so we
        // need to append any leftovers.
        $after = mb_substr($lineContent, $this->cursorCol + mb_strlen($text, 'UTF-8'), null, 'UTF-8');

        $this->buffer[$this->cursorRow] = $before . $text . $after;

        $startCol = $this->cursorCol;

        // Update the cursor position forward by the length of the text
        $this->moveCursorCol(absolute: mb_strlen($before . $text, 'UTF-8'));

        // Fill the ANSI buffer with currently active flags, based
        // on where the cursor started and where it ended.
        $this->ansi->fillBufferWithActiveFlags($this->cursorRow, $startCol, max($startCol, $this->cursorCol - 1));

        // We no longer have ANSI codes that haven't been written
        // into the ANSI buffer, so flip the flag.
        $this->unflushedAnsi = false;

        if (!empty($remainder)) {
            $this->newlineWithScroll();
            $this->handlePrintableCharacters($remainder);
        }
    }

    public function saveCursor()
    {
        $this->stashedCursor = [
            $this->cursorCol,
            $this->cursorRow - $this->linesOffScreen
        ];
    }

    public function restoreCursor()
    {
        if ($this->stashedCursor) {
            [$col, $row] = $this->stashedCursor;
            $this->moveCursorCol(absolute: $col);
            $this->moveCursorRow(absolute: $row + $this->linesOffScreen);
            $this->stashedCursor = [];
        }
    }

    public function moveCursorCol(?int $absolute = null, ?int $relative = null)
    {
        $this->ensureCursorParams($absolute, $relative);

        // Inside this method, position is zero-based.

        $max = $this->width;
        $min = 0;

        $position = $this->cursorCol;

        if (!is_null($absolute)) {
            $position = $absolute;
        }

        if (!is_null($relative)) {
            // Relative movements cannot put the cursor at the very end, only absolute
            // movements can. Not sure why, but I verified the behavior manually.
            $max -= 1;
            $position += $relative;
        }

        $position = min($position, $max);
        $position = max($min, $position);

        $this->cursorCol = $position;
    }

    public function moveCursorRow(?int $absolute = null, ?int $relative = null)
    {
        $this->ensureCursorParams($absolute, $relative);

        $max = $this->height + $this->linesOffScreen;
        $min = $this->linesOffScreen;

        $position = $this->cursorRow;

        if (!is_null($absolute)) {
            $position = $absolute;
        }

        if (!is_null($relative)) {
            $position += $relative;
        }

        $position = min($position, $max);
        $position = max($min, $position);

        $this->cursorRow = $position;

        $this->buffer->expand($this->cursorRow);
    }

    protected function moveCursor(string $direction, ?int $absolute = null, ?int $relative = null): void
    {
        $this->ensureCursorParams($absolute, $relative);

        $property = $direction === 'x' ? 'cursorCol' : 'cursorRow';
        $max = $direction === 'x' ? $this->width : ($this->height + $this->linesOffScreen);
        $min = $direction === 'x' ? 0 : $this->linesOffScreen;

        if (!is_null($absolute)) {
            $this->{$property} = $absolute;
        }

        if (!is_null($relative)) {
            $this->{$property} += $relative;
        }

        $this->{$property} = min(
            max($this->{$property}, $min),
            $max - 1
        );
    }

    protected function ensureCursorParams($absolute, $relative): void
    {
        if (!is_null($absolute) && !is_null($relative)) {
            throw new Exception('Use either relative or absolute, but not both.');
        }

        if (is_null($absolute) && is_null($relative)) {
            throw new Exception('Relative and absolute cannot both be blank.');
        }
    }

    /**
     * Handle SGR (Select Graphic Rendition) ANSI codes for colors and styles.
     */
    protected function handleSGR(string $params): void
    {
        $this->unflushedAnsi = true;

        // Support multiple codes, like \e[30;41m
        $codes = array_map(intval(...), explode(';', $params));

        $this->ansi->addAnsiCodes(...$codes);
    }

    protected function handleEraseDisplay(int $param): void
    {
        if ($param === 0) {
            // \e[0J - Erase from cursor until end of screen
            $this->bothBuffers->clear(
                startRow: $this->cursorRow,
                startCol: $this->cursorCol
            );
        } elseif ($param === 1) {
            // \e[1J - Erase from cursor until beginning of screen
            $this->bothBuffers->clear(
                startRow: $this->linesOffScreen,
                endRow: $this->cursorRow,
                endCol: $this->cursorCol
            );
        } elseif ($param === 2) {
            // \e[2J - Erase entire screen
            $this->bothBuffers->clear(
                startRow: $this->linesOffScreen,
                endRow: $this->linesOffScreen + $this->height,
            );
        }
    }

    protected function handleEraseInLine(int $param): void
    {
        if ($param === 0) {
            // \e[0K - Erase from cursor to end of line
            $this->bothBuffers->clear(
                startRow: $this->cursorRow,
                startCol: $this->cursorCol,
                endRow: $this->cursorRow
            );

        } elseif ($param == 1) {
            // \e[1K - Erase start of line to the cursor
            $this->bothBuffers->clear(
                startRow: $this->cursorRow,
                endRow: $this->cursorRow,
                endCol: $this->cursorCol
            );
        } elseif ($param === 2) {
            //\e[2K - Erase the entire line
            $this->bothBuffers->clear(
                startRow: $this->cursorRow,
                endRow: $this->cursorRow
            );
        }
    }
}
