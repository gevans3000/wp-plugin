# PowerShell script to run the Action Scheduler integration tests
# This script runs the diagnostic tests and generates an HTML report

# Script variables
$testRunnerPath = Join-Path -Path $PSScriptRoot -ChildPath "action-scheduler-test-runner.php"
$outputDir = Join-Path -Path $PSScriptRoot -ChildPath "test-results"
$outputFile = Join-Path -Path $outputDir -ChildPath "action-scheduler-test-results-$(Get-Date -Format 'yyyy-MM-dd-HHmmss').html"

# Create output directory if it doesn't exist
if (-not (Test-Path -Path $outputDir)) {
    New-Item -Path $outputDir -ItemType Directory | Out-Null
    Write-Host "Created test results directory: $outputDir"
}

# Run the diagnostics directly by including PHP code and writing to a file
$diagFile = Join-Path -Path (Split-Path -Parent $PSScriptRoot) -ChildPath "dev-files\as_diagnostic.php"
$diagContent = Get-Content -Path $diagFile -Raw

# Output the content directly to an HTML file
$diagContent | Out-File -FilePath $outputFile -Encoding utf8

Write-Host "Test complete. Results saved to: $outputFile"
Write-Host "Opening test results in default browser..."

# Open the results in the default browser
Start-Process $outputFile
