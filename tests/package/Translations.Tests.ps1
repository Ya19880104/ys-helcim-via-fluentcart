#requires -Version 5.1

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$generator = Join-Path $repoRoot 'scripts\update-translations.php'
$potPath = Join-Path $repoRoot 'languages\ys-helcim-via-fluentcart.pot'
$poPath = Join-Path $repoRoot 'languages\ys-helcim-via-fluentcart-zh_TW.po'
$moPath = Join-Path $repoRoot 'languages\ys-helcim-via-fluentcart-zh_TW.mo'

if (-not (Test-Path -LiteralPath $generator -PathType Leaf)) {
    throw "Missing translation generator: $generator"
}

& php $generator --check "--root=$repoRoot"
if ($LASTEXITCODE -ne 0) {
    throw "Translation catalog check failed with exit code $LASTEXITCODE."
}

$pot = [System.IO.File]::ReadAllText($potPath, [System.Text.Encoding]::UTF8)
$po = [System.IO.File]::ReadAllText($poPath, [System.Text.Encoding]::UTF8)
foreach ($catalog in @($pot, $po)) {
    if ($catalog -notmatch 'Project-Id-Version: YS Helcim via FluentCart 1\.1\.0-rc\.2\\n') {
        throw 'Translation catalog has a stale Project-Id-Version header.'
    }
}

foreach ($wrappedMessage in @(
    'The order is not in a safe unpaid state.',
    'The matching payment operation could not be verified.',
    'The hosted checkout request could not be prepared.',
    'The hosted checkout request is invalid.',
    'The payment confirmation session is invalid or expired.',
    'The hosted payment operation identifier is invalid.'
)) {
    $escaped = [regex]::Escape(('msgid "{0}"' -f $wrappedMessage))
    if ($pot -notmatch $escaped) {
        throw "Translation catalog omitted a wrapped runtime message: $wrappedMessage"
    }
}
if ($pot -notmatch '(?m)^#\. translators:') {
    throw 'Translation template omitted source translator comments.'
}

$mo = [System.IO.File]::ReadAllBytes($moPath)
if ($mo.Length -lt 28) {
    throw 'Compiled zh_TW MO catalog is missing or truncated.'
}

$magic = [System.BitConverter]::ToUInt32($mo, 0)
if ($magic -ne [uint32]2500072158) {
    throw ('Compiled zh_TW MO catalog has invalid magic 0x{0:x8}.' -f $magic)
}

$moCount = [System.BitConverter]::ToUInt32($mo, 8)
$originalTableOffset = [System.BitConverter]::ToUInt32($mo, 12)
$translationTableOffset = [System.BitConverter]::ToUInt32($mo, 16)
$potCount = [regex]::Matches($pot, '(?m)^msgid ').Count
if ($moCount -ne $potCount -or $moCount -lt 2) {
    throw "Compiled MO entry count ($moCount) does not match POT entry count ($potCount)."
}

$strictUtf8 = [System.Text.UTF8Encoding]::new($false, $true)
foreach ($tableOffset in @($originalTableOffset, $translationTableOffset)) {
    if ([uint64]$tableOffset + (8 * [uint64]$moCount) -gt [uint64]$mo.Length) {
        throw 'Compiled MO string table extends beyond the file.'
    }

    for ($index = 0; $index -lt $moCount; $index++) {
        $recordOffset = [int]$tableOffset + (8 * $index)
        $length = [System.BitConverter]::ToUInt32($mo, $recordOffset)
        $offset = [System.BitConverter]::ToUInt32($mo, $recordOffset + 4)
        if ([uint64]$offset + [uint64]$length -ge [uint64]$mo.Length -or $mo[[int]($offset + $length)] -ne 0) {
            throw "Compiled MO contains an invalid string record at table index $index."
        }
        [void]$strictUtf8.GetString($mo, [int]$offset, [int]$length)
    }
}

Write-Output 'OK translation catalogs are complete, current, and compiled.'
