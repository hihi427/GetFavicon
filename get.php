<?php
// 设置响应头为ICO图标格式
header('Content-Type: image/x-icon');

// 定义默认图标的SHA1哈希值
define('DEFAULT_ICON_SHA1', '086b07df148df70aac37ead9868b2df44ab91576');
// 定义缓存过期时间为30天（秒数）
define('CACHE_EXPIRE_SECONDS', 30 * 24 * 60 * 60);
// 定义缓存目录路径
define('CACHE_DIR', __DIR__ . '/cache/');
// 定义默认图标文件路径
define('DEFAULT_ICON', __DIR__ . '/default.ico');

// 检查并创建缓存目录（如果不存在）
if (!is_dir(CACHE_DIR) && !mkdir(CACHE_DIR, 0755, true) && !is_dir(CACHE_DIR)) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Failed to create cache directory');
}

// 处理无参数直接访问的情况
if (basename($_SERVER['SCRIPT_NAME']) === 'get.php' && empty(trim($_GET['url'] ?? ''))) {
    serveDefaultIcon();
    exit;
}

try {
    // 获取并规范化URL参数中的域名
    $domain = normalizeDomain($_GET['url'] ?? '');
    
    // 如果域名为空则返回默认图标
    if (empty($domain)) {
        serveDefaultIcon();
        exit;
    }
    
    // 生成基于域名MD5的缓存文件名
    $cacheFile = CACHE_DIR . md5($domain) . '.ico';
    
    // 检查缓存文件是否存在
    if (file_exists($cacheFile)) {
        // 如果是软链接则返回默认图标
        if (is_link($cacheFile)) {
            serveDefaultIcon();
            exit;
        }
        
        // 如果缓存未过期则直接输出缓存文件
        if (isCacheValid($cacheFile)) {
            readfile($cacheFile);
            exit;
        }
        
        // 删除过期的缓存文件
        unlink($cacheFile);
    }
    
    // 尝试获取带www回退的favicon数据
    $faviconData = fetchFaviconWithFallback($domain);
    
    // 处理获取到的favicon数据
    processFaviconResult($faviconData, $cacheFile);

} catch (Exception $e) {
    // 记录错误日志并返回默认图标
    error_log('Favicon Error: ' . $e->getMessage());
    serveDefaultIcon();
}

/**
 * 规范化域名处理函数
 * @param string $domain 原始域名
 * @return string 处理后的规范化域名
 */
function normalizeDomain($domain) {
    // 移除http://和https://协议头
    $domain = str_replace(['http://', 'https://'], '', $domain);
    // 移除www.前缀（不区分大小写）
    $domain = preg_replace('/^www\./i', '', $domain);
    // 返回小写并去除两端空格的域名
    return strtolower(trim($domain));
}

/**
 * 检查缓存是否有效
 * @param string $cacheFile 缓存文件路径
 * @return bool 是否有效
 */
function isCacheValid($cacheFile) {
    return (time() - filemtime($cacheFile)) < CACHE_EXPIRE_SECONDS;
}

/**
 * 输出默认图标
 */
function serveDefaultIcon() {
    // 检查默认图标文件是否存在
    if (file_exists(DEFAULT_ICON)) {
        readfile(DEFAULT_ICON);
    } else {
        // 不存在则返回404状态码
        header('HTTP/1.1 404 Not Found');
    }
}

/**
 * 获取带www回退的favicon数据
 * @param string $domain 域名
 * @return mixed 获取到的favicon数据
 */
function fetchFaviconWithFallback($domain) {
    // 首先尝试获取无www的favicon
    $faviconData = getFavicon($domain);
    
    // 如果获取到的是默认图标，则尝试带www的版本
    if ($faviconData && sha1($faviconData) === DEFAULT_ICON_SHA1) {
        $wwwData = getFavicon('www.' . $domain);
        return $wwwData ?: $faviconData;
    }
    
    return $faviconData;
}

/**
 * 处理获取到的favicon结果
 * @param mixed $faviconData favicon数据
 * @param string $cacheFile 缓存文件路径
 */
function processFaviconResult($faviconData, $cacheFile) {
    // 如果没有获取到数据则返回默认图标
    if (!$faviconData) {
        serveDefaultIcon();
        return;
    }
    
    // 检查是否为默认图标
    if (sha1($faviconData) === DEFAULT_ICON_SHA1) {
        // 创建指向默认图标的软链接
        if (file_exists(DEFAULT_ICON)) {
            @unlink($cacheFile);
            symlink(DEFAULT_ICON, $cacheFile);
        }
        serveDefaultIcon();
        return;
    }
    
    // 保存新获取的favicon到缓存文件并输出
    file_put_contents($cacheFile, $faviconData);
    echo $faviconData;
}

/**
 * 从Yandex获取favicon
 * @param string $domain 域名
 * @return mixed 获取到的favicon数据
 */
function getFavicon($domain) {
    // 使用静态变量保持CURL句柄
    static $ch = null;
    
    // 初始化CURL句柄
    if ($ch === null) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'Mozilla/5.0 Favicon Fetcher',
        ]);
    }
    
    // 设置Yandex favicon API URL
    curl_setopt($ch, CURLOPT_URL, 'https://favicon.yandex.net/favicon/v2/' . urlencode($domain) . '?size=32');
    // 执行请求获取数据
    $data = curl_exec($ch);
    
    // 返回成功获取的数据或false
    return (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && !empty($data)) ? $data : false;
}