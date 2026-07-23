#requires -Version 5.1

[CmdletBinding()]
param(
    [string] $SourceRoot = '',

    [string] $OutputDirectory = '',

    [switch] $RequireClean,

    [switch] $PassThru
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($SourceRoot)) {
    $SourceRoot = Split-Path -Parent $PSScriptRoot
}

$slug = 'ys-helcim-via-fluentcart'
$requiredRootFiles = @(
    'CHANGELOG.md',
    'LICENSE',
    'README.md',
    'ys-helcim-via-fluentcart.php'
)
$allowedTopDirectories = @('assets', 'languages', 'src', 'vendor')
$allowedExtensionsByTopDirectory = [ordered]@{
    assets = @('.css', '.js', '.svg')
    languages = @('.mo', '.po', '.pot')
    src = @('.php')
    vendor = @('.css', '.js', '.json', '.php')
}
$deniedFileNames = @('.env', '.env.local', '.gitignore', '.gitattributes')
$deniedExtensions = @('.crt', '.env', '.key', '.log', '.p12', '.pem', '.pfx', '.sql', '.sqlite', '.zip')
$textExtensions = @('.css', '.html', '.js', '.json', '.md', '.php', '.po', '.pot', '.svg', '.txt', '.xml')
$fixedTimestamp = [System.DateTimeOffset]::new(1980, 1, 1, 0, 0, 0, [System.TimeSpan]::Zero)
$secretMarkers = [ordered]@{
    private_key = '-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----'
    github_token = '\b(?:gh[opsu]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,})\b'
    aws_access_key = '\bAKIA[0-9A-Z]{16}\b'
    slack_token = '\bxox[baprs]-[A-Za-z0-9-]{20,}\b'
    embedded_url_credentials = 'https?://[^\s/:]+:[^\s/@]+@'
    credential_assignment = '(?i)(?:api[_ -]?token|secret[_ -]?key|verifier[_ -]?token)["'']?\s*(?:=>|:|=)\s*["''][A-Za-z0-9_./+=-]{16,}["'']'
    internal_environment = '(?i)\b(?:dev|staging)-[a-z0-9][a-z0-9-]*\b'
    development_host = '(?i)(?:(?:https?://)?(?:dev|staging)-[a-z0-9-]+(?:\.[a-z0-9-]+)+|\.wppro\.cloud|\.trycloudflare\.com)'
    development_path = '(?i)(?:/var/www/|[A-Z]:\\(?:dev|Users)\\)'
    official_test_card = '\b(?:4124939999999990|5413330089099130|374245001751006)\b'
}

