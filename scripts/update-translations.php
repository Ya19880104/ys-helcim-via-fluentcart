<?php

declare(strict_types=1);

const YS_HELCIM_I18N_DOMAIN = 'ys-helcim-via-fluentcart';
const YS_HELCIM_I18N_VERSION = '1.1.0-rc.2';

/**
 * Rebuild or verify the plugin POT, zh_TW PO, and compiled MO catalogs.
 *
 * Usage:
 *   php scripts/update-translations.php --root=/path/to/repo
 *   php scripts/update-translations.php --check --root=/path/to/repo
 */

$options = getopt('', array('check', 'root:'));
$checkOnly = array_key_exists('check', $options);
$root = isset($options['root']) ? (string) $options['root'] : dirname(__DIR__);
$root = realpath($root);

if ($root === false || ! is_dir($root)) {
    fwrite(STDERR, "The repository root does not exist.\n");
    exit(2);
}

try {
    $pluginFile = $root . DIRECTORY_SEPARATOR . 'ys-helcim-via-fluentcart.php';
    assert_release_version($pluginFile);

    $sourceFiles = list_source_files($root, $pluginFile);
    $messages = extract_messages($root, $sourceFiles);
    if ($messages === array()) {
        throw new RuntimeException('No translatable messages were found.');
    }

    $languageDir = $root . DIRECTORY_SEPARATOR . 'languages';
    $potPath = $languageDir . DIRECTORY_SEPARATOR . 'ys-helcim-via-fluentcart.pot';
    $poPath = $languageDir . DIRECTORY_SEPARATOR . 'ys-helcim-via-fluentcart-zh_TW.po';
    $moPath = $languageDir . DIRECTORY_SEPARATOR . 'ys-helcim-via-fluentcart-zh_TW.mo';

    $existingTranslations = is_file($poPath) ? parse_po_translations($poPath) : array();
    $translations = merge_translations($messages, $existingTranslations);
    $expected = array(
        $potPath => render_pot($messages),
        $poPath  => render_po($messages, $translations),
        $moPath  => render_mo($messages, $translations),
    );

    if ($checkOnly) {
        $stale = array();
        foreach ($expected as $path => $contents) {
            if (! is_file($path) || file_get_contents($path) !== $contents) {
                $stale[] = basename($path);
            }
        }

        if ($stale !== array()) {
            fwrite(
                STDERR,
                'Translation catalogs are stale: ' . implode(', ', $stale)
                . ". Run php scripts/update-translations.php to rebuild them.\n"
            );
            exit(1);
        }
    } else {
        if (! is_dir($languageDir) && ! mkdir($languageDir, 0777, true) && ! is_dir($languageDir)) {
            throw new RuntimeException('Unable to create the languages directory.');
        }

        foreach ($expected as $path => $contents) {
            if (file_put_contents($path, $contents) === false) {
                throw new RuntimeException('Unable to write ' . $path);
            }
        }
    }

    $preserved = 0;
    foreach ($messages as $key => $_message) {
        if (isset($existingTranslations[$key]) && translation_has_content($existingTranslations[$key])) {
            ++$preserved;
        }
    }
    $mode = $checkOnly ? 'verified' : 'updated';
    fwrite(
        STDOUT,
        sprintf(
            "OK translations %s: messages=%d preserved=%d fallback=%d\n",
            $mode,
            count($messages),
            $preserved,
            count($messages) - $preserved
        )
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'Translation build failed: ' . $error->getMessage() . "\n");
    exit(2);
}

/** @return list<string> */
function list_source_files(string $root, string $pluginFile): array
{
    if (! is_file($pluginFile)) {
        throw new RuntimeException('Missing plugin bootstrap: ' . $pluginFile);
    }

    $files = array($pluginFile);
    $srcDir = $root . DIRECTORY_SEPARATOR . 'src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files, SORT_STRING);

    return $files;
}

