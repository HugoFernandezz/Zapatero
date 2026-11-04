<# Comprueba endpoints sin mostrar secretos ni modificar pedidos. Ejecutar también desde otra red.
   .\scripts\comprobar-publicacion.ps1 -TiendaUrl https://tienda.example -N8nUrl https://n8n.example
   Opcional: variables del proceso ZAPATERO_API_TOKEN y BO_API_TOKEN para verificar las APIs autenticadas.
#>
param(
    [Parameter(Mandatory = $true)][uri]$TiendaUrl,
    [Parameter(Mandatory = $true)][uri]$N8nUrl,
    [string]$Salida = 'storage/comprobacion-publicacion.json'
)
$ErrorActionPreference = 'Stop'
foreach ($url in @($TiendaUrl, $N8nUrl)) {
    if ($url.Scheme -ne 'https' -or $url.IsLoopback -or $url.Host -match '\.local$|\.internal$|\.example$') {
        throw 'Indica URLs públicas HTTPS reales, sin localhost ni dominios de ejemplo.'
    }
}
Add-Type -AssemblyName System.Net.Http
$handler = [System.Net.Http.HttpClientHandler]::new()
$handler.AllowAutoRedirect = $false
$client = [System.Net.Http.HttpClient]::new($handler)
$client.Timeout = [TimeSpan]::FromSeconds(120)
$checks = [System.Collections.Generic.List[object]]::new()
function Check-Endpoint([string]$Name, [string]$Url, [int]$Expected, [string]$Token = '', [string]$Contains = '') {
    $request = [System.Net.Http.HttpRequestMessage]::new([System.Net.Http.HttpMethod]::Get, $Url)
    $response = $null
    try {
        if ($Token) { $request.Headers.Authorization = [System.Net.Http.Headers.AuthenticationHeaderValue]::new('Bearer', $Token) }
        $response = $client.SendAsync($request).GetAwaiter().GetResult()
        $body = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        $code = [int]$response.StatusCode
        $ok = $code -eq $Expected -and (-not $Contains -or $body.Contains($Contains))
        $checks.Add([pscustomobject]@{ prueba = $Name; url = $Url; http = $code; esperado = $Expected; correcto = $ok })
    } catch {
        # No volcar cabeceras, cuerpo o excepciones que puedan incluir datos privados.
        $checks.Add([pscustomobject]@{ prueba = $Name; url = $Url; http = $null; esperado = $Expected; correcto = $false })
    } finally {
        if ($null -ne $response) { $response.Dispose() }
        $request.Dispose()
    }
}
try {
    $shop = $TiendaUrl.AbsoluteUri.TrimEnd('/')
    $automation = $N8nUrl.AbsoluteUri.TrimEnd('/')
    Check-Endpoint 'Tienda disponible' "$shop/health" 200 '' '"status":"ok"'
    Check-Endpoint 'Politicas publicadas' "$shop/politicas" 200 '' 'Prototipo académico sin actividad comercial.'
    Check-Endpoint 'Back-office exige login' "$shop/bo" 303
    Check-Endpoint 'API protegida' "$shop/bo/api/v1/metrics" 401
    Check-Endpoint 'n8n disponible' "$automation/healthz" 200
    if ($env:ZAPATERO_API_TOKEN) { Check-Endpoint 'API de tienda autenticada' "$shop/api/v1/events?limit=1" 200 $env:ZAPATERO_API_TOKEN 'api_version' }
    if ($env:BO_API_TOKEN) { Check-Endpoint 'API de back-office autenticada' "$shop/bo/api/v1/metrics" 200 $env:BO_API_TOKEN }
    $ok = @($checks | Where-Object { -not $_.correcto }).Count -eq 0
    $result = [ordered]@{
        fecha_utc = [DateTime]::UtcNow.ToString('o')
        resultado = if ($ok) { 'correcto' } else { 'fallido' }
        alcance = 'Disponibilidad HTTP desde el equipo ejecutor; no demuestra por si sola los tres escenarios ni una segunda red.'
        pruebas = $checks.ToArray()
    }
    $parent = Split-Path -Parent $Salida
    if ($parent -and -not (Test-Path -LiteralPath $parent)) { New-Item -ItemType Directory -Path $parent -Force | Out-Null }
    $result | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $Salida -Encoding utf8
    $checks | Format-Table prueba,http,esperado,correcto -AutoSize
    Write-Host "Informe sin secretos: $Salida"
    if (-not $ok) { exit 1 }
} finally {
    $client.Dispose()
    $handler.Dispose()
}
