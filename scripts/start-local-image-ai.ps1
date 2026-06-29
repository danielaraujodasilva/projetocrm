$ErrorActionPreference = 'Stop'

$root = if ($env:LOCAL_IMAGE_AI_ROOT) { $env:LOCAL_IMAGE_AI_ROOT } else { 'C:\AI\tattoo-image' }
$executable = Join-Path $root 'backend\sd-server.exe'
$model = Join-Path $root 'models\realvisxl-v5.0-Q8_0.gguf'
$logFolder = Join-Path $root 'logs'
$stdoutLog = Join-Path $logFolder 'server.out.log'
$stderrLog = Join-Path $logFolder 'server.error.log'

if (-not (Test-Path -LiteralPath $executable)) {
    throw "Executável da IA local não encontrado: $executable"
}
if (-not (Test-Path -LiteralPath $model)) {
    throw "Modelo local não encontrado: $model"
}
if (-not (Test-Path -LiteralPath $logFolder)) {
    New-Item -ItemType Directory -Path $logFolder | Out-Null
}
if (Get-Process -Name 'sd-server' -ErrorAction SilentlyContinue) {
    exit 0
}

$arguments = @(
    '--model', "`"$model`"",
    '--listen-ip', '127.0.0.1',
    '--listen-port', '7861',
    '--backend', 'clip=cpu,vae=cpu,diffusion=vulkan0',
    '--params-backend', 'clip=cpu,vae=cpu,diffusion=vulkan0',
    '--max-vram', 'vulkan0=7',
    '--vae-tiling',
    '--diffusion-fa',
    '--threads', '8'
)

Start-Process -FilePath $executable `
    -ArgumentList ($arguments -join ' ') `
    -WorkingDirectory (Split-Path -Parent $executable) `
    -RedirectStandardOutput $stdoutLog `
    -RedirectStandardError $stderrLog `
    -WindowStyle Hidden
