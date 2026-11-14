param(
    [Parameter(Mandatory = $true)]
    [string]$PhpExecutable,
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$PhpunitArguments
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $projectRoot

# Never reuse the application's cached configuration or an inherited database.
$testConfig = Join-Path $projectRoot 'tmp\isolated-phpunit-config-must-not-exist.php'
if (Test-Path -LiteralPath $testConfig) {
    throw "El archivo de configuración aislada no debe existir: $testConfig"
}
$env:APP_CONFIG_CACHE = $testConfig
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
Remove-Item Env:DB_URL -ErrorAction SilentlyContinue
$env:SESSION_DRIVER = 'array'
$env:CACHE_STORE = 'array'
$env:QUEUE_CONNECTION = 'sync'
$env:BANECO_ENABLED = 'false'
$env:BANECO_WEBHOOK_ENABLED = 'false'

& $PhpExecutable tests/verify-isolated-environment.php
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $PhpExecutable vendor/bin/phpunit @PhpunitArguments
exit $LASTEXITCODE
