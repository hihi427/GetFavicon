<?php
// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 定义常量
define('CACHE_DIR', __DIR__ . '/cache');
define('DEFAULT_ICON', __DIR__ . '/default.ico');
define('CACHE_EXPIRE', 30 * 24 * 60 * 60); // 30天
define('YANDEX_DEFAULT_SHA1', '086b07df148df70aac37ead9868b2df44ab91576');
define('SPLITBEE_DEFAULT_SHA1', '2d7c9b60d1e2b4f4726141de2e4ab738110b9287');

// 创建cache目录
if (!file_exists(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

// 获取URL参数
$url = isset($_GET['url']) ? trim($_GET['url']) : '';
if (empty($url)) {
    header('HTTP/1.1 400 Bad Request');
    die('Missing url parameter');
}

// 格式化域名
$domain = formatDomain($url);
$cacheFile = CACHE_DIR . '/' . md5($domain) . '.ico';

// 检查缓存是否存在且未过期
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_EXPIRE) {
    serveIcon($cacheFile);
    exit;
}

// 尝试从Yandex获取favicon
$iconData = tryYandex($domain);
if ($iconData === null) {
    // 尝试从Splitbee获取favicon
    $iconData = trySplitbee($domain);
}

if ($iconData === null) {
    // 如果都失败，使用默认图标
    if (file_exists(DEFAULT_ICON)) {
        // 创建软链接
        if (!file_exists($cacheFile)) {
            symlink(DEFAULT_ICON, $cacheFile);
        }
        serveIcon(DEFAULT_ICON);
    } else {
        header('HTTP/1.1 404 Not Found');
        die('Favicon not found and no default icon available');
    }
} else {
    // 保存到缓存
    file_put_contents($cacheFile, $iconData);
    serveIcon($cacheFile);
}

/**
 * 格式化域名
 */
function formatDomain($url) {
    // 移除协议和路径
    $domain = preg_replace('~^(https?://)?(www\.)?~i', '', $url);
    
    // 移除路径和查询参数
    $domain = explode('/', $domain)[0];
    $domain = explode('?', $domain)[0];
    $domain = explode('#', $domain)[0];
    
    return strtolower($domain);
}

/**
 * 尝试从Yandex获取favicon
 */
function tryYandex($domain) {
    $url = "https://favicon.yandex.net/favicon/v2/{$domain}?size=32";
    $iconData = downloadIcon($url);
    
    if ($iconData === null) {
        return null;
    }
    
    $sha1 = sha1($iconData);
    if ($sha1 !== YANDEX_DEFAULT_SHA1) {
        return $iconData;
    }
    
    // 尝试加上www前缀
    $wwwDomain = 'www.' . $domain;
    $url = "https://favicon.yandex.net/favicon/v2/{$wwwDomain}?size=32";
    $wwwIconData = downloadIcon($url);
    
    if ($wwwIconData === null) {
        return $iconData;
    }
    
    $wwwSha1 = sha1($wwwIconData);
    if ($wwwSha1 !== YANDEX_DEFAULT_SHA1) {
        return $wwwIconData;
    }
    
    return null;
}

/**
 * 尝试从Splitbee获取favicon
 */
function trySplitbee($domain) {
    $url = "https://favicon.splitbee.io/?url={$domain}";
    $iconData = downloadIcon($url);
    
    if ($iconData === null) {
        return null;
    }
    
    $sha1 = sha1($iconData);
    if ($sha1 !== SPLITBEE_DEFAULT_SHA1) {
        return $iconData;
    }
    
    // 尝试加上www前缀
    $wwwDomain = 'www.' . $domain;
    $url = "https://favicon.splitbee.io/?url={$wwwDomain}";
    $wwwIconData = downloadIcon($url);
    
    if ($wwwIconData === null) {
        return $iconData;
    }
    
    $wwwSha1 = sha1($wwwIconData);
    if ($wwwSha1 !== SPLITBEE_DEFAULT_SHA1) {
        return $wwwIconData;
    }
    
    return null;
}

/**
 * 下载图标
 */
function downloadIcon($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($data)) {
        return null;
    }
    
    return $data;
}

/**
 * 输出图标
 */
function serveIcon($file) {
    $mime = 'image/x-icon';
    $lastModified = filemtime($file);
    $etag = md5_file($file);
    
    header('Content-Type: ' . $mime);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
    header('ETag: "' . $etag . '"');
    
    // 检查客户端缓存
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && 
        strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $lastModified) {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }
    
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && 
        trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }
    
    readfile($file);
}
?>
