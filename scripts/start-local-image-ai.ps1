$ErrorActionPreference = 'Stop'

$root = if ($env:LOCAL_IMAGE_AI_ROOT) { $env:LOCAL_IMAGE_AI_ROOT } else { 'C:\AI\tattoo-image' }
$executable = Join-Path $root 'backend\sd-server.exe'
$model = Join-Path $root 'models\sd3.5m_turbo-Q4_K_M.gguf'
$vae = Join-Path $root 'models\sd35-vae.safetensors'
$clip = Join-Path $root 'models\sd35-clip_l.safetensors'
$clipG = Join-Path $root 'models\sd35-clip_g.safetensors'
$t5 = Join-Path $root 'models\t5xxl-encoder-q4_k_m.gguf'
$logFolder = Join-Path $root 'logs'
$stdoutLog = Join-Path $logFolder 'server.out.log'
$stderrLog = Join-Path $logFolder 'server.error.log'

if (-not (Test-Path -LiteralPath $executable)) {
    throw "Executável da IA local não encontrado: $executable"
}
foreach ($requiredFile in @($model, $vae, $clip, $clipG, $t5)) {
    if (-not (Test-Path -LiteralPath $requiredFile)) {
        throw "Componente do SD 3.5 não encontrado: $requiredFile"
    }
}
if (-not (Test-Path -LiteralPath $logFolder)) {
    New-Item -ItemType Directory -Path $logFolder | Out-Null
}
if (Get-Process -Name 'sd-server' -ErrorAction SilentlyContinue) {
    exit 0
}

$arguments = @(
    '--diffusion-model', "`"$model`"",
    '--vae', "`"$vae`"",
    '--clip_l', "`"$clip`"",
    '--clip_g', "`"$clipG`"",
    '--t5xxl', "`"$t5`"",
    '--listen-ip', '127.0.0.1',
    '--listen-port', '7861',
    '--backend', 'clip=cpu,vae=vulkan0,diffusion=vulkan0',
    '--params-backend', 'clip=cpu,vae=vulkan0,diffusion=vulkan0',
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
