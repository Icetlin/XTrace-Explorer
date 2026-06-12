<?php
// Debug test: process a small section of the trace file around line 1935817

$xtFile = '/app/var/traces/34/trace.xt';
$fh = fopen($xtFile, 'rb');
$lineNo = 0;

$windowSize = 800;
$window = array_fill(0, $windowSize, null);
$windowHead = 0;

$debugLines = range(1935810, 1935820);
$foundQueries = [];

while (($line = fgets($fh, 1048576)) !== false) {
    $lineNo++;
    
    if (!in_array($lineNo, $debugLines) && $lineNo < 1935810) continue;
    if ($lineNo > 1935820 && $lineNo < 1936040) continue;
    if ($lineNo > 1936050) break;
    
    // Detect call lines
    if (!preg_match('#^\s+[\d.]+\s+(\d+)([ ]*)->\s+(.+?)\(#', $line, $callM)) {
        continue;
    }
    
    $depth = (int)(strlen($callM[1]) / 2);
    $rawSig = trim($callM[3]);
    
    // Check for executeQuery
    if (str_contains($rawSig, 'Connection->executeQuery')) {
        echo "Line $lineNo: EXECUTEQUERY depth=$depth\n";
        
        // Scan window for caller
        $idx = $windowHead === 0 ? $windowSize - 1 : $windowHead - 1;
        $bestCaller = null;
        $bestDepth = -1;
        
        for ($scan = 0; $scan < $windowSize; $scan++) {
            if ($window[$idx] === null) {
                if (--$idx < 0) $idx = $windowSize - 1;
                if ($scan > 0 && $idx === $windowHead - 1) break;
                continue;
            }
            [$wLine, $wSig, $wFile, $wDepth] = $window[$idx];
            
            $isBusiness = (str_contains($wSig, 'Repository')
                || str_contains($wSig, 'Service')
                || str_contains($wSig, 'Getter')
                || str_contains($wSig, 'DataGetter'))
                && !str_contains($wSig, '\\Doctrine\\')
                && !str_contains($wSig, 'Filter');

            if ($isBusiness && $wDepth > $bestDepth) {
                $bestDepth = $wDepth;
                $bestCaller = [$wLine, $wSig, $wFile, $wDepth];
            }

            if (--$idx < 0) $idx = $windowSize - 1;
            if ($idx === $windowHead) break;
        }
        
        if ($bestCaller !== null) {
            [$wLine, $wSig, $wFile] = $bestCaller;
            echo "  CALLER: $wSig (line $wLine)\n";
        } else {
            echo "  NO CALLER (window has " . count(array_filter($window)) . " entries)\n";
            // Show first few window entries
            $count = 0;
            for ($i = 0; $i < $windowSize && $count < 5; $i++) {
                if ($window[$i] !== null) {
                    [$wLine, $wSig, $wFile] = $window[$i];
                    echo "    window[$i]: $wSig at $wLine\n";
                    $count++;
                }
            }
        }
        continue;
    }
    
    // Check for App\src call - USE THE CORRECT PATTERN
    // Pattern from line 652: '#App\\\\[^\(]+#' which becomes #App\\[^\(]+# in regex
    if (str_contains($line, '/src/') && preg_match('#App\\\\[^\(]+#', $rawSig)) {
        $sig = null;
        $file = '';
        
        if (preg_match('#^(App\\[^\(]+)\(#', $rawSig, $sigM)) {
            $sig = $sigM[1];
        } elseif (preg_match('#^App[^\(]+#', $rawSig, $sigM)) {
            $sig = $sigM[0];
        }
        
        if (preg_match('#/src/([^\s]+):(\d+)$#', $line, $fileM)) {
            $file = 'src/' . $fileM[1] . ':' . $fileM[2];
        }
        
        if ($sig !== null) {
            echo "Line $lineNo: App call depth=$depth sig=$sig file=$file\n";
            $window[$windowHead] = [$lineNo, $sig, $file, $depth];
            $windowHead = ($windowHead + 1) % $windowSize;
        }
    }
}

fclose($fh);