function Get-FileSha256 {
    param([Parameter(Mandatory)][string] $Path)

    return (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
}

function Get-ReleaseRelativePath {
    param(
        [Parameter(Mandatory)][string] $Root,
        [Parameter(Mandatory)][string] $Path
    )

    $rootPrefix = [System.IO.Path]::GetFullPath($Root).TrimEnd('\', '/') + [System.IO.Path]::DirectorySeparatorChar
    $fullPath = [System.IO.Path]::GetFullPath($Path)
    if (-not $fullPath.StartsWith($rootPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Runtime file is outside the source root: $fullPath"
    }
    return $fullPath.Substring($rootPrefix.Length).Replace('\', '/')
}

function Test-ForbiddenRelativePath {
    param([Parameter(Mandatory)][string] $RelativePath)

    $segments = $RelativePath -split '/'
    foreach ($segment in $segments) {
        if ($segment -match '^(?i:docs?|manual|node_modules|outputs?|release-artifacts|scripts?|tests?)$' -or
            $segment -match '^\.(?i:git|github|idea|vscode)$') {
            return $true
        }
    }

    $leaf = [System.IO.Path]::GetFileName($RelativePath)
    if ($deniedFileNames -contains $leaf.ToLowerInvariant()) {
        return $true
    }

    $extension = [System.IO.Path]::GetExtension($leaf).ToLowerInvariant()
    return $deniedExtensions -contains $extension
}

function Assert-NoSecretMarkers {
    param(
        [Parameter(Mandatory)][string] $RelativePath,
        [Parameter(Mandatory)][string] $FullName
    )

    try {
        $bytes = [System.IO.File]::ReadAllBytes($FullName)
    } catch {
        throw "Release file could not be scanned: $RelativePath"
    }

    # Credentials and infrastructure markers are ASCII even when embedded in a
    # binary runtime file such as an MO catalog. Scan every file before the
    # stricter UTF-8 validation applied to known text formats.
    $ascii = [System.Text.Encoding]::ASCII.GetString($bytes)
    foreach ($marker in $secretMarkers.GetEnumerator()) {
        if ($ascii -match $marker.Value) {
            throw "Release source contains forbidden secret/development marker '$($marker.Key)' in $RelativePath"
        }
    }

    $extension = [System.IO.Path]::GetExtension($RelativePath).ToLowerInvariant()
    if ($textExtensions -notcontains $extension) {
        return
    }
    try {
        [void] [System.Text.UTF8Encoding]::new($false, $true).GetString($bytes)
    } catch {
        throw "Release text file is not valid UTF-8: $RelativePath"
    }
}

function Get-SafeDirectoryFiles {
    param(
        [Parameter(Mandatory)][string] $Base,
        [Parameter(Mandatory)][string] $Root,
        [Parameter(Mandatory)][string] $RelativeDirectory
    )

    $baseItem = Get-Item -LiteralPath $Base -Force
    $pending = [System.Collections.Generic.Stack[object]]::new()
    $pending.Push($baseItem)
    $files = [System.Collections.Generic.List[object]]::new()

    while ($pending.Count -gt 0) {
        $directory = $pending.Pop()
        if (($directory.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) {
            throw "Runtime allowlist contains a reparse-point directory: $RelativeDirectory"
        }
        foreach ($child in Get-ChildItem -LiteralPath $directory.FullName -Force) {
            $relative = Get-ReleaseRelativePath -Root $Root -Path $child.FullName
            if (($child.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) {
                throw "Runtime allowlist contains a reparse point: $relative"
            }
            if ($child.PSIsContainer) {
                $pending.Push($child)
            } else {
                $files.Add($child)
            }
        }
    }

    return $files
}

function Get-RuntimeFiles {
    param([Parameter(Mandatory)][string] $Root)

    $files = [System.Collections.Generic.List[object]]::new()
    foreach ($name in $requiredRootFiles) {
        $path = Join-Path $Root $name
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            throw "Required runtime file is missing: $name"
        }
        $rootFile = Get-Item -LiteralPath $path -Force
        if (($rootFile.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) {
            throw "Required runtime file is a reparse point: $name"
        }
        $files.Add([pscustomobject]@{ RelativePath = $name; FullName = $rootFile.FullName })
    }

    foreach ($directory in $allowedTopDirectories) {
        $base = Join-Path $Root $directory
        if (-not (Test-Path -LiteralPath $base -PathType Container)) {
            throw "Required runtime directory is missing: $directory"
        }
        foreach ($file in Get-SafeDirectoryFiles -Base $base -Root $Root -RelativeDirectory $directory) {
            $relative = Get-ReleaseRelativePath -Root $Root -Path $file.FullName
            if (Test-ForbiddenRelativePath -RelativePath $relative) {
                continue
            }
            $extension = [System.IO.Path]::GetExtension($relative).ToLowerInvariant()
            if ($allowedExtensionsByTopDirectory[$directory] -notcontains $extension) {
                throw "Runtime allowlist contains an unexpected extension in $relative"
            }
            $files.Add([pscustomobject]@{ RelativePath = $relative; FullName = $file.FullName })
        }
    }

    $sorted = @($files | Sort-Object RelativePath)
    $duplicates = @($sorted | Group-Object { $_.RelativePath.ToLowerInvariant() } | Where-Object Count -gt 1)
    if ($duplicates.Count -gt 0) {
        throw "Runtime allowlist contains case-insensitive duplicate paths: $($duplicates[0].Name)"
    }

    foreach ($file in $sorted) {
        Assert-NoSecretMarkers -RelativePath $file.RelativePath -FullName $file.FullName
    }

    return $sorted
}

$resolvedSource = (Resolve-Path -LiteralPath $SourceRoot).Path
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $resolvedSource 'outputs\release'
}
$resolvedOutput = [System.IO.Path]::GetFullPath($OutputDirectory)

$pluginPath = Join-Path $resolvedSource 'ys-helcim-via-fluentcart.php'
$pluginText = [System.IO.File]::ReadAllText($pluginPath, [System.Text.Encoding]::UTF8)
$headerMatch = [regex]::Match($pluginText, '(?m)^\s*\*\s*Version:\s*([^\s]+)\s*$')
$constantMatch = [regex]::Match($pluginText, "define\(\s*'YS_HELCIM_FCT_VERSION'\s*,\s*'([^']+)'\s*\)")
if (-not $headerMatch.Success -or -not $constantMatch.Success) {
    throw 'Unable to read both plugin version declarations.'
}
$version = $headerMatch.Groups[1].Value
if ($version -ne $constantMatch.Groups[1].Value) {
    throw 'Plugin header and YS_HELCIM_FCT_VERSION do not match.'
}
if ($version -notmatch '^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$') {
    throw "Plugin version is not release-safe: $version"
}

$translationBuilder = Join-Path $PSScriptRoot 'update-translations.php'
if (-not (Test-Path -LiteralPath $translationBuilder -PathType Leaf)) {
    throw "Translation catalog verifier is missing: $translationBuilder"
}
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
if ($null -eq $phpCommand) {
    throw 'PHP CLI is required to verify release translation catalogs.'
}
$frontEndTranslationChecker = Join-Path $PSScriptRoot 'check-frontend-translations.php'
if (-not (Test-Path -LiteralPath $frontEndTranslationChecker -PathType Leaf)) {
    throw "Front-end translation contract checker is missing: $frontEndTranslationChecker"
}
& $phpCommand.Source $frontEndTranslationChecker "--root=$resolvedSource" | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'Front-end checkout translation keys do not match their server maps.'
}
& $phpCommand.Source $translationBuilder --check "--root=$resolvedSource" | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'Translation catalogs are stale or invalid. Rebuild them before packaging.'
}

$gitCommit = ''
$gitDirty = $null
try {
    $gitOutput = @(& git -C $resolvedSource rev-parse HEAD 2>$null)
    if ($LASTEXITCODE -ne 0) {
        throw 'git rev-parse failed.'
    }
    $gitCommit = [string] ($gitOutput | Select-Object -First 1)
    $gitCommit = $gitCommit.Trim().ToLowerInvariant()
    $status = @(& git -C $resolvedSource status --porcelain=v1 2>$null)
    if ($LASTEXITCODE -ne 0) {
        throw 'git status failed.'
    }
    $gitDirty = $status.Count -gt 0
} catch {
    $gitCommit = ''
    $gitDirty = $null
}
if ($gitCommit -notmatch '^[0-9a-f]{40}(?:[0-9a-f]{24})?$' -or $gitDirty -isnot [bool]) {
    throw 'Git source provenance is unavailable for the release manifest.'
}
if ($RequireClean -and $gitDirty -ne $false) {
    throw 'A clean Git worktree is required for a final release build.'
}

$runtimeFiles = @(Get-RuntimeFiles -Root $resolvedSource)
if ($runtimeFiles.Count -eq 0) {
    throw 'The runtime allowlist is empty.'
}

[void] (New-Item -ItemType Directory -Path $resolvedOutput -Force)
$zipPath = Join-Path $resolvedOutput "$slug.zip"
$manifestPath = Join-Path $resolvedOutput "$slug.manifest.json"

foreach ($target in @($zipPath, $manifestPath)) {
    if (Test-Path -LiteralPath $target) {
        Remove-Item -LiteralPath $target -Force
    }
}

$directories = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::Ordinal)
[void] $directories.Add($slug)
foreach ($file in $runtimeFiles) {
    $segments = $file.RelativePath -split '/'
    if ($segments.Count -le 1) {
        continue
    }
    $current = $slug
    for ($index = 0; $index -lt $segments.Count - 1; $index++) {
        $current += '/' + $segments[$index]
        [void] $directories.Add($current)
    }
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$fileStream = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::CreateNew, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
try {
    $archive = [System.IO.Compression.ZipArchive]::new($fileStream, [System.IO.Compression.ZipArchiveMode]::Create, $false)
    try {
        foreach ($directory in @($directories | Sort-Object)) {
            $entry = $archive.CreateEntry($directory + '/')
            $entry.LastWriteTime = $fixedTimestamp
        }

        foreach ($file in $runtimeFiles) {
            $entry = $archive.CreateEntry(
                "$slug/$($file.RelativePath)",
                [System.IO.Compression.CompressionLevel]::Optimal
            )
            $entry.LastWriteTime = $fixedTimestamp
            $entryStream = $entry.Open()
            try {
                $sourceStream = [System.IO.File]::OpenRead($file.FullName)
                try {
                    $sourceStream.CopyTo($entryStream)
                } finally {
                    $sourceStream.Dispose()
                }
            } finally {
                $entryStream.Dispose()
            }
        }
    } finally {
        $archive.Dispose()
    }
} finally {
    $fileStream.Dispose()
}

$archiveDigest = Get-FileSha256 -Path $zipPath
$manifestFiles = @(
    foreach ($file in $runtimeFiles) {
        [ordered]@{
            path = $file.RelativePath
            bytes = [int64] (Get-Item -LiteralPath $file.FullName).Length
            sha256 = Get-FileSha256 -Path $file.FullName
        }
    }
)
$manifest = [ordered]@{
    schema_version = 1
    slug = $slug
    version = $version
    archive_file = [System.IO.Path]::GetFileName($zipPath)
    archive_sha256 = $archiveDigest
    file_count = $runtimeFiles.Count
    source_commit = $gitCommit
    source_dirty = $gitDirty
    files = $manifestFiles
}
$manifestJson = $manifest | ConvertTo-Json -Depth 5
[System.IO.File]::WriteAllText(
    $manifestPath,
    $manifestJson + [System.Environment]::NewLine,
    [System.Text.UTF8Encoding]::new($false)
)

$verifier = Join-Path $PSScriptRoot 'verify-release-package.ps1'
if (-not (Test-Path -LiteralPath $verifier -PathType Leaf)) {
    throw "Release verifier is missing: $verifier"
}
& $verifier -ZipPath $zipPath -ManifestPath $manifestPath -SourceRoot $resolvedSource -Quiet

$result = [pscustomobject]@{
    ZipPath = $zipPath
    ManifestPath = $manifestPath
    Version = $version
    FileCount = $runtimeFiles.Count
    Sha256 = $archiveDigest
    SourceCommit = $gitCommit
    SourceDirty = $gitDirty
}

if ($PassThru) {
    Write-Output $result
} else {
    Write-Output "package=$zipPath"
    Write-Output "manifest=$manifestPath"
    Write-Output "version=$version files=$($runtimeFiles.Count) sha256=$archiveDigest source_commit=$gitCommit source_dirty=$gitDirty"
}
