# OMUN Kennungen - Windows.
#
# Opens ..\app\index.html in the browser and does for it what a web page may not
# do itself: read and write the list of handed-out codes (code + name) in a file
# on the desktop and keep the website key. Runs only on this computer
# (127.0.0.1) and needs nothing but Windows itself (Windows PowerShell 5.1).
#
# Start: double-click "OMUN Kennungen.cmd". Stop: close the black window.

$ErrorActionPreference = 'Stop'
$port = 8765
$prefix = "http://127.0.0.1:$port/"
$page = Join-Path $PSScriptRoot '..\app\index.html'
$listFile = if ($env:OMUN_KENNUNGEN_FILE) { $env:OMUN_KENNUNGEN_FILE } else { Join-Path ([Environment]::GetFolderPath('Desktop')) 'OMUN-Kennungen.csv' }
$settingsDir = Join-Path $env:APPDATA 'OMUN Kennungen'
$settingsFile = Join-Path $settingsDir 'settings.json'
$utf8 = New-Object System.Text.UTF8Encoding($false)

function Normalize-Code([string]$s) { return ($s.ToUpper() -replace '[^A-Z0-9]', '') }
function Test-Code([string]$c) { return ($c -cmatch '^[A-HJ-NP-Z2-9]{8}$') -and ($c -match '\d') -and ($c -cmatch '[A-Z]') }

# Splits CSV text into rows of cells (";" separated, quotes doubled inside quoted cells).
function Read-Csv([string]$text) {
    $rows = New-Object System.Collections.ArrayList
    $row = New-Object System.Collections.ArrayList
    $cell = New-Object System.Text.StringBuilder
    $quoted = $false
    $text = $text.Replace("`r`n", "`n").Replace([string][char]0xFEFF, '')
    for ($i = 0; $i -lt $text.Length; $i++) {
        $ch = $text[$i]
        if ($quoted) {
            if ($ch -eq '"') {
                if ($i + 1 -lt $text.Length -and $text[$i + 1] -eq '"') { [void]$cell.Append('"'); $i++ } else { $quoted = $false }
            } else { [void]$cell.Append($ch) }
        }
        elseif ($ch -eq '"') { $quoted = $true }
        elseif ($ch -eq ';') { [void]$row.Add($cell.ToString()); [void]$cell.Clear() }
        elseif ($ch -eq "`n") { [void]$row.Add($cell.ToString()); [void]$rows.Add($row.ToArray()); $row = New-Object System.Collections.ArrayList; [void]$cell.Clear() }
        else { [void]$cell.Append($ch) }
    }
    if ($cell.Length -gt 0 -or $row.Count -gt 0) { [void]$row.Add($cell.ToString()); [void]$rows.Add($row.ToArray()) }
    return ,$rows
}

function Get-List {
    $out = New-Object System.Collections.ArrayList
    if (Test-Path -LiteralPath $listFile) {
        foreach ($cells in (Read-Csv ([System.IO.File]::ReadAllText($listFile, $utf8)))) {
            $code = Normalize-Code ([string]$cells[0])
            if (Test-Code $code) {
                $name = ''; $date = ''
                if ($cells.Count -gt 1) { $name = ([string]$cells[1]).Trim() }
                if ($cells.Count -gt 2) { $date = ([string]$cells[2]).Trim() }
                [void]$out.Add(@{ code = $code; name = $name; date = $date })
            }
        }
    }
    return ,$out
}

function Add-ToList($rows) {
    $have = @{}
    foreach ($r in (Get-List)) { $have[$r.code] = $true }
    $sb = New-Object System.Text.StringBuilder
    if (-not (Test-Path -LiteralPath $listFile) -or (Get-Item -LiteralPath $listFile).Length -eq 0) {
        [void]$sb.Append([char]0xFEFF).Append("Kennung;Name;Erzeugt am`r`n")   # BOM, so Excel shows umlauts correctly
    }
    $stamp = (Get-Date).ToString('dd.MM.yyyy HH:mm')
    $added = 0
    foreach ($r in $rows) {
        $code = Normalize-Code ([string]$r.code)
        if (-not (Test-Code $code) -or $have.ContainsKey($code)) { continue }
        $have[$code] = $true
        $name = ([string]$r.name).Trim()
        if ($name.Length -gt 300) { $name = $name.Substring(0, 300) }
        if ($name -match '^[=+\-@]') { $name = "'" + $name }   # no formulas in Excel
        if ($name -match '[";\n]') { $name = '"' + $name.Replace('"', '""') + '"' }
        [void]$sb.Append($code.Substring(0, 4)).Append('-').Append($code.Substring(4)).Append(';').Append($name).Append(';').Append($stamp).Append("`r`n")
        $added++
    }
    [System.IO.File]::AppendAllText($listFile, $sb.ToString(), $utf8)
    return $added
}