function assert_release_version(string $pluginFile): void
{
    $source = file_get_contents($pluginFile);
    if ($source === false || ! preg_match('/^[ \t*]*Version:\s*([^\r\n]+)/mi', $source, $matches)) {
        throw new RuntimeException('The plugin Version header could not be read.');
    }

    if (trim($matches[1]) !== YS_HELCIM_I18N_VERSION) {
        throw new RuntimeException(
            sprintf(
                'Plugin version %s does not match translation release version %s.',
                trim($matches[1]),
                YS_HELCIM_I18N_VERSION
            )
        );
    }
}

/**
 * @param list<string> $sourceFiles
 * @return array<string,array{msgid:string,plural:?string,context:?string,refs:array<string,true>,comments:array<string,true>,php_format:bool}>
 */
function extract_messages(string $root, array $sourceFiles): array
{
    $specs = array(
        '__'          => array('msgid' => 0, 'domain' => 1),
        '_e'          => array('msgid' => 0, 'domain' => 1),
        'esc_html__'  => array('msgid' => 0, 'domain' => 1),
        'esc_attr__'  => array('msgid' => 0, 'domain' => 1),
        'esc_html_e'  => array('msgid' => 0, 'domain' => 1),
        'esc_attr_e'  => array('msgid' => 0, 'domain' => 1),
        '_x'          => array('msgid' => 0, 'context' => 1, 'domain' => 2),
        '_ex'         => array('msgid' => 0, 'context' => 1, 'domain' => 2),
        'esc_html_x'  => array('msgid' => 0, 'context' => 1, 'domain' => 2),
        'esc_attr_x'  => array('msgid' => 0, 'context' => 1, 'domain' => 2),
        '_n'          => array('msgid' => 0, 'plural' => 1, 'domain' => 3),
        '_nx'         => array('msgid' => 0, 'plural' => 1, 'context' => 3, 'domain' => 4),
    );
    $wrapperSpecs = array(
        // These helpers forward literal call-site messages through __() with
        // the fixed plugin domain. Keeping the mapping file-scoped prevents
        // unrelated error()/logger calls from becoming catalog entries.
        'src/HelcimJs/YSHelcimJsPurchaseRuntime.php' => array(
            'runtimeError' => array('msgid' => 1, 'domain' => null),
        ),
        'src/HelcimPay/YSHelcimPayConfirmationService.php' => array(
            'error' => array('msgid' => 1, 'domain' => null),
        ),
        'src/HelcimPay/YSHelcimPayRecoveryService.php' => array(
            'error' => array('msgid' => 1, 'domain' => null),
        ),
        'src/HelcimPay/YSHelcimPayProcessor.php' => array(
            'error' => array('msgid' => 1, 'domain' => null),
            'neverSentError' => array('msgid' => 1, 'domain' => null),
            // One call forwards an already translated WP_Error message from
            // YSHelcimPayConfirmationService; literal call sites are cataloged.
            'rejectConfirm' => array('msgid' => 2, 'domain' => null, 'allow_dynamic' => true),
        ),
    );
    $dynamicForwarderExpected = array(
        'src/HelcimJs/YSHelcimJsPurchaseRuntime.php' => 1,
        'src/HelcimPay/YSHelcimPayConfirmationService.php' => 1,
        'src/HelcimPay/YSHelcimPayRecoveryService.php' => 1,
        'src/HelcimPay/YSHelcimPayProcessor.php' => 3,
    );
    $dynamicForwarderSeen = array_fill_keys(array_keys($dynamicForwarderExpected), 0);
    $messages = array();
    $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';

    foreach ($sourceFiles as $file) {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException('Unable to read ' . $file);
        }
        $tokens = token_get_all($source);
        $count = count($tokens);
        $relative = str_replace('\\', '/', $file);
        if (strpos($relative, $rootPrefix) !== 0) {
            throw new RuntimeException('Source file escaped repository root: ' . $file);
        }
        $relative = substr($relative, strlen($rootPrefix));
        $fileSpecs = $specs + ($wrapperSpecs[$relative] ?? array());

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (! is_array($token) || $token[0] !== T_STRING || ! isset($fileSpecs[$token[1]])) {
                continue;
            }

            $function = $token[1];
            $functionIndex = $index;
            $open = next_significant_token($tokens, $index + 1);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            list($arguments, $close) = parse_call_arguments($tokens, $open);
            $index = $close;
            $spec = $fileSpecs[$function];
            if ($spec['domain'] !== null) {
                $domain = literal_argument($arguments, (int) $spec['domain']);
                if ($domain !== null && $domain !== YS_HELCIM_I18N_DOMAIN) {
                    continue;
                }
                if ($domain === null) {
                    throw new RuntimeException(sprintf('%s:%d has a non-literal text domain.', $relative, $token[2]));
                }
            }

            $msgid = literal_argument($arguments, (int) $spec['msgid']);
            $plural = isset($spec['plural']) ? literal_argument($arguments, (int) $spec['plural']) : null;
            $context = isset($spec['context']) ? literal_argument($arguments, (int) $spec['context']) : null;
            if ($msgid === null || (isset($spec['plural']) && $plural === null) || (isset($spec['context']) && $context === null)) {
                $previous = previous_significant_token($tokens, $functionIndex - 1);
                $isWrapperDefinition = ! isset($specs[$function])
                    && $previous !== null
                    && is_array($tokens[$previous])
                    && $tokens[$previous][0] === T_FUNCTION;
                $isKnownDynamicForwarder = isset($specs[$function])
                    && $function === '__'
                    && isset($dynamicForwarderExpected[$relative])
                    && variable_argument($arguments, 0) === 'message';
                if ($isKnownDynamicForwarder) {
                    ++$dynamicForwarderSeen[$relative];
                }
                $isAllowedWrapperForwarding = ! isset($specs[$function])
                    && ! empty($spec['allow_dynamic']);
                if ($isWrapperDefinition || $isKnownDynamicForwarder || $isAllowedWrapperForwarding) {
                    continue;
                }

                throw new RuntimeException(sprintf('%s:%d has a non-literal translatable message.', $relative, $token[2]));
            }

            $key = ($context === null ? '' : $context . "\x04") . $msgid;
            if (! isset($messages[$key])) {
                $messages[$key] = array(
                    'msgid'      => $msgid,
                    'plural'     => $plural,
                    'context'    => $context,
                    'refs'       => array(),
                    'comments'   => array(),
                    'php_format' => has_php_placeholder($msgid) || ($plural !== null && has_php_placeholder($plural)),
                );
            } elseif ($messages[$key]['plural'] !== $plural) {
                throw new RuntimeException('Conflicting plural definitions for message: ' . $msgid);
            }
            $messages[$key]['refs'][$relative . ':' . $token[2]] = true;
            $translatorComment = find_translator_comment($tokens, $functionIndex);
            if ($translatorComment !== null) {
                $messages[$key]['comments'][$translatorComment] = true;
            }
        }
    }

    foreach ($dynamicForwarderExpected as $path => $expectedCount) {
        if (($dynamicForwarderSeen[$path] ?? 0) !== $expectedCount) {
            throw new RuntimeException(
                sprintf(
                    '%s has an unexpected number of dynamic translation forwarders (expected %d, found %d).',
                    $path,
                    $expectedCount,
                    $dynamicForwarderSeen[$path] ?? 0
                )
            );
        }
    }

    uksort($messages, 'strcmp');

    return $messages;
}

