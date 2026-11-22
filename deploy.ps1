param(
  [string]$File = ""
)

$SSH_KEY = "C:/Users/onlym/.ssh/vdt_hostinger"
$REMOTE = "u993876486@82.25.102.131"
$PORT = "65002"
$REMOTE_PATH = "/home/u993876486/domains/text-humanize.com/public_html"

if ($File -ne "") {
  Write-Host "Deploying single file: $File" -ForegroundColor Cyan
  $localPath = Join-Path "C:\Users\onlym\development\text-humanize" $File
  $remoteDest = "$REMOTE_PATH/$File".Replace("\", "/")
  scp -P $PORT -i $SSH_KEY -o StrictHostKeyChecking=no "$localPath" "${REMOTE}:${remoteDest}"
  ssh -p $PORT -i $SSH_KEY -o StrictHostKeyChecking=no $REMOTE "chmod 644 '$remoteDest'"
} else {
  Write-Host "Deploying all files to Hostinger production..." -ForegroundColor Cyan
  scp -P $PORT -i $SSH_KEY -o StrictHostKeyChecking=no -r C:/Users/onlym/development/text-humanize/* "${REMOTE}:${REMOTE_PATH}/"
  ssh -p $PORT -i $SSH_KEY -o StrictHostKeyChecking=no $REMOTE "find $REMOTE_PATH/ -type f -exec chmod 644 {} + && find $REMOTE_PATH/ -type d -exec chmod 755 {} +"
}

Write-Host "Deployment complete! Live at https://text-humanize.com/" -ForegroundColor Green