function Send-Response($context, [int]$status, [string]$type, [string]$body) {
    $bytes = $utf8.GetBytes($body)
    $context.Response.StatusCode = $status
    $context.Response.ContentType = $type
    $context.Response.Headers['Cache-Control'] = 'no-store'
    $context.Response.ContentLength64 = $bytes.Length
    $context.Response.OutputStream.Write($bytes, 0, $bytes.Length)
    $context.Response.OutputStream.Close()
}
function Send-Json($context, $data, [int]$status = 200) {
    Send-Response $context $status 'application/json; charset=utf-8' (ConvertTo-Json $data -Depth 5 -Compress)
}

$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add($prefix)
try { $listener.Start() } catch {
    Write-Host "OMUN Kennungen laeuft schon (oder Port $port ist belegt). Der Browser wird geoeffnet."
    Start-Process $prefix
    exit
}
Write-Host "OMUN Kennungen laeuft: $prefix"
Write-Host "Liste: $listFile"
Write-Host "Zum Beenden dieses Fenster schliessen."
Start-Process $prefix

while ($listener.IsListening) {
    $context = $listener.GetContext()
    try {
        $req = $context.Request
        $path = $req.Url.AbsolutePath
        # Only this computer, and only this page: no other website may read or write the list.
        $origin = $req.Headers['Origin']
        if ($req.Url.Host -ne '127.0.0.1' -or ($origin -and $origin -ne "http://127.0.0.1:$port")) {
            Send-Response $context 403 'text/plain' 'Forbidden'; continue
        }
        if ($req.HttpMethod -eq 'GET' -and ($path -eq '/' -or $path -eq '/index.html')) {
            Send-Response $context 200 'text/html; charset=utf-8' ([System.IO.File]::ReadAllText($page, $utf8)); continue
        }
        if ($req.HttpMethod -ne 'POST' -or -not $path.StartsWith('/api/') -or -not ([string]$req.ContentType).StartsWith('application/json')) {
            Send-Response $context 404 'text/plain' 'Not found'; continue
        }
        $reader = New-Object System.IO.StreamReader($req.InputStream, $utf8)
        $in = $reader.ReadToEnd() | ConvertFrom-Json
        switch ($path.Substring(5)) {
            'list' { Send-Json $context @{ file = $listFile; rows = @(Get-List) } }
            'add' {
                try {
                    $added = Add-ToList @($in.rows)
                    Send-Json $context @{ file = $listFile; added = $added; rows = @(Get-List) }
                } catch {
                    Send-Json $context @{ error = "Die Datei konnte nicht geschrieben werden: $listFile (ist sie gerade in Excel geoeffnet?)" }
                }
            }
            'settings' {
                $s = @{ site = ''; key = '' }
                if (Test-Path -LiteralPath $settingsFile) {
                    $saved = [System.IO.File]::ReadAllText($settingsFile, $utf8) | ConvertFrom-Json
                    $s = @{ site = [string]$saved.site; key = [string]$saved.key }
                }
                Send-Json $context $s
            }
            'settings_set' {
                if (-not (Test-Path -LiteralPath $settingsDir)) { [void](New-Item -ItemType Directory -Path $settingsDir) }
                [System.IO.File]::WriteAllText($settingsFile, (ConvertTo-Json @{ site = [string]$in.site; key = [string]$in.key }), $utf8)
                Send-Json $context @{ ok = $true }
            }
            'reveal' {
                if (Test-Path -LiteralPath $listFile) { Start-Process explorer.exe "/select,`"$listFile`"" } else { Start-Process explorer.exe (Split-Path $listFile) }
                Send-Json $context @{ ok = $true }
            }
            default { Send-Response $context 404 'text/plain' 'Not found' }
        }
    } catch {
        try { Send-Json $context @{ error = "Fehler im Programm: $($_.Exception.Message)" } 500 } catch {}
    }
}
