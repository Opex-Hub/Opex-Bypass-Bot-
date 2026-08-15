<?php
header('Content-Type: application/json');

// Get the URL from the request
 $url = isset($_GET['url']) ? trim($_GET['url']) : '';

if (empty($url)) {
    echo json_encode(['error' => 'No URL provided.']);
    exit;
}

// Validate URL
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['error' => 'Invalid URL format.']);
    exit;
}

 $hops = [];
 $currentUrl = $url;
 $maxHops = 15; // Prevent infinite loops
 $visited = []; 

function resolveUrl($base, $relative) {
    if (strpos($relative, 'http') === 0) return $relative;
    $baseParts = parse_url($base);
    $scheme = isset($baseParts['scheme']) ? $baseParts['scheme'] : 'https';
    $host = isset($baseParts['host']) ? $baseParts['host'] : '';
    
    if (strpos($relative, '//') === 0) {
        return $scheme . ':' . $relative;
    } elseif (strpos($relative, '/') === 0) {
        return $scheme . '://' . $host . $relative;
    } else {
        $path = isset($baseParts['path']) ? dirname($baseParts['path']) : '/';
        return $scheme . '://' . $host . $path . '/' . $relative;
    }
}

function fetchUrl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Handle redirects manually to track them
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    
    return ['body' => $body, 'code' => $httpCode, 'redirect' => $redirectUrl];
}

while ($maxHops-- > 0) {
    if (in_array($currentUrl, $visited)) {
        break; // Break if we see the same URL again to prevent loops
    }
    $visited[] = $currentUrl;
    $hops[] = $currentUrl;
    
    $response = fetchUrl($currentUrl);
    $nextUrl = null;
    
    // 1. Check for standard HTTP redirects (301, 302, 307, 308)
    if (!empty($response['redirect'])) {
        $nextUrl = resolveUrl($currentUrl, $response['redirect']);
    } 
    // 2. If no HTTP redirect, check the HTML body for "Task" redirects
    else if (!empty($response['body'])) {
        $body = $response['body'];
        
        // A. Meta Refresh Redirect (Very common on shorteners: <meta http-equiv="refresh" content="0; url=...">)
        if (preg_match('/<meta[^>]+http-equiv=["\']refresh["\'][^>]+content=["\'][^"\']*url=([^"\']+)/i', $body, $matches)) {
            $nextUrl = resolveUrl($currentUrl, trim($matches[1]));
        }
        // B. JavaScript window.location redirects
        else if (preg_match('/window\.location(?:\.href)?\s*=\s*["\']([^"\']+)["\']/i', $body, $matches)) {
            $nextUrl = resolveUrl($currentUrl, trim($matches[1]));
        }
        // C. JavaScript location.replace redirects
        else if (preg_match('/location\.replace\(["\']([^"\']+)["\']\)/i', $body, $matches)) {
            $nextUrl = resolveUrl($currentUrl, trim($matches[1]));
        }
        // D. Common "Continue" form auto-submit (some shorteners use this)
        else if (preg_match('/<form[^>]+action=["\']([^"\']+)["\'][^>]*id=["\'][^"\']*redirect/i', $body, $matches)) {
            $nextUrl = resolveUrl($currentUrl, trim($matches[1]));
        }
    }
    
    // If we found a next URL, follow it
    if ($nextUrl && $nextUrl !== $currentUrl) {
        $currentUrl = $nextUrl;
    } else {
        break; // No more redirects found, we reached the destination
    }
}

echo json_encode(['hops' => $hops]);
exit;
?>
