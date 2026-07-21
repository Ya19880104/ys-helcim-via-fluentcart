<?php

declare(strict_types=1);

/**
 * Verify that every checkout t('key') call has one exact server-provided
 * translation key and that obsolete/misspelled server keys cannot drift.
 */

$options = getopt('', array('root:'));
$root = isset($options['root']) ? realpath((string) $options['root']) : dirname(__DIR__);
if ($root === false || ! is_dir($root)) {
    fwrite(STDERR, "The repository root does not exist.\n");
    exit(2);
}

$contracts = array(
    'hosted' => array(
        'javascript' => 'assets/js/ys-helcim-pay-checkout.js',
        'gateway'    => 'src/HelcimPay/YSHelcimPayGateway.php',
    ),
    'inline' => array(
        'javascript' => 'assets/js/ys-helcim-js-checkout.js',
        'gateway'    => 'src/HelcimJs/YSHelcimJsGateway.php',
    ),
);

$failed = false;
foreach ($contracts as $name => $contract) {
    try {
        $javascriptPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $contract['javascript']);
        $gatewayPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $contract['gateway']);
        $used = extract_javascript_translation_keys(read_source($javascriptPath));
        $provided = extract_php_translation_keys(read_source($gatewayPath));

        $missing = array_values(array_diff($used, $provided));
        $unused = array_values(array_diff($provided, $used));
        if ($missing !== array() || $unused !== array()) {
            $failed = true;
            fwrite(STDERR, sprintf("%s checkout translation key mismatch:\n", $name));
            if ($missing !== array()) {
                fwrite(STDERR, '  missing server keys: ' . implode(', ', $missing) . "\n");
            }
            if ($unused !== array()) {
                fwrite(STDERR, '  unused server keys: ' . implode(', ', $unused) . "\n");
            }
            continue;
        }

        fwrite(STDOUT, sprintf("OK %s front-end translations: keys=%d\n", $name, count($used)));
    } catch (Throwable $error) {
        $failed = true;
        fwrite(STDERR, sprintf("%s translation contract failed: %s\n", $name, $error->getMessage()));
    }
}

exit($failed ? 1 : 0);

function read_source(string $path): string
{
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

/** @return list<string> */
function extract_javascript_translation_keys(string $source): array
{
    preg_match_all('/\bt\(\s*([\'\"])([A-Za-z][A-Za-z0-9_]*)\1\s*\)/', $source, $matches);
    $keys = array_values(array_unique($matches[2] ?? array()));
    sort($keys, SORT_STRING);
    if ($keys === array()) {
        throw new RuntimeException('No literal t() calls were found in the checkout runtime.');
    }

    return $keys;
}

/** @return list<string> */
function extract_php_translation_keys(string $source): array
{
    $tokens = token_get_all($source);
    $keys = array();
    $blocks = 0;

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        if (decode_php_string($token[1]) !== 'translations') {
            continue;
        }

        $arrow = next_significant($tokens, $index + 1);
        if ($arrow === null || ! is_array($tokens[$arrow]) || $tokens[$arrow][0] !== T_DOUBLE_ARROW) {
            continue;
        }
        $value = next_significant($tokens, $arrow + 1);
        if ($value === null) {
            continue;
        }

        if ($tokens[$value] === '[') {
            $open = $value;
            $main = 'square';
        } elseif (is_array($tokens[$value]) && $tokens[$value][0] === T_ARRAY) {
            $open = next_significant($tokens, $value + 1);
            if ($open === null || $tokens[$open] !== '(') {
                throw new RuntimeException('Malformed array() translation map.');
            }
            $main = 'round';
        } else {
            throw new RuntimeException('The translations map must be a literal array.');
        }

        ++$blocks;
        foreach (top_level_array_keys($tokens, $open, $main) as $key) {
            $keys[$key] = true;
        }
    }

    if ($blocks !== 1) {
        throw new RuntimeException(sprintf('Expected one translations map, found %d.', $blocks));
    }

    $result = array_keys($keys);
    sort($result, SORT_STRING);
    if ($result === array()) {
        throw new RuntimeException('The server translations map is empty.');
    }

    return $result;
}

/**
 * @param array<int,mixed> $tokens
 * @return list<string>
 */
function top_level_array_keys(array $tokens, int $open, string $main): array
{
    $round = $main === 'round' ? 1 : 0;
    $square = $main === 'square' ? 1 : 0;
    $curly = 0;
    $keys = array();

    for ($index = $open + 1, $count = count($tokens); $index < $count; ++$index) {
        $token = $tokens[$index];
        $atTop = ($main === 'round' && $round === 1 && $square === 0 && $curly === 0)
            || ($main === 'square' && $square === 1 && $round === 0 && $curly === 0);

        if ($atTop && is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $arrow = next_significant($tokens, $index + 1);
            if ($arrow !== null && is_array($tokens[$arrow]) && $tokens[$arrow][0] === T_DOUBLE_ARROW) {
                $keys[] = decode_php_string($token[1]);
            }
        }

        if ($token === '(') {
            ++$round;
        } elseif ($token === ')') {
            --$round;
        } elseif ($token === '[') {
            ++$square;
        } elseif ($token === ']') {
            --$square;
        } elseif ($token === '{') {
            ++$curly;
        } elseif ($token === '}') {
            --$curly;
        }

        if (($main === 'round' && $round === 0) || ($main === 'square' && $square === 0)) {
            return $keys;
        }
        if ($round < 0 || $square < 0 || $curly < 0) {
            break;
        }
    }

    throw new RuntimeException('Unterminated server translations map.');
}

/** @param array<int,mixed> $tokens */
function next_significant(array $tokens, int $start): ?int
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

function decode_php_string(string $literal): string
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
    for ($index = 0, $length = strlen($inner); $index < $length; ++$index) {
        if ($inner[$index] === '\\' && $index + 1 < $length && ($inner[$index + 1] === '\\' || $inner[$index + 1] === "'")) {
            $decoded .= $inner[++$index];
        } else {
            $decoded .= $inner[$index];
        }
    }

    return $decoded;
}
