<#
.SYNOPSIS
  Envía un payload de prueba al webhook de n8n (flujo WF1).
.EXAMPLE
  .\n8n\probar.ps1 -Payload 01-esc1-pedido-creado.json -Order ZAP-20261101-0001
  .\n8n\probar.ps1 -Payload 01-esc1-pedido-creado.json -Order ZAP-20261101-0001 -EventId evt_0123456789abcdef0123456789abcdef   # repetir => duplicate
  .\n8n\probar.ps1 -Payload 90-error-payload-invalido.json                                                                   # rama de error
  .\n8n\probar.ps1 -Payload 03-esc3-incidencia.json -Order ZAP-20261101-0001 -Flow wf2                                         # Escenario 3 (incidencia → agente)
  .\n8n\probar.ps1 -Payload 04-esc3-soporte.json -Order ZAP-20261101-0001 -Ticket 2 -Flow wf2                                  # Escenario 3 (soporte real: ticket existente)
#>
param(
    [Parameter(Mandatory = $true)][string]$Payload,
    [string]$Order = '',
    [string]$EventId = '',
    [string]$Ticket = '1',
    [ValidateSet('wf1', 'wf2')][string]$Flow = 'wf1',
    [string]$Url = '',
    [string]$Secret = ''
)
$ErrorActionPreference = 'Stop'
$dir = Split-Path -Parent $MyInvocation.MyCommand.Path

if (-not $Secret) {
    $envFile = Join-Path $dir 'n8n.env'
    if (Test-Path $envFile) {
        $line = Select-String -Path $envFile -Pattern '^N8N_WEBHOOK_SECRET=' | Select-Object -First 1
        if ($line) { $Secret = $line.Line.Split('=', 2)[1].Trim() }
    }
}
if (-not $Secret) { throw 'Falta N8N_WEBHOOK_SECRET en n8n\n8n.env (o usa -Secret).' }

$file = Join-Path (Join-Path $dir 'payloads') $Payload
if (-not (Test-Path $file)) { throw "No existe el payload: $file" }
if (-not $EventId) { $EventId = 'evt_' + [guid]::NewGuid().ToString('N') }
if (-not $Url) { $Url = if ($Flow -eq 'wf2') { 'http://localhost:5678/webhook/zapatero-incidencia' } else { 'http://localhost:5678/webhook/zapatero-event' } }
$now = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')

$body = (Get-Content $file -Raw -Encoding UTF8).Replace('__EVENT_ID__', $EventId).Replace('__ORDER_CODE__', $Order).Replace('__NOW__', $now).Replace('__TICKET_ID__', $Ticket)
Write-Host "POST $Url" -ForegroundColor Cyan
Write-Host $body -ForegroundColor DarkGray

try {
    $r = Invoke-RestMethod -Method Post -Uri $Url -ContentType 'application/json; charset=utf-8' `
        -Headers @{ 'X-Webhook-Secret' = $Secret } -Body ([System.Text.Encoding]::UTF8.GetBytes($body))
    Write-Host "`nRespuesta de n8n:" -ForegroundColor Green
    $r | ConvertTo-Json -Depth 10
} catch {
    Write-Host "`nError HTTP:" -ForegroundColor Red
    Write-Host $_.Exception.Message
    if ($_.ErrorDetails) { Write-Host $_.ErrorDetails.Message }
    Write-Host "`nPistas: ¿el workflow está activado/publicado? ¿n8n está en marcha (docker ps)?" -ForegroundColor Yellow
}