/** @param array<int,mixed> $tokens */
function next_significant_token(array $tokens, int $start): ?int
{
    for ($index = $start, $count = count($tokens); $index < $count; ++$index) {
        $token = $tokens[$index];
        if (is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        return $index;
    }

    return null;
}

/** @param array<int,mixed> $tokens */
function previous_significant_token(array $tokens, int $start): ?int
{
    for ($index = $start; $index >= 0; --$index) {
        $token = $tokens[$index];
        if (is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        return $index;
    }

    return null;
}

/** @param array<int,mixed> $tokens */
function find_translator_comment(array $tokens, int $functionIndex): ?string
{
    $remaining = 80;
    for ($index = $functionIndex - 1; $index >= 0 && $remaining-- > 0; --$index) {
        $token = $tokens[$index];
        if (is_string($token) && in_array($token, array(';', '{', '}'), true)) {
            break;
        }
        if (! is_array($token) || ! in_array($token[0], array(T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        if (stripos($token[1], 'translators:') === false) {
            continue;
        }

        $comment = preg_replace('/^\s*(?:\/\*+|\/\/)\s*|\s*\*\/\s*$/', '', $token[1]);
        $comment = preg_replace('/\s+/', ' ', (string) $comment);
        $comment = trim((string) $comment);
        return $comment === '' ? null : $comment;
    }

    return null;
}

/**
 * @param array<int,mixed> $tokens
 * @return array{0:list<list<mixed>>,1:int}
 */
function parse_call_arguments(array $tokens, int $open): array
{
    $arguments = array();
    $current = array();
    $round = 0;
    $square = 0;
    $curly = 0;

    for ($index = $open + 1, $count = count($tokens); $index < $count; ++$index) {
        $token = $tokens[$index];
        if ($token === '(') {
            ++$round;
        } elseif ($token === ')') {
            if ($round === 0 && $square === 0 && $curly === 0) {
                $arguments[] = $current;
                return array($arguments, $index);
            }
            --$round;
        } elseif ($token === '[') {
            ++$square;
        } elseif ($token === ']') {
            --$square;
        } elseif ($token === '{') {
            ++$curly;
        } elseif ($token === '}') {
            --$curly;
        } elseif ($token === ',' && $round === 0 && $square === 0 && $curly === 0) {
            $arguments[] = $current;
            $current = array();
            continue;
        }
        $current[] = $token;
    }

    throw new RuntimeException('Unterminated translation function call.');
}

/** @param list<list<mixed>> $arguments */
function literal_argument(array $arguments, int $position): ?string
{
    if (! isset($arguments[$position])) {
        return null;
    }

    $significant = array_values(array_filter(
        $arguments[$position],
        static function ($token): bool {
            return ! (is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true));
        }
    ));
    if (count($significant) !== 1 || ! is_array($significant[0]) || $significant[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
        return null;
    }

    return decode_php_literal($significant[0][1]);
}

/** @param list<list<mixed>> $arguments */
function variable_argument(array $arguments, int $position): ?string
{
    if (! isset($arguments[$position])) {
        return null;
    }

    $significant = array_values(array_filter(
        $arguments[$position],
        static function ($token): bool {
            return ! (is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true));
        }
    ));
    if (count($significant) !== 1 || ! is_array($significant[0]) || $significant[0][0] !== T_VARIABLE) {
        return null;
    }

    return ltrim($significant[0][1], '$');
}

function decode_php_literal(string $literal): string
{
    $quote = $literal[0] ?? '';
    $inner = substr($literal, 1, -1);
    if ($quote === '"') {
        return stripcslashes($inner);
    }
    if ($quote !== "'") {
        throw new RuntimeException('Unsupported PHP string literal.');
    }

    $decoded = '';
    $length = strlen($inner);
    for ($index = 0; $index < $length; ++$index) {
        if ($inner[$index] === '\\' && $index + 1 < $length && ($inner[$index + 1] === '\\' || $inner[$index + 1] === "'")) {
            $decoded .= $inner[++$index];
        } else {
            $decoded .= $inner[$index];
        }
    }

    return $decoded;
}

function has_php_placeholder(string $message): bool
{
    return preg_match('/(?<!%)%(?:\d+\$)?[-+0-9\'.]*[bcdeEfFgGosuxX]/', $message) === 1;
}

/**
 * @return array<string,array{singular:string,plural:list<string>}>
 */
function parse_po_translations(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read existing translation file: ' . $path);
    }

    $translations = array();
    $blocks = preg_split('/(?:\r?\n){2,}/', trim($contents));
    foreach ($blocks === false ? array() : $blocks as $block) {
        $fields = array();
        $current = null;
        foreach (preg_split('/\r?\n/', $block) ?: array() as $line) {
            if (preg_match('/^(msgctxt|msgid|msgid_plural|msgstr(?:\[(\d+)\])?)\s+(".*")$/', $line, $matches)) {
                $current = $matches[1];
                $fields[$current] = decode_po_quoted($matches[3]);
            } elseif ($current !== null && preg_match('/^(".*")$/', $line, $matches)) {
                $fields[$current] .= decode_po_quoted($matches[1]);
            }
        }

        $msgid = $fields['msgid'] ?? null;
        if ($msgid === null || $msgid === '') {
            continue;
        }
        $context = $fields['msgctxt'] ?? null;
        $key = ($context === null ? '' : $context . "\x04") . $msgid;
        $plural = array();
        foreach ($fields as $field => $value) {
            if (preg_match('/^msgstr\[(\d+)\]$/', $field, $matches)) {
                $plural[(int) $matches[1]] = $value;
            }
        }
        ksort($plural, SORT_NUMERIC);
        $translations[$key] = array(
            'singular' => $fields['msgstr'] ?? '',
            'plural'   => array_values($plural),
        );
    }

    return $translations;
}

function decode_po_quoted(string $quoted): string
{
    $value = json_decode($quoted, true);
    if (! is_string($value)) {
        throw new RuntimeException('Invalid quoted PO value: ' . $quoted);
    }

    return $value;
}

/**
 * @param array<string,array{msgid:string,plural:?string,context:?string,refs:array<string,true>,comments:array<string,true>,php_format:bool}> $messages
 * @param array<string,array{singular:string,plural:list<string>}> $existing
 * @return array<string,array{singular:string,plural:list<string>}>
 */
function merge_translations(array $messages, array $existing): array
{
    $translations = array();
    foreach ($messages as $key => $message) {
        $prior = $existing[$key] ?? array('singular' => '', 'plural' => array());
        if ($message['plural'] === null) {
            $translations[$key] = array(
                'singular' => $prior['singular'] !== '' ? $prior['singular'] : $message['msgid'],
                'plural'   => array(),
            );
        } else {
            $fallback = $message['msgid'];
            $translations[$key] = array(
                'singular' => '',
                'plural'   => array(($prior['plural'][0] ?? '') !== '' ? $prior['plural'][0] : $fallback),
            );
        }
    }

    return $translations;
}

/** @param array{singular:string,plural:list<string>} $translation */
function translation_has_content(array $translation): bool
{
    if ($translation['singular'] !== '') {
        return true;
    }
    foreach ($translation['plural'] as $value) {
        if ($value !== '') {
            return true;
        }
    }
    return false;
}

/** @param array<string,array{msgid:string,plural:?string,context:?string,refs:array<string,true>,comments:array<string,true>,php_format:bool}> $messages */
function render_pot(array $messages): string
{
    $output = "# Copyright (C) 2026 YS\n";
    $output .= "# This file is distributed under the same license as YS Helcim via FluentCart.\n";
    $output .= "#, fuzzy\nmsgid \"\"\nmsgstr \"\"\n";
    $output .= render_header_lines(pot_header());
    foreach ($messages as $message) {
        $output .= "\n" . render_message($message, null, true);
    }
    return $output;
}

/**
 * @param array<string,array{msgid:string,plural:?string,context:?string,refs:array<string,true>,comments:array<string,true>,php_format:bool}> $messages
 * @param array<string,array{singular:string,plural:list<string>}> $translations
 */
function render_po(array $messages, array $translations): string
{
    $output = "# Traditional Chinese translations for YS Helcim via FluentCart.\n";
    $output .= "# This file is distributed under the same license as YS Helcim via FluentCart.\n";
    $output .= "msgid \"\"\nmsgstr \"\"\n";
    $output .= render_header_lines(po_header());
    foreach ($messages as $key => $message) {
        $output .= "\n" . render_message($message, $translations[$key], false);
    }
    return $output;
}

/**
 * @param array{msgid:string,plural:?string,context:?string,refs:array<string,true>,comments:array<string,true>,php_format:bool} $message
 * @param array{singular:string,plural:list<string>}|null $translation
 */
function render_message(array $message, ?array $translation, bool $template): string
{
    $refs = array_keys($message['refs']);
    sort($refs, SORT_STRING);
    $comments = array_keys($message['comments']);
    sort($comments, SORT_STRING);
    $output = '';
    foreach ($comments as $comment) {
        $output .= '#. ' . $comment . "\n";
    }
    $output .= '#: ' . implode(' ', $refs) . "\n";
    if ($message['php_format']) {
        $output .= "#, php-format\n";
    }
    if ($message['context'] !== null) {
        $output .= 'msgctxt ' . po_quote($message['context']) . "\n";
    }
    $output .= 'msgid ' . po_quote($message['msgid']) . "\n";
    if ($message['plural'] !== null) {
        $output .= 'msgid_plural ' . po_quote($message['plural']) . "\n";
        $output .= 'msgstr[0] ' . po_quote($template ? '' : ($translation['plural'][0] ?? $message['msgid'])) . "\n";
        if ($template) {
            $output .= "msgstr[1] \"\"\n";
        }
    } else {
        $output .= 'msgstr ' . po_quote($template ? '' : ($translation['singular'] ?? $message['msgid'])) . "\n";
    }
    return $output;
}

function po_quote(string $value): string
{
    return '"' . str_replace(
        array('\\', '"', "\t", "\r", "\n"),
        array('\\\\', '\\"', '\\t', '\\r', '\\n'),
        $value
    ) . '"';
}

/** @param list<string> $headers */
function render_header_lines(array $headers): string
{
    $output = '';
    foreach ($headers as $header) {
        $output .= po_quote($header . "\n") . "\n";
    }
    return $output;
}

/** @return list<string> */
function pot_header(): array
{
    return array(
        'Project-Id-Version: YS Helcim via FluentCart ' . YS_HELCIM_I18N_VERSION,
        'Report-Msgid-Bugs-To: ',
        'POT-Creation-Date: 2026-07-22 00:00+0000',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Domain: ' . YS_HELCIM_I18N_DOMAIN,
    );
}

/** @return list<string> */
function po_header(): array
{
    return array(
        'Project-Id-Version: YS Helcim via FluentCart ' . YS_HELCIM_I18N_VERSION,
        'PO-Revision-Date: 2026-07-22 00:00+0800',
        'Language: zh_TW',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Plural-Forms: nplurals=1; plural=0;',
        'X-Domain: ' . YS_HELCIM_I18N_DOMAIN,
    );
}

/**
 * @param array<string,array{msgid:string,plural:?string,context:?string,refs:array<string,true>,comments:array<string,true>,php_format:bool}> $messages
 * @param array<string,array{singular:string,plural:list<string>}> $translations
 */
function render_mo(array $messages, array $translations): string
{
    $entries = array('' => implode("\n", po_header()) . "\n");
    foreach ($messages as $key => $message) {
        $original = ($message['context'] === null ? '' : $message['context'] . "\x04") . $message['msgid'];
        if ($message['plural'] !== null) {
            $original .= "\0" . $message['plural'];
            $translated = $translations[$key]['plural'][0] ?? $message['msgid'];
        } else {
            $translated = $translations[$key]['singular'] ?? $message['msgid'];
        }
        $entries[$original] = $translated;
    }
    ksort($entries, SORT_STRING);

    $count = count($entries);
    $originalTableOffset = 28;
    $translationTableOffset = $originalTableOffset + (8 * $count);
    $originalDataOffset = $translationTableOffset + (8 * $count);
    $originalTable = '';
    $originalData = '';
    foreach (array_keys($entries) as $original) {
        $originalTable .= pack('V2', strlen($original), $originalDataOffset + strlen($originalData));
        $originalData .= $original . "\0";
    }

    $translationDataOffset = $originalDataOffset + strlen($originalData);
    $translationTable = '';
    $translationData = '';
    foreach ($entries as $translated) {
        $translationTable .= pack('V2', strlen($translated), $translationDataOffset + strlen($translationData));
        $translationData .= $translated . "\0";
    }

    return pack(
        'V7',
        0x950412de,
        0,
        $count,
        $originalTableOffset,
        $translationTableOffset,
        0,
        0
    ) . $originalTable . $translationTable . $originalData . $translationData;
}
