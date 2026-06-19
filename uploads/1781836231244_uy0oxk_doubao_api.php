<?php
/**
 * 豆包图片解析API
 * 功能：提取图片地址，过滤watermark链接
 * 此解析源码由DeepSeek编写，仅供学习参考，请勿用于非法用途。
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

class DoubaoImageParser {
    
    private $userAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";
    
    /**
     * 解析豆包图片 - 增强版，支持多次尝试
     * @param string $url 豆包分享链接
     * @param int $retryCount 重试次数
     * @return array 解析结果
     */
    public function parse($url, $retryCount = 3) {
        // 1. 提取URL
        $filteredUrl = $this->extractURL($url);
        if (!$filteredUrl) {
            return ['success' => false, 'message' => '无效的链接地址'];
        }
        
        // 2. 检查是否为豆包链接
        if (strpos($filteredUrl, 'doubao.com') === false) {
            return ['success' => false, 'message' => '不是豆包平台链接'];
        }
        
        // 3. 多次尝试获取内容
        for ($i = 0; $i < $retryCount; $i++) {
            if ($i > 0) {
                usleep(500000); // 等待0.5秒后重试
            }
            
            $html = $this->getWebContent($filteredUrl);
            if (!$html) {
                continue;
            }
            
            // 尝试多种解析方法
            $imageUrl = $this->parseWithMultipleMethods($html);
            
            if ($imageUrl) {
                return [
                    'success' => true,
                    'message' => '解析成功',
                    'data' => ['url' => $imageUrl]
                ];
            }
        }
        
        return ['success' => false, 'message' => '多次尝试后仍未找到图片链接，请检查链接是否有效'];
    }
    
    /**
     * 多种方法解析图片
     */
    private function parseWithMultipleMethods($html) {
        $methods = [
            'method1' => function($html) {
                // 方法1: 直接匹配 image_ori_raw
                if (preg_match_all('/image_ori_raw":{(.*?)}/', $html, $matches)) {
                    foreach ($matches[1] as $imageData) {
                        if (preg_match('/"url":"(.*?)"/', $imageData, $urlMatch)) {
                            $imageUrl = $this->cleanUrl($urlMatch[1]);
                            if (strpos($imageUrl, 'watermark') === false && strpos($imageUrl, 'byteimg.com') !== false) {
                                return $this->finalCleanUrl($imageUrl);
                            }
                        }
                    }
                }
                return null;
            },
            'method2' => function($html) {
                // 方法2: 先转码再匹配
                $decoded = $this->htmlEscape($html);
                $decoded = $this->urlDecode($decoded);
                $decoded = $this->decodeUnicode($decoded);
                $decoded = $this->removeBackslashEscape($decoded);
                
                if (preg_match_all('/image_ori_raw":{(.*?)}/', $decoded, $matches)) {
                    foreach ($matches[1] as $imageData) {
                        if (preg_match('/"url":"(.*?)"/', $imageData, $urlMatch)) {
                            $imageUrl = $this->cleanUrl($urlMatch[1]);
                            if (strpos($imageUrl, 'watermark') === false && strpos($imageUrl, 'byteimg.com') !== false) {
                                return $this->finalCleanUrl($imageUrl);
                            }
                        }
                    }
                }
                return null;
            },
            'method3' => function($html) {
                // 方法3: 匹配byteimg.com图片链接
                if (preg_match_all('/https?:\/\/[^\s"\']*byteimg\.com[^\s"\']*/i', $html, $matches)) {
                    $images = array_unique($matches[0]);
                    foreach ($images as $imageUrl) {
                        $imageUrl = $this->cleanUrl($imageUrl);
                        if (strpos($imageUrl, 'watermark') === false) {
                            return $this->finalCleanUrl($imageUrl);
                        }
                    }
                }
                return null;
            },
            'method4' => function($html) {
                // 方法4: 匹配JSON中的图片URL
                if (preg_match_all('/"url"\s*:\s*"([^"]+byteimg\.com[^"]+)"/i', $html, $matches)) {
                    foreach ($matches[1] as $imageUrl) {
                        $imageUrl = $this->cleanUrl($imageUrl);
                        if (strpos($imageUrl, 'watermark') === false) {
                            return $this->finalCleanUrl($imageUrl);
                        }
                    }
                }
                return null;
            },
            'method5' => function($html) {
                // 方法5: 匹配base64编码的图片数据
                if (preg_match_all('/"url":"(\\\u0026[^"]*byteimg\.com[^"]*)"/i', $html, $matches)) {
                    foreach ($matches[1] as $imageUrl) {
                        $imageUrl = str_replace('\u0026', '&', $imageUrl);
                        $imageUrl = $this->cleanUrl($imageUrl);
                        if (strpos($imageUrl, 'watermark') === false && strpos($imageUrl, 'byteimg.com') !== false) {
                            return $this->finalCleanUrl($imageUrl);
                        }
                    }
                }
                return null;
            }
        ];
        
        foreach ($methods as $method) {
            $result = $method($html);
            if ($result) {
                return $result;
            }
        }
        
        return null;
    }
    
    /**
     * 获取网页内容 - 增强版
     */
    private function getWebContent($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                'Cache-Control: no-cache',
                'Connection: keep-alive'
            ]
        ]);
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        return $content;
    }
    
    /**
     * 第一次转码：HTML转义处理
     */
    private function htmlEscape($content) {
        $replacements = [
            '&apos;' => "'",
            '&ensp;' => " ",
            '&emsp;' => " ",
            '&thinsp;' => " ",
            '&nbsp;' => " ",
            '&lt;' => "<",
            '&gt;' => ">",
            '&amp;' => "&",
            '&quot;' => '"',
            '&#39;' => "'",
            '&#47;' => "/",
            '#x27' => "\\",
            '&#x60' => "`",
            '&copy' => "©",
        ];
        
        foreach ($replacements as $k => $v) {
            $content = str_replace($k, $v, $content);
        }
        
        $content = preg_replace('/^"/', '', $content);
        $content = preg_replace('/"$/', '', $content);
        $content = str_replace("&amp;", "&", $content);
        
        return $content;
    }
    
    /**
     * 第二次转码：URL解码
     */
    private function urlDecode($s) {
        if (!$s) return null;
        
        $s = preg_replace_callback('/%([0-9A-Fa-f]{2})/', function($matches) {
            return chr(hexdec($matches[1]));
        }, $s);
        
        $s = str_replace('+', ' ', $s);
        
        return $s;
    }
    
    /**
     * 第三次转码：Unicode解码
     */
    private function decodeUnicode($s) {
        $s = preg_replace_callback('/\\\\u([0-9A-Fa-f]{4})/', function($matches) {
            $code = hexdec($matches[1]);
            return $this->unicodeToUtf8($code);
        }, $s);
        
        return $s;
    }
    
    /**
     * 第四次转码：移除反斜杠转义
     */
    private function removeBackslashEscape($s) {
        $s = str_replace('\\\\', '\\', $s);
        $s = str_replace('\\"', '"', $s);
        $s = str_replace('\\/', '/', $s);
        
        return $s;
    }
    
    /**
     * Unicode码点转UTF-8字符
     */
    private function unicodeToUtf8($code) {
        if ($code <= 0x7F) {
            return chr($code);
        } elseif ($code <= 0x7FF) {
            return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
        } elseif ($code <= 0xFFFF) {
            return chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
        } elseif ($code <= 0x10FFFF) {
            return chr(0xF0 | ($code >> 18)) . chr(0x80 | (($code >> 12) & 0x3F)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
        }
        return '?';
    }
    
    /**
     * 清理图片URL
     */
    private function cleanUrl($url) {
        $url = stripslashes($url);
        $url = trim($url, '"\'');
        $url = str_replace('\/', '/', $url);
        return $url;
    }
    
    /**
     * 最终清理URL
     */
    private function finalCleanUrl($url) {
        $url = preg_replace_callback('/\\\\u0026/', function() {
            return '&';
        }, $url);
        
        $url = str_replace('%3D', '=', $url);
        $url = str_replace('%3A', ':', $url);
        $url = str_replace('%2F', '/', $url);
        $url = str_replace('%3F', '?', $url);
        $url = str_replace('\\', '', $url);
        
        // 移除URL中的多余参数
        if (($pos = strpos($url, '?')) !== false) {
            $baseUrl = substr($url, 0, $pos);
            parse_str(substr($url, $pos + 1), $params);
            // 保留必要的参数，移除水印相关参数
            unset($params['watermark']);
            $newParams = http_build_query($params);
            $url = $baseUrl . ($newParams ? '?' . $newParams : '');
        }
        
        return $url;
    }
    
    /**
     * 提取URL
     */
    private function extractURL($str) {
        $pattern = '/https?:\/\/[-A-Za-z0-9+&@#\/%?=~_|!:,.;]+[-A-Za-z0-9+&@#\/%=~_|]/';
        if (preg_match($pattern, $str, $matches)) {
            return $matches[0];
        }
        return null;
    }
    
    /**
     * 下载图片 - 增强版
     */
    public function downloadImage($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_REFERER => 'https://www.doubao.com/',
            CURLOPT_HTTPHEADER => [
                'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9'
            ]
        ]);
        
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        
        if ($imageData && $httpCode === 200) {
            // 检测图片格式
            $extension = $this->getImageExtension($imageData, $contentType);
            return [
                'data' => $imageData,
                'extension' => $extension,
                'size' => strlen($imageData)
            ];
        }
        
        return null;
    }
    
    /**
     * 获取图片扩展名
     */
    private function getImageExtension($imageData, $contentType) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_buffer($finfo, $imageData);
        finfo_close($finfo);
        
        if (!$mimeType || $mimeType === 'application/octet-stream') {
            $mimeType = $contentType;
        }
        
        $extMap = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp'
        ];
        
        foreach ($extMap as $mime => $ext) {
            if (strpos($mimeType, $mime) !== false) {
                return $ext;
            }
        }
        
        return 'jpg';
    }
}

// 处理请求
$parser = new DoubaoImageParser();
$url = isset($_REQUEST['url']) ? $_REQUEST['url'] : null;

if (!$url) {
    echo json_encode([
        'success' => false,
        'message' => '请提供url参数'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// 如果请求下载
if (isset($_REQUEST['download']) && $_REQUEST['download'] == 1) {
    $result = $parser->parse($url);
    if ($result['success']) {
        $imageData = $parser->downloadImage($result['data']['url']);
        if ($imageData) {
            $filename = 'doubao_image_' . date('Ymd_His') . '.' . $imageData['extension'];
            header('Content-Type: image/' . $imageData['extension']);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . $imageData['size']);
            header('Cache-Control: no-cache, must-revalidate');
            echo $imageData['data'];
            exit();
        }
    }
    echo json_encode(['success' => false, 'message' => '下载失败，请重试'], JSON_UNESCAPED_UNICODE);
} else {
    // 正常解析
    $result = $parser->parse($url);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}
?>