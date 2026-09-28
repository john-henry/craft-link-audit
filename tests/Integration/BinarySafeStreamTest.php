<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

// ---------------------------------------------------------------------------
// The CSV export writes its own line endings: fputcsv is given eol "\r\n",
// which is what RFC 4180 asks for and what a spreadsheet expects.
//
// A handle opened in text mode undoes that on Windows, where the stream layer
// translates a \n on the way out. The \n inside a \r\n the export already
// wrote becomes \r\n of its own, every line ends \r\r\n, and the file is
// broken for the one program it exists to be opened in. Nothing shows on
// Linux, so the suite cannot catch it by writing a file and reading it back:
// the mode string is the thing to hold.
// ---------------------------------------------------------------------------

/** Every fopen() call in the plugin's source, as file:line => mode. */
function fopenModes(): array
{
    $src = dirname(__DIR__, 2) . '/src';
    $found = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string)file_get_contents($file->getPathname());

        if (!preg_match_all("/fopen\(\s*[^,]+,\s*'([^']+)'/", $source, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($m[1] as $match) {
            $line = substr_count(substr($source, 0, (int)$match[1]), "\n") + 1;
            $found[basename($file->getPathname()) . ':' . $line] = $match[0];
        }
    }

    return $found;
}

it('finds the stream handles at all', function() {
    // Without this the check below passes on a codebase that opens nothing,
    // which is the state a refactor would leave it in.
    expect(fopenModes())->not->toBeEmpty();
});

it('opens every stream in binary mode', function() {
    $textMode = array_filter(
        fopenModes(),
        static fn(string $mode): bool => !str_contains($mode, 'b'),
    );

    expect($textMode)->toBe([], sprintf(
        "These handles are opened in text mode, which rewrites \\n on Windows:\n  - %s",
        implode("\n  - ", array_map(
            static fn(string $k, string $v): string => "$k → '$v'",
            array_keys($textMode),
            $textMode,
        )),
    ));
});
