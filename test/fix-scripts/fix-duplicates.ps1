$filePath = "c:\Users\lovel\source\repos\gevans3000\wp-plugin\sumai.php"
$content = Get-Content -Path $filePath -Raw

# Find the start and end positions of the duplicate function
$pattern = '(?s)function sumai_generate_daily_summary\(bool \$force_fetch = false, bool \$draft_mode = false, string \$status_id = ''\) \{.*?\n\}'
$matches = [regex]::Matches($content, $pattern)

if ($matches.Count -gt 1) {
    Write-Host "Found $($matches.Count) occurrences of sumai_generate_daily_summary"
    
    # Keep the first occurrence, remove the second
    $secondMatch = $matches[1]
    $startPos = $secondMatch.Index
    $endPos = $startPos + $secondMatch.Length
    
    # Get the content before and after the duplicate function
    $beforeContent = $content.Substring(0, $startPos)
    $afterContent = $content.Substring($endPos)
    
    # Replace the duplicate with a comment
    $replacementText = "// Function sumai_generate_daily_summary is already defined earlier in the file (line 416)
// Removing duplicate declaration to fix fatal error

"
    
    # Create the new content
    $newContent = $beforeContent + $replacementText + $afterContent
    
    # Write the new content back to the file
    Set-Content -Path $filePath -Value $newContent
    
    Write-Host "Duplicate function removed successfully!"
} else {
    Write-Host "No duplicates found or pattern didn't match correctly."
}
