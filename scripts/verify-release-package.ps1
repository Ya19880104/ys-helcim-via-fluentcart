#requires -Version 5.1

[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string] $ZipPath,

    [string] $ManifestPath = '',

    [string] $SourceRoot = '',

    [switch] $Quiet
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

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

function Get-BytesSha256 {
    param([Parameter(Mandatory)][byte[]] $Bytes)

    $algorithm = [System.Security.Cryptography.SHA256]::Create()
    try {
        return ([System.BitConverter]::ToString($algorithm.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant()
    } finally {
        $algorithm.Dispose()
    }
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
        [Parameter(Mandatory)][byte[]] $Bytes
    )

    $ascii = [System.Text.Encoding]::ASCII.GetString($Bytes)
    foreach ($marker in $secretMarkers.GetEnumerator()) {
        if ($ascii -match $marker.Value) {
            throw "Release package contains forbidden secret/development marker '$($marker.Key)' in $RelativePath"
        }
    }

    $extension = [System.IO.Path]::GetExtension($RelativePath).ToLowerInvariant()
    if ($textExtensions -notcontains $extension) {
        return
    }
    try {
        $utf8 = [System.Text.UTF8Encoding]::new($false, $true)
        [void] $utf8.GetString($Bytes)
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

function Get-SourceRuntimeFiles {
    param([Parameter(Mandatory)][string] $Root)

    $files = [System.Collections.Generic.List[object]]::new()
    foreach ($name in $requiredRootFiles) {
        $path = Join-Path $Root $name
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            throw "Required runtime file is missing from the source tree: $name"
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
            throw "Required runtime directory is missing from the source tree: $directory"
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

    return @($files | Sort-Object RelativePath)
}

$resolvedZip = (Resolve-Path -LiteralPath $ZipPath).Path
if (-not (Test-Path -LiteralPath $resolvedZip -PathType Leaf)) {
    throw "Release archive does not exist: $ZipPath"
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [System.IO.Compression.ZipFile]::OpenRead($resolvedZip)
$archiveFiles = [System.Collections.Generic.List[object]]::new()
$archivePaths = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::Ordinal)
$archivePathsIgnoreCase = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
$rootEntryFound = $false
$totalUncompressedBytes = [int64] 0
$archiveVersion = ''

try {
    foreach ($entry in $archive.Entries) {
        $entryName = $entry.FullName
        if ([string]::IsNullOrWhiteSpace($entryName) -or $entryName.Contains('\')) {
            throw "Release archive contains a non-normalized path: $entryName"
        }
        if ($entryName.StartsWith('/') -or
            $entryName.Contains('//') -or
            $entryName -match '(^|/)\.{1,2}(/|$)' -or
            $entryName -match '[:\x00-\x1F\x7F]') {
            throw "Release archive contains an unsafe path: $entryName"
        }
        if (-not $archivePaths.Add($entryName) -or -not $archivePathsIgnoreCase.Add($entryName)) {
            throw "Release archive contains a duplicate path: $entryName"
        }
        # ZIP stores a DOS wall-clock value without a timezone. .NET attaches
        # the local offset when reading it, so compare DateTime rather than UTC.
        if ($entry.LastWriteTime.DateTime -ne $fixedTimestamp.DateTime) {
            throw "Release archive contains a non-deterministic timestamp: $entryName"
        }
        if ($entryName -eq "$slug/") {
            $rootEntryFound = $true
            continue
        }
        if (-not $entryName.StartsWith("$slug/", [System.StringComparison]::Ordinal)) {
            throw "Release archive contains a path outside the single slug root: $entryName"
        }

        $relative = $entryName.Substring($slug.Length + 1)
        if ([string]::IsNullOrWhiteSpace($relative)) {
            continue
        }
        if (Test-ForbiddenRelativePath -RelativePath $relative.TrimEnd('/')) {
            throw "Release archive contains a forbidden path: $entryName"
        }

        $top = ($relative.TrimEnd('/') -split '/')[0]
        $isDirectory = $entryName.EndsWith('/')
        if ($relative -notmatch '/' -and -not $isDirectory) {
            if ($requiredRootFiles -notcontains $relative) {
                throw "Release archive contains a non-allowlisted root file: $entryName"
            }
        } elseif ($allowedTopDirectories -notcontains $top) {
            throw "Release archive contains a non-allowlisted top directory: $entryName"
        }

        $unixType = (($entry.ExternalAttributes -shr 16) -band 0xF000)
        if ($unixType -eq 0xA000) {
            throw "Release archive contains a symbolic link: $entryName"
        }
        if ($isDirectory) {
            continue
        }
        if ($relative.Contains('/')) {
            $extension = [System.IO.Path]::GetExtension($relative).ToLowerInvariant()
            if ($allowedExtensionsByTopDirectory[$top] -notcontains $extension) {
                throw "Release archive contains an unexpected runtime extension: $entryName"
            }
        }
        if ($entry.Length -gt 25MB) {
            throw "Release archive contains an unexpectedly large file: $entryName"
        }
        $totalUncompressedBytes += $entry.Length
        if ($totalUncompressedBytes -gt 100MB) {
            throw 'Release archive exceeds the uncompressed size limit.'
        }

        $memory = [System.IO.MemoryStream]::new()
        try {
            $stream = $entry.Open()
            try {
                $stream.CopyTo($memory)
            } finally {
                $stream.Dispose()
            }
            $bytes = $memory.ToArray()
        } finally {
            $memory.Dispose()
        }

        Assert-NoSecretMarkers -RelativePath $relative -Bytes $bytes
        if ($relative -eq 'ys-helcim-via-fluentcart.php') {
            $pluginText = [System.Text.UTF8Encoding]::new($false, $true).GetString($bytes)
            $headerMatch = [regex]::Match($pluginText, '(?m)^\s*\*\s*Version:\s*([^\s]+)\s*$')
            $constantMatch = [regex]::Match($pluginText, "define\(\s*'YS_HELCIM_FCT_VERSION'\s*,\s*'([^']+)'\s*\)")
            if (-not $headerMatch.Success -or -not $constantMatch.Success -or
                $headerMatch.Groups[1].Value -ne $constantMatch.Groups[1].Value -or
                $headerMatch.Groups[1].Value -notmatch '^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$') {
                throw 'Release archive contains inconsistent or invalid plugin version declarations.'
            }
            $archiveVersion = $headerMatch.Groups[1].Value
        }
        $archiveFiles.Add(
            [pscustomobject]@{
                Path = $relative
                Bytes = [int64] $bytes.Length
                Sha256 = Get-BytesSha256 -Bytes $bytes
            }
        )
    }
} finally {
    $archive.Dispose()
}

if (-not $rootEntryFound) {
    throw "Release archive is missing its explicit $slug/ root entry."
}
if ([string]::IsNullOrWhiteSpace($archiveVersion)) {
    throw 'Release archive does not expose a valid plugin version.'
}

foreach ($required in $requiredRootFiles) {
    if (-not $archivePaths.Contains("$slug/$required")) {
        throw "Release archive is missing required root file: $required"
    }
}

$archiveFiles = @($archiveFiles | Sort-Object Path)
if ($archiveFiles.Count -eq 0) {
    throw 'Release archive contains no runtime files.'
}

$archiveDigest = Get-FileSha256 -Path $resolvedZip
$manifest = $null

if (-not [string]::IsNullOrWhiteSpace($ManifestPath)) {
    $resolvedManifest = (Resolve-Path -LiteralPath $ManifestPath).Path
    $manifest = Get-Content -Raw -LiteralPath $resolvedManifest | ConvertFrom-Json

    if ($manifest.schema_version -ne 1 -or $manifest.slug -ne $slug) {
        throw 'Release manifest schema or slug is invalid.'
    }
    if ($manifest.archive_file -ne "$slug.zip" -or
        [System.IO.Path]::GetFileName($resolvedZip) -ne [string] $manifest.archive_file) {
        throw 'Release manifest archive_file does not match the verified ZIP file name.'
    }
    if ([string] $manifest.source_commit -notmatch '^[0-9a-f]{40}(?:[0-9a-f]{24})?$' -or
        $manifest.source_dirty -isnot [bool]) {
        throw 'Release manifest source provenance is invalid.'
    }
    if ($manifest.version -ne $archiveVersion) {
        throw 'Release manifest version does not match the packaged plugin.'
    }
    if ($manifest.archive_sha256 -ne $archiveDigest) {
        throw 'Release manifest archive SHA-256 does not match the ZIP.'
    }
    if ([int] $manifest.file_count -ne $archiveFiles.Count) {
        throw 'Release manifest file count does not match the ZIP.'
    }

    $manifestFiles = @($manifest.files | Sort-Object path)
    if ($manifestFiles.Count -ne $archiveFiles.Count) {
        throw 'Release manifest file list does not match the ZIP.'
    }
    for ($index = 0; $index -lt $archiveFiles.Count; $index++) {
        if ($manifestFiles[$index].path -ne $archiveFiles[$index].Path -or
            $manifestFiles[$index].sha256 -ne $archiveFiles[$index].Sha256 -or
            [int64] $manifestFiles[$index].bytes -ne $archiveFiles[$index].Bytes) {
            throw "Release manifest entry does not match the ZIP: $($archiveFiles[$index].Path)"
        }
    }
}

if (-not [string]::IsNullOrWhiteSpace($SourceRoot)) {
    $resolvedSource = (Resolve-Path -LiteralPath $SourceRoot).Path
    if ($null -ne $manifest) {
        $gitOutput = @(& git -C $resolvedSource rev-parse HEAD 2>$null)
        if ($LASTEXITCODE -ne 0) {
            throw 'Unable to verify the release manifest source commit.'
        }
        $actualCommit = [string] ($gitOutput | Select-Object -First 1)
        $actualCommit = $actualCommit.Trim().ToLowerInvariant()
        $actualStatus = @(& git -C $resolvedSource status --porcelain=v1 2>$null)
        if ($LASTEXITCODE -ne 0) {
            throw 'Unable to verify the release manifest source dirty state.'
        }
        $actualDirty = $actualStatus.Count -gt 0
        if ([string] $manifest.source_commit -ne $actualCommit) {
            throw 'Release manifest source commit does not match the source tree.'
        }
        if ([bool] $manifest.source_dirty -ne $actualDirty) {
            throw 'Release manifest source dirty state does not match the source tree.'
        }
    }
    $sourceFiles = @(Get-SourceRuntimeFiles -Root $resolvedSource)
    if ($sourceFiles.Count -ne $archiveFiles.Count) {
        throw "Release archive file count does not match the source runtime allowlist. Source=$($sourceFiles.Count), ZIP=$($archiveFiles.Count)."
    }

    for ($index = 0; $index -lt $sourceFiles.Count; $index++) {
        $source = $sourceFiles[$index]
        $packed = $archiveFiles[$index]
        if ($source.RelativePath -ne $packed.Path) {
            throw "Release archive manifest differs from the source runtime allowlist: $($source.RelativePath) / $($packed.Path)"
        }
        $sourceDigest = Get-FileSha256 -Path $source.FullName
        if ($sourceDigest -ne $packed.Sha256) {
            throw "Release archive file differs from source: $($source.RelativePath)"
        }
    }
}

if (-not $Quiet) {
    Write-Output "OK package=$resolvedZip files=$($archiveFiles.Count) sha256=$archiveDigest"
}
