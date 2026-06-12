$path = 'C:\Users\RMMF\Herd\laracasts-the-batteries-included-ai-toolkit\README.md'
$bytes = [System.IO.File]::ReadAllBytes($path)
if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
    'UTF-8 BOM'
} else {
    'No BOM (length=' + $bytes.Length + ')'
}
