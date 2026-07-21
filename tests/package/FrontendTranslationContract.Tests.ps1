#requires -Version 5.1

[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$checker = Join-Path $repoRoot 'scripts\check-frontend-translations.php'
if (-not (Test-Path -LiteralPath $checker -PathType Leaf)) {
    throw "Missing front-end translation contract checker: $checker"
}

& php $checker "--root=$repoRoot"
if ($LASTEXITCODE -ne 0) {
    throw "Front-end translation contract check failed with exit code $LASTEXITCODE."
}

Write-Output 'OK front-end translation keys exactly match both checkout runtimes.'
