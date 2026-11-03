$ErrorActionPreference = 'Stop'
$hostsFile = 'C:/Windows/System32/drivers/etc/hosts'
$stamp = Get-Date -Format yyyyMMdd-HHmmss
if ((Get-Content $hostsFile -Raw) -notmatch '(?m)^127\.0\.0\.1\s+teryfoto\s*$') {
    Copy-Item -LiteralPath $hostsFile -Destination "C:/webs/teryfoto/storage/hosts-$stamp.bak"
    Add-Content -LiteralPath $hostsFile -Value "`r`n127.0.0.1 teryfoto" -Encoding ascii
}
$vhostsFile = 'C:/xampp/apache/conf/extra/httpd-vhosts.conf'
if ((Get-Content $vhostsFile -Raw) -notmatch 'ServerName teryfoto\s') {
    Copy-Item -LiteralPath $vhostsFile -Destination "C:/webs/teryfoto/storage/vhosts-$stamp.bak"
    Add-Content -LiteralPath $vhostsFile -Value "`r`n$(Get-Content C:/webs/teryfoto/infra/apache-local.conf -Raw)" -Encoding ascii
}
& C:/xampp/apache/bin/httpd.exe -t
if ($LASTEXITCODE -ne 0) { throw 'Apache configuration failed' }
if (Get-Process httpd -ErrorAction SilentlyContinue) {
    & C:/xampp/apache/bin/httpd.exe -k restart
} else {
    Start-Process -FilePath C:/xampp/apache/bin/httpd.exe -WindowStyle Hidden
}
'Local setup completed' | Set-Content C:/webs/teryfoto/storage/local-setup.txt
