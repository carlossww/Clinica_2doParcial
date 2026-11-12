param(
    [ValidateRange(1, 65535)][int]$Port = 8000
)

. "$PSScriptRoot/common.ps1"
$php = Resolve-VidaSaludPhp
$null = Initialize-VidaSaludLocalCertificates
$previousScan = $env:PHP_INI_SCAN_DIR
$uploadConfig = Join-Path $script:VidaSaludRoot '.runtime/php-conf'
New-Item -ItemType Directory -Path $uploadConfig -Force | Out-Null
[System.IO.File]::WriteAllText((Join-Path $uploadConfig 'uploads.ini'), "upload_max_filesize=10M`npost_max_size=12M`n", [System.Text.UTF8Encoding]::new($false))
$env:PHP_INI_SCAN_DIR = $previousScan + [System.IO.Path]::PathSeparator + $uploadConfig
Push-Location $script:VidaSaludRoot
try {
    Write-Host "PHP seleccionado: $php"
    Invoke-VidaSaludPhp $php @('artisan', 'serve', '--host=127.0.0.1', "--port=$Port", '--no-interaction')
}
finally { $env:PHP_INI_SCAN_DIR = $previousScan; Pop-Location }
