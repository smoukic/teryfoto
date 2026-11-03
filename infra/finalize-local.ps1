$ErrorActionPreference = 'Stop'
$hostsFile = 'C:/Windows/System32/drivers/etc/hosts'
$content = Get-Content $hostsFile -Raw
$content = $content.Replace('127.0.0.1 teryfoto.test teryfoto', '127.0.0.1 teryfoto')
[IO.File]::WriteAllText($hostsFile, $content, [Text.Encoding]::ASCII)
$vhostsFile = 'C:/xampp/apache/conf/extra/httpd-vhosts.conf'
$config = Get-Content $vhostsFile -Raw
$config = $config.Replace("ServerName teryfoto.test`n    ServerAlias teryfoto", 'ServerName teryfoto').Replace("ServerName teryfoto.test`r`n    ServerAlias teryfoto", 'ServerName teryfoto')
[IO.File]::WriteAllText($vhostsFile, $config, [Text.Encoding]::ASCII)
& C:/xampp/apache/bin/httpd.exe -t
if ($LASTEXITCODE -ne 0) { throw 'Invalid Apache configuration' }
& C:/xampp/apache/bin/httpd.exe -k restart
