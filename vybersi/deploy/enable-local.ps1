# Run in an elevated PowerShell.
$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$hostsPath = Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'
$apache = 'C:/xampp/apache/bin/httpd.exe'
$config = 'C:/xampp/apache/conf/extra/httpd-vhosts.conf'
$public = ($project -replace '\\', '/') + '/public'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backup = "$config.vybersi-$stamp.bak"
$original = Get-Content -LiteralPath $config -Raw
$block = @"
# BEGIN VYBERSI
<VirtualHost 127.0.0.1:80>
    ServerName nahledovky
    DocumentRoot "$public"
    php_admin_value upload_max_filesize 28M
    php_admin_value post_max_size 32M
    php_admin_value memory_limit 512M
    php_admin_value max_execution_time 120
    <Directory "$public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require local
    </Directory>
</VirtualHost>
# END VYBERSI
"@

$marker = '(?ms)^# BEGIN VYBERSI\r?\n.*?^# END VYBERSI'
if ($original -match $marker) {
    $updated = [regex]::Replace($original, $marker, [System.Text.RegularExpressions.MatchEvaluator] { param($match) $block })
} else {
    if ($original -match '(?im)^\s*ServerName\s+nahledovky\b') {
        throw 'VirtualHost nahledovky already exists outside the managed block.'
    }
    $updated = $original + "`r`n" + $block + "`r`n"
}
if ($updated -cne $original) {
    Copy-Item -LiteralPath $config -Destination $backup
    [IO.File]::WriteAllText($config, $updated, [Text.UTF8Encoding]::new($false))
    & $apache -t
    if ($LASTEXITCODE -ne 0) {
        Copy-Item -LiteralPath $backup -Destination $config -Force
        throw 'Apache config test failed. Original config restored.'
    }
}

$existing = Get-Content -LiteralPath $hostsPath | Where-Object {
    ($_ -split '#', 2)[0] -match '(?i)(^|\s)nahledovky(\s|$)'
}
if ($existing -and ($existing | Where-Object { $_ -notmatch '^\s*127\.0\.0\.1\s+' })) {
    throw 'V hosts už je jiné mapování pro nahledovky.'
}
if (-not $existing) {
    Add-Content -LiteralPath $hostsPath -Value "`r`n127.0.0.1 nahledovky" -Encoding ascii
}
ipconfig /flushdns | Out-Null

if ((Get-Service Apache2.4 -ErrorAction SilentlyContinue).Status -eq 'Running') {
    Restart-Service Apache2.4
} elseif (Get-Process httpd -ErrorAction SilentlyContinue) {
    & $apache -k restart
    if ($LASTEXITCODE -ne 0) { throw 'Apache restart failed.' }
} else {
    Start-Process -FilePath $apache -WorkingDirectory (Split-Path -Parent $apache) -WindowStyle Hidden
}
