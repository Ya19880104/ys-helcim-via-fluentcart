#requires -Version 5.1

[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Assert-Equal {
    param(
        [Parameter(Mandatory)]
        $Expected,

        [Parameter(Mandatory)]
        $Actual,

        [Parameter(Mandatory)]
        [string] $Message
    )

    if ($Expected -ne $Actual) {
        throw "$Message Expected '$Expected', got '$Actual'."
    }
}

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\..')).Path
$builder = Join-Path $repoRoot 'scripts\build-release.ps1'
$verifier = Join-Path $repoRoot 'scripts\verify-release-package.ps1'

if (-not (Test-Path -LiteralPath $builder -PathType Leaf)) {
    throw "Missing release builder: $builder"
}

if (-not (Test-Path -LiteralPath $verifier -PathType Leaf)) {
    throw "Missing release verifier: $verifier"
}

$tempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('ys-helcim-package-test-' + [guid]::NewGuid().ToString('N'))
[void] (New-Item -ItemType Directory -Path $tempRoot)

try {
    $firstOutput = Join-Path $tempRoot 'first'
    $secondOutput = Join-Path $tempRoot 'second'

    $first = & $builder -SourceRoot $repoRoot -OutputDirectory $firstOutput -PassThru
    $second = & $builder -SourceRoot $repoRoot -OutputDirectory $secondOutput -PassThru

    if ($first.FileCount -le 0) {
        throw 'The strict runtime allowlist produced an empty archive.'
    }
    Assert-Equal -Expected $first.FileCount -Actual $second.FileCount -Message 'Two builds did not include the same runtime file count.'
    Assert-Equal -Expected $first.Sha256 -Actual $second.Sha256 -Message 'Two builds from identical input were not deterministic.'
    Assert-Equal `
        -Expected (Get-FileHash -Algorithm SHA256 -LiteralPath $first.ManifestPath).Hash `
        -Actual (Get-FileHash -Algorithm SHA256 -LiteralPath $second.ManifestPath).Hash `
        -Message 'Two builds from identical input did not produce the same manifest.'

    & $verifier -ZipPath $first.ZipPath -ManifestPath $first.ManifestPath -SourceRoot $repoRoot

    $manifest = Get-Content -Raw -LiteralPath $first.ManifestPath | ConvertFrom-Json
    Assert-Equal -Expected '1.1.0-rc.2' -Actual $manifest.version -Message 'The manifest version does not match the release candidate.'
    Assert-Equal -Expected $first.FileCount -Actual $manifest.file_count -Message 'The manifest file count is incorrect.'
    Assert-Equal -Expected $first.Sha256 -Actual $manifest.archive_sha256 -Message 'The manifest archive digest is incorrect.'

    if ($env:OS -eq 'Windows_NT') {
        $junctionSource = Join-Path $tempRoot 'junction-source'
        [void] (New-Item -ItemType Directory -Path $junctionSource)
        foreach ($rootFile in @('CHANGELOG.md', 'LICENSE', 'README.md', 'ys-helcim-via-fluentcart.php')) {
            Copy-Item -LiteralPath (Join-Path $repoRoot $rootFile) -Destination $junctionSource
        }
        foreach ($runtimeDirectory in @('assets', 'languages', 'src', 'vendor')) {
            Copy-Item -LiteralPath (Join-Path $repoRoot $runtimeDirectory) -Destination $junctionSource -Recurse
        }
        & git -C $junctionSource init --quiet
        & git -C $junctionSource config core.autocrlf false
        & git -C $junctionSource add --all
        & git -C $junctionSource -c user.name='YS Helcim Test' -c user.email='release-test@example.invalid' commit --quiet -m 'fixture'
        if ($LASTEXITCODE -ne 0) {
            throw 'Unable to create the reparse-point source fixture repository.'
        }

        $junctionTarget = Join-Path $tempRoot 'junction-target'
        [void] (New-Item -ItemType Directory -Path $junctionTarget)
        [System.IO.File]::WriteAllText(
            (Join-Path $junctionTarget 'outside.php'),
            '<?php // must never be followed by the release builder',
            [System.Text.UTF8Encoding]::new($false)
        )
        [void] (New-Item -ItemType Junction -Path (Join-Path $junctionSource 'src\external-runtime') -Target $junctionTarget)

        $junctionRejected = $false
        try {
            & $builder -SourceRoot $junctionSource -OutputDirectory (Join-Path $tempRoot 'junction-output') -PassThru
        } catch {
            if ($_.Exception.Message -notmatch 'reparse') {
                throw
            }
            $junctionRejected = $true
        }
        Assert-Equal -Expected $true -Actual $junctionRejected -Message 'The builder followed a reparse-point directory outside its source tree.'
    }

    $wrongVersionManifest = Join-Path $tempRoot 'wrong-version.manifest.json'
    $wrongVersion = Get-Content -Raw -LiteralPath $first.ManifestPath | ConvertFrom-Json
    $wrongVersion.version = '9.9.9'
    [System.IO.File]::WriteAllText(
        $wrongVersionManifest,
        (($wrongVersion | ConvertTo-Json -Depth 5) + [System.Environment]::NewLine),
        [System.Text.UTF8Encoding]::new($false)
    )
    $versionRejected = $false
    try {
        & $verifier -ZipPath $first.ZipPath -ManifestPath $wrongVersionManifest
    } catch {
        if ($_.Exception.Message -notmatch 'version') {
            throw
        }
        $versionRejected = $true
    }
    Assert-Equal -Expected $true -Actual $versionRejected -Message 'The verifier accepted a manifest version that disagrees with the packaged plugin.'

    $wrongArchiveManifest = Join-Path $tempRoot 'wrong-archive.manifest.json'
    $wrongArchive = Get-Content -Raw -LiteralPath $first.ManifestPath | ConvertFrom-Json
    $wrongArchive.archive_file = 'renamed-or-ambiguous.zip'
    [System.IO.File]::WriteAllText(
        $wrongArchiveManifest,
        (($wrongArchive | ConvertTo-Json -Depth 5) + [System.Environment]::NewLine),
        [System.Text.UTF8Encoding]::new($false)
    )
    $archiveNameRejected = $false
    try {
        & $verifier -ZipPath $first.ZipPath -ManifestPath $wrongArchiveManifest
    } catch {
        if ($_.Exception.Message -notmatch 'archive.*file|file.*name') {
            throw
        }
        $archiveNameRejected = $true
    }
    Assert-Equal -Expected $true -Actual $archiveNameRejected -Message 'The verifier accepted a manifest for a different archive filename.'

    $wrongCommitManifest = Join-Path $tempRoot 'wrong-commit.manifest.json'
    $wrongCommit = Get-Content -Raw -LiteralPath $first.ManifestPath | ConvertFrom-Json
    $wrongCommit.source_commit = '0000000000000000000000000000000000000000'
    [System.IO.File]::WriteAllText(
        $wrongCommitManifest,
        (($wrongCommit | ConvertTo-Json -Depth 5) + [System.Environment]::NewLine),
        [System.Text.UTF8Encoding]::new($false)
    )
    $commitRejected = $false
    try {
        & $verifier -ZipPath $first.ZipPath -ManifestPath $wrongCommitManifest -SourceRoot $repoRoot
    } catch {
        if ($_.Exception.Message -notmatch 'source commit') {
            throw
        }
        $commitRejected = $true
    }
    Assert-Equal -Expected $true -Actual $commitRejected -Message 'The verifier accepted a false source commit provenance claim.'

    $badZip = Join-Path $tempRoot 'forbidden-entry.zip'
    Copy-Item -LiteralPath $first.ZipPath -Destination $badZip

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::Open($badZip, [System.IO.Compression.ZipArchiveMode]::Update)
    try {
        $entry = $archive.CreateEntry('ys-helcim-via-fluentcart/tests/manual/leak.txt')
        $entry.LastWriteTime = [System.DateTimeOffset]::new(1980, 1, 1, 0, 0, 0, [System.TimeSpan]::Zero)
        $writer = [System.IO.StreamWriter]::new($entry.Open())
        try {
            $writer.Write('must not ship')
        } finally {
            $writer.Dispose()
        }
    } finally {
        $archive.Dispose()
    }

    $rejected = $false
    try {
        & $verifier -ZipPath $badZip
    } catch {
        if ($_.Exception.Message -notmatch 'forbidden') {
            throw
        }
        $rejected = $true
    }

    Assert-Equal -Expected $true -Actual $rejected -Message 'The verifier accepted an archive containing a forbidden test path.'

    $unexpectedExtensionZip = Join-Path $tempRoot 'unexpected-extension.zip'
    Copy-Item -LiteralPath $first.ZipPath -Destination $unexpectedExtensionZip
    $archive = [System.IO.Compression.ZipFile]::Open($unexpectedExtensionZip, [System.IO.Compression.ZipArchiveMode]::Update)
    try {
        $entry = $archive.CreateEntry('ys-helcim-via-fluentcart/assets/debug-copy.bak')
        $entry.LastWriteTime = [System.DateTimeOffset]::new(1980, 1, 1, 0, 0, 0, [System.TimeSpan]::Zero)
        $writer = [System.IO.StreamWriter]::new($entry.Open())
        try {
            $writer.Write('runtime backup files must not ship')
        } finally {
            $writer.Dispose()
        }
    } finally {
        $archive.Dispose()
    }

    $extensionRejected = $false
    try {
        & $verifier -ZipPath $unexpectedExtensionZip
    } catch {
        if ($_.Exception.Message -notmatch 'extension|allowlist|forbidden') {
            throw
        }
        $extensionRejected = $true
    }
    Assert-Equal -Expected $true -Actual $extensionRejected -Message 'The verifier accepted an unexpected runtime file extension.'

    $secretZip = Join-Path $tempRoot 'binary-secret.zip'
    Copy-Item -LiteralPath $first.ZipPath -Destination $secretZip
    $archive = [System.IO.Compression.ZipFile]::Open($secretZip, [System.IO.Compression.ZipArchiveMode]::Update)
    try {
        $entry = $archive.CreateEntry('ys-helcim-via-fluentcart/languages/leaked-secret.mo')
        $entry.LastWriteTime = [System.DateTimeOffset]::new(1980, 1, 1, 0, 0, 0, [System.TimeSpan]::Zero)
        $writer = [System.IO.StreamWriter]::new($entry.Open())
        try {
            $writer.Write('binary-prefix' + [char]0 + 'github_pat_' + ('A' * 40))
        } finally {
            $writer.Dispose()
        }
    } finally {
        $archive.Dispose()
    }

    $secretRejected = $false
    try {
        & $verifier -ZipPath $secretZip
    } catch {
        if ($_.Exception.Message -notmatch 'secret|credential') {
            throw
        }
        $secretRejected = $true
    }

    Assert-Equal -Expected $true -Actual $secretRejected -Message 'The verifier accepted a binary runtime file containing a recognized credential.'

    $timestampZip = Join-Path $tempRoot 'nondeterministic-timestamp.zip'
    Copy-Item -LiteralPath $first.ZipPath -Destination $timestampZip
    $archive = [System.IO.Compression.ZipFile]::Open($timestampZip, [System.IO.Compression.ZipArchiveMode]::Update)
    try {
        $readmeEntry = $archive.GetEntry('ys-helcim-via-fluentcart/README.md')
        if ($null -eq $readmeEntry) {
            throw 'The deterministic test archive is missing README.md.'
        }
        $readmeEntry.LastWriteTime = [System.DateTimeOffset]::new(2026, 7, 22, 0, 0, 0, [System.TimeSpan]::Zero)
    } finally {
        $archive.Dispose()
    }

    $timestampRejected = $false
    try {
        & $verifier -ZipPath $timestampZip
    } catch {
        if ($_.Exception.Message -notmatch 'timestamp|deterministic') {
            throw
        }
        $timestampRejected = $true
    }

    Assert-Equal -Expected $true -Actual $timestampRejected -Message 'The verifier accepted a package with a non-deterministic entry timestamp.'

    $ambiguousPathZip = Join-Path $tempRoot 'ambiguous-path.zip'
    Copy-Item -LiteralPath $first.ZipPath -Destination $ambiguousPathZip
    $archive = [System.IO.Compression.ZipFile]::Open($ambiguousPathZip, [System.IO.Compression.ZipArchiveMode]::Update)
    try {
        $entry = $archive.CreateEntry('ys-helcim-via-fluentcart/src/./shadow.php')
        $entry.LastWriteTime = [System.DateTimeOffset]::new(1980, 1, 1, 0, 0, 0, [System.TimeSpan]::Zero)
        $writer = [System.IO.StreamWriter]::new($entry.Open())
        try {
            $writer.Write('<?php // ambiguous extraction path')
        } finally {
            $writer.Dispose()
        }
    } finally {
        $archive.Dispose()
    }

    $ambiguousPathRejected = $false
    try {
        & $verifier -ZipPath $ambiguousPathZip
    } catch {
        if ($_.Exception.Message -notmatch 'unsafe|normalized|ambiguous') {
            throw
        }
        $ambiguousPathRejected = $true
    }

    Assert-Equal -Expected $true -Actual $ambiguousPathRejected -Message 'The verifier accepted an ambiguous dot-segment ZIP path.'

    Write-Output "OK release package verification: files=$($first.FileCount) sha256=$($first.Sha256)"
} finally {
    $resolvedTemp = [System.IO.Path]::GetFullPath($tempRoot)
    $systemTemp = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
    if ($resolvedTemp.StartsWith($systemTemp, [System.StringComparison]::OrdinalIgnoreCase) -and
        (Split-Path -Leaf $resolvedTemp).StartsWith('ys-helcim-package-test-', [System.StringComparison]::Ordinal)) {
        Remove-Item -LiteralPath $resolvedTemp -Recurse -Force
    }
}
