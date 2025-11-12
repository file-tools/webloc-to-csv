<?php
/**
 * WebLoc to CSV Converter
 * Scans a directory for .webloc files and exports their information to CSV
 */

// ============================================================================
// CONFIGURATION - Set your path here
// ============================================================================
$basePath = 'xxxx';  // Change this to your target directory
$outputCsv = 'webloc_export_' . date('Y-m-d_H-i-s') . '.csv';

// ============================================================================
// MAIN SCRIPT
// ============================================================================

// Validate base path
if (!is_dir($basePath)) {
    die("Error: Base path does not exist: $basePath\n");
}

// Normalize base path (remove trailing slash)
$basePath = rtrim($basePath, '/');

echo "Scanning directory: $basePath\n";
echo "Output file: $outputCsv\n\n";

// Open CSV file for writing
$csvFile = fopen($outputCsv, 'w');
if (!$csvFile) {
    die("Error: Could not create CSV file: $outputCsv\n");
}

// Write CSV header
fputcsv($csvFile, ['Filename', 'URL', 'Creation Date', 'Relative Path']);

// Initialize counters
$totalFiles = 0;
$successCount = 0;
$errorCount = 0;

// Recursively scan for .webloc files
try {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        // Only process .webloc files
        if ($file->isFile() && strtolower($file->getExtension()) === 'webloc') {
            $totalFiles++;
            $filePath = $file->getPathname();
            
            echo "Processing: $filePath\n";
            
            // Extract information
            $filename = $file->getFilename();
            $url = extractUrlFromWebloc($filePath);
            $creationDate = getMacCreationDate($filePath);
            
            // Calculate relative path from base path
            $relativePath = str_replace($basePath, '', $file->getPath());
            $relativePath = ltrim($relativePath, '/');
            if ($relativePath === '') {
                $relativePath = '/';
            }
            
            // Write to CSV
            if ($url !== null) {
                fputcsv($csvFile, [$filename, $url, $creationDate, $relativePath]);
                $successCount++;
            } else {
                echo "  ⚠️  Warning: Could not extract URL from $filename\n";
                fputcsv($csvFile, [$filename, '[ERROR: Could not extract URL]', $creationDate, $relativePath]);
                $errorCount++;
            }
        }
    }
} catch (Exception $e) {
    fclose($csvFile);
    die("Error scanning directory: " . $e->getMessage() . "\n");
}

// Close CSV file
fclose($csvFile);

// Print summary
echo "\n" . str_repeat('=', 60) . "\n";
echo "SUMMARY\n";
echo str_repeat('=', 60) . "\n";
echo "Total .webloc files found: $totalFiles\n";
echo "Successfully processed: $successCount\n";
echo "Errors: $errorCount\n";
echo "Output saved to: $outputCsv\n";

/**
 * Get the actual creation date (birth time) of a file on macOS
 * 
 * Uses the stat command to retrieve the birth time, which is the actual
 * file creation date on macOS (not inode change time)
 * 
 * @param string $filePath Path to the file
 * @return string The formatted creation date or 'Unknown' on failure
 */
function getMacCreationDate($filePath) {
    // Escape the file path for shell command
    $escapedPath = escapeshellarg($filePath);
    
    // Use macOS stat command with -f %B to get birth time (creation time) as Unix timestamp
    $command = "stat -f %B $escapedPath 2>/dev/null";
    $timestamp = trim(shell_exec($command));
    
    if ($timestamp && is_numeric($timestamp)) {
        return date('Y-m-d H:i:s', $timestamp);
    }
    
    // Fallback to filectime if stat command fails
    return date('Y-m-d H:i:s', filectime($filePath));
}

/**
 * Extract URL from a .webloc file
 * 
 * .webloc files are XML plist files that contain a URL
 * 
 * @param string $filePath Path to the .webloc file
 * @return string|null The extracted URL or null on failure
 */
function extractUrlFromWebloc($filePath) {
    // Read file contents
    $contents = @file_get_contents($filePath);
    if ($contents === false) {
        return null;
    }
    
    // Suppress XML errors for malformed files
    libxml_use_internal_errors(true);
    
    // Try to parse as XML plist
    $xml = @simplexml_load_string($contents);
    
    if ($xml === false) {
        libxml_clear_errors();
        return null;
    }
    
    // Navigate the plist structure to find the URL
    // Structure: <plist><dict><key>URL</key><string>URL_HERE</string></dict></plist>
    if (isset($xml->dict)) {
        $dict = $xml->dict;
        $key = null;
        
        foreach ($dict->children() as $child) {
            if ($child->getName() === 'key' && (string)$child === 'URL') {
                $key = $child;
            } elseif ($key !== null && $child->getName() === 'string') {
                return (string)$child;
            }
        }
    }
    
    return null;
}

?>

