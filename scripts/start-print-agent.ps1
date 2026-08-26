param(
    [string]$PhpExecutable = 'php.exe',
    [switch]$Once
)

$ErrorActionPreference = 'Stop'
$projectDirectory = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
Set-Location -LiteralPath $projectDirectory

$arguments = @('artisan', 'print-agent:work')
if ($Once) {
    $arguments += '--once'
}

& $PhpExecutable @arguments
exit $LASTEXITCODE
