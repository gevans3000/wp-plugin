# Sumai Action Scheduler Integration Diagnostic Runner
# This script executes the Action Scheduler integration diagnostic
# and displays the results in a web browser

# Set working directory
$workingDir = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $workingDir

# Define paths
$outputDir = Join-Path $workingDir "dev-files"
$outputFile = Join-Path $outputDir "diagnostic_results.html"
$diagnosticFile = Join-Path $outputDir "as_diagnostic.php"

# Create temporary PHP file to execute diagnostic and output to file
$tempPhpFile = Join-Path $env:TEMP "sumai_diagnostic_runner.php"
@"
<?php
// Capture output
ob_start();
include '$diagnosticFile';
`$output = ob_get_clean();

// Write to file
file_put_contents('$outputFile', `$output);
echo "Diagnostic complete. Results saved to: $outputFile\n";
?>
"@ | Out-File -FilePath $tempPhpFile -Encoding UTF8

# Check if PHP is available
try {
    $phpVersion = php -v
    Write-Host "Using PHP:" -ForegroundColor Cyan
    Write-Host $phpVersion[0] -ForegroundColor Cyan
}
catch {
    Write-Host "Error: PHP is not available in the PATH." -ForegroundColor Red
    Write-Host "Please make sure PHP is installed and added to your PATH environment variable." -ForegroundColor Red
    exit 1
}

# Execute the diagnostic
Write-Host "Running Sumai Action Scheduler integration diagnostic..." -ForegroundColor Yellow
php $tempPhpFile

# Clean up temporary file
Remove-Item $tempPhpFile -Force

# Open results in default browser
Start-Process $outputFile

Write-Host "Diagnostic complete!" -ForegroundColor Green
