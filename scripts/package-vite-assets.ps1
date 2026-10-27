param(
    [string] $OutputDirectory = ""
)

$ErrorActionPreference = 'Stop'

$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$privateRoot = [System.IO.Path]::GetFullPath((Join-Path $projectRoot 'storage/app/private/deployment'))
$outputRoot = if ($OutputDirectory) {
    [System.IO.Path]::GetFullPath($OutputDirectory)
} else {
    $privateRoot
}

if (-not $outputRoot.StartsWith($privateRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "El directorio de salida debe permanecer dentro de $privateRoot"
}

$staging = Join-Path $outputRoot 'vite-build'
$archive = Join-Path $outputRoot 'vite-build.zip'

New-Item -ItemType Directory -Force -Path $outputRoot | Out-Null
if (Test-Path -LiteralPath $staging) {
    Remove-Item -LiteralPath $staging -Recurse -Force
}
if (Test-Path -LiteralPath $archive) {
    Remove-Item -LiteralPath $archive -Force
}

Push-Location $projectRoot
try {
    & npm.cmd run build -- --outDir $staging --emptyOutDir
    if ($LASTEXITCODE -ne 0) {
        throw "Vite terminó con código $LASTEXITCODE"
    }
} finally {
    Pop-Location
}

$manifestPath = Join-Path $staging 'manifest.json'
if (-not (Test-Path -LiteralPath $manifestPath)) {
    throw 'Vite no generó manifest.json.'
}

$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
$requiredEntries = @(
    'resources/css/app.css',
    'resources/js/app.js',
    'resources/css/storefront.css',
    'resources/js/storefront.js'
)

foreach ($entry in $requiredEntries) {
    $property = $manifest.PSObject.Properties[$entry]
    if ($null -eq $property) {
        throw "Falta la entrada requerida $entry en manifest.json."
    }

    $assetPath = Join-Path $staging $property.Value.file
    if (-not (Test-Path -LiteralPath $assetPath)) {
        throw "El manifest referencia un asset inexistente: $($property.Value.file)"
    }
}

Compress-Archive -Path (Join-Path $staging '*') -DestinationPath $archive -CompressionLevel Optimal
$hash = Get-FileHash -LiteralPath $archive -Algorithm SHA256

Write-Output "Artefacto Vite validado: $archive"
Write-Output "SHA256: $($hash.Hash)"
