<?php
/**
 * 定时循环检测脚本
 * 功能：循环检测域名状态并更新
 * 使用方法：php check.php
 */

// 引入数据库配置
require_once 'config.php';

/**
 * 获取数据库连接
 */
function getDbConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($conn->connect_error) {
        die("数据库连接失败: " . $conn->connect_error);
    }
    $conn->set_charset(DB_CHARSET);
    return $conn;
}

/**
 * 创建检测日志表（如果不存在）
 */
function createCheckLogsTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS `check_logs` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '日志ID',
        `domain` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '检测的域名',
        `status` TINYINT UNSIGNED DEFAULT 0 COMMENT '检测状态',
        `http_code` INT DEFAULT 0 COMMENT 'HTTP响应码',
        `response` TEXT COMMENT '接口返回数据',
        `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        PRIMARY KEY (`id`),
        INDEX `idx_domain` (`domain`(191)),
        INDEX `idx_create_time` (`create_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='域名检测日志表'";
    
    return $conn->query($sql);
}

/**
 * 获取系统配置
 */
function getSystemConfig($conn) {
    $sql = "SELECT api_url, request_interval FROM config ORDER BY id DESC LIMIT 1";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return null;
}

/**
 * 获取需要检测的域名列表（按update_time升序排列）
 */
function getDomainsToCheck($conn) {
    $sql = "SELECT id, domain, status, group_id FROM domainlist WHERE status IN (0, 1, 3) ORDER BY update_time ASC";
    $result = $conn->query($sql);
    
    $domains = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $domains[] = $row;
        }
    }
    return $domains;
}

/**
 * 发起GET请求检测域名状态
 */
function checkDomainStatus($apiUrl, $domain) {
    $url = $apiUrl . "?domain=" . urlencode($domain);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return [
            'success' => false,
            'error' => $error,
            'status' => null
        ];
    }
    
    $data = json_decode($response, true);
    
    return [
        'success' => true,
        'http_code' => $httpCode,
        'data' => $data
    ];
}

/**
 * 更新域名状态和检测时间
 */
function updateDomainStatus($conn, $domainId, $status, $responseData) {
    $sql = "UPDATE domainlist SET status = ?, update_time = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $status, $domainId);
    
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * 记录检测日志
 */
function logCheckResult($conn, $domain, $status, $httpCode, $response) {
    // 检查日志表是否存在，不存在则创建
    $tableCheck = $conn->query("SHOW TABLES LIKE 'check_logs'");
    if ($tableCheck->num_rows == 0) {
        createCheckLogsTable($conn);
    }
    
    $sql = "INSERT INTO check_logs (domain, status, http_code, response, create_time) VALUES (?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    
    if ($stmt === false) {
        echo "    日志记录失败: " . $conn->error . "\n";
        return false;
    }
    
    $stmt->bind_param("siis", $domain, $status, $httpCode, $response);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * 获取域名所属分组的通知URL
 */
function getGroupNotifyUrl($conn, $groupId) {
    if ($groupId <= 0) {
        return null;
    }
    
    $sql = "SELECT notify_url FROM domain_groups WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifyUrl = null;
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $notifyUrl = $row['notify_url'];
    }
    
    $result->free();
    $stmt->close();
    
    return $notifyUrl;
}

/**
 * 获取分组名称
 */
function getGroupName($conn, $groupId) {
    if ($groupId <= 0) {
        return '';
    }
    
    $sql = "SELECT name FROM domain_groups WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $groupId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $groupName = '';
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $groupName = $row['name'];
    }
    
    $result->free();
    $stmt->close();
    
    return $groupName;
}

/**
 * 获取系统配置的通知API URL
 */
function getSystemNotifyUrl($conn) {
    $sql = "SELECT notify_api_url FROM config LIMIT 1";
    $result = $conn->query($sql);
    
    $notifyUrl = null;
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $notifyUrl = $row['notify_api_url'];
        $result->free();
    }
    
    return $notifyUrl;
}

/**
 * 发送通知
 */
function sendNotification($notifyUrl, $domain, $statusText, $groupName = '') {
    if (empty($notifyUrl)) {
        return false;
    }
    
    // 构造消息内容：如果有分组名称则包含在消息中
    if (!empty($groupName)) {
        $message = $groupName . " - " . $domain . " - " . $statusText;
    } else {
        $message = $domain . " - " . $statusText;
    }
    
    $url = $notifyUrl . "?msg=" . urlencode($message);
    //echo $url . "\n";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => $error];
    }
    
    return ['success' => true, 'http_code' => $httpCode, 'response' => $response];
}

/**
 * 更新域名的通知状态
 */
function updateNotifyStatus($conn, $domainId, $notifyStatus) {
    $sql = "UPDATE domainlist SET notify_status = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $notifyStatus, $domainId);
    
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * 获取域名的当前通知状态
 */
function getDomainNotifyStatus($conn, $domainId) {
    $sql = "SELECT notify_status FROM domainlist WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $domainId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifyStatus = 0;
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $notifyStatus = $row['notify_status'];
    }
    
    $result->free();
    $stmt->close();
    
    return $notifyStatus;
}

/**
 * 获取系统配置的notify_types
 */
function getNotifyTypes($conn) {
    $sql = "SELECT notify_types FROM config LIMIT 1";
    $result = $conn->query($sql);
    
    $notifyTypes = [];
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $notifyTypesStr = $row['notify_types'] ?? '';
        // notify_types 可能是JSON数组或逗号分隔的字符串
        if (empty($notifyTypesStr)) {
            $notifyTypes = [];
        } else {
            $decoded = json_decode($notifyTypesStr, true);
            if (is_array($decoded)) {
                $notifyTypes = $decoded;
            } else {
                // 尝试按逗号分隔
                $notifyTypes = array_filter(array_map('trim', explode(',', $notifyTypesStr)));
            }
        }
        $result->free();
    }
    
    return $notifyTypes;
}

/**
 * 处理域名状态变更通知
 */
function handleStatusNotification($conn, $domainId, $domainName, $groupId, $newStatus, $statusText) {
    // 只处理状态2、3、4的通知
    if (!in_array($newStatus, [2, 3, 4])) {
        return;
    }
    
    // 获取通知类型配置
    $notifyTypes = getNotifyTypes($conn);
    
    // 检查该状态是否在通知配置中
    if (!empty($notifyTypes) && !in_array($newStatus, $notifyTypes)) {
        echo "    状态({$statusText})未配置通知，跳过\n";
        return;
    }
    
    // 获取当前通知状态
    $currentNotifyStatus = getDomainNotifyStatus($conn, $domainId);
    
    // 如果通知状态已经是当前状态，说明已经通知过，不再重复通知
    if ($currentNotifyStatus == $newStatus) {
        echo "    状态未变更，跳过通知\n";
        return;
    }
    
    // 获取通知URL
    $notifyUrl = null;
    if ($groupId > 0) {
        // 已分组的域名，优先使用分组的notify_url
        $notifyUrl = getGroupNotifyUrl($conn, $groupId);
        // 如果分组有notify_url，则使用它；否则已分组域名不使用系统通知URL
        if (!empty($notifyUrl)) {
            // 分组有notify_url，使用它
        } else {
            $notifyUrl = getSystemNotifyUrl($conn);
            // 分组没有notify_url，不使用系统URL（已分组的域名不使用系统通知）
            //$notifyUrl = null;
        }
    } else {
        // 未分组的域名，使用系统通知URL
        $notifyUrl = getSystemNotifyUrl($conn);
    }
    
    if (empty($notifyUrl)) {
        echo "    未配置通知URL，跳过通知\n";
        return;
    }
    
    // 发送通知
    echo "    发送通知 ({$statusText})... ";
    // 如果域名属于某个分组，则带上分组名称
    $groupName = getGroupName($conn, $groupId);
    $result = sendNotification($notifyUrl, $domainName, $statusText, $groupName);
    
    if ($result['success']) {
        // 通知成功后更新notify_status
        updateNotifyStatus($conn, $domainId, $newStatus);
        echo "成功\n";
    } else {
        echo "失败: {$result['error']}\n";
    }
}

/**
 * 主程序
 */
function main() {
    echo "[" . date('Y-m-d H:i:s') . "] 域名检测程序启动\n";
    
    $conn = getDbConnection();
    
    // 获取系统配置
    $config = getSystemConfig($conn);
    if (!$config || empty($config['api_url'])) {
        die("错误: 未配置API地址\n");
    }
    
    $apiUrl = $config['api_url'];
    $requestInterval = isset($config['request_interval']) ? intval($config['request_interval']) : 3;
    
    echo "[" . date('Y-m-d H:i:s') . "] 配置信息: API地址={$apiUrl}, 检测间隔={$requestInterval}秒\n";
    
    $loopCount = 0;
    
    while (true) {
        $loopCount++;
        echo "\n[" . date('Y-m-d H:i:s') . "] 第{$loopCount}轮检测开始\n";
        
        // 获取待检测域名列表
        $domains = getDomainsToCheck($conn);
        
        if (empty($domains)) {
            echo "[" . date('Y-m-d H:i:s') . "] 没有需要检测的域名\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] 共有 " . count($domains) . " 个域名待检测\n";
            
            foreach ($domains as $domain) {
                $domainId = $domain['id'];
                $domainName = $domain['domain'];
                $groupId = $domain['group_id'] ?? 0;
                $oldStatus = $domain['status'];
                
                echo "  检测域名: {$domainName} (当前状态: {$oldStatus})... ";
                
                // 检测域名状态
                $result = checkDomainStatus($apiUrl, $domainName);
                
                if ($result['success']) {
                    $httpCode = $result['http_code'];
                    $data = $result['data'];
                    
                    // 解析返回的状态值
                    // 接口返回格式: {"status":2,"ret_code":0,"domain":"jnoo.com","info":"域名微信内可能被封","info2":"","endtime":"2026-08-22 14:57:43"}
                    $newStatus = isset($data['status']) ? intval($data['status']) : 0;
                    
                    // 状态值映射：1正常 2红色被封 3蓝色异常 4白色被封
                    $statusText = '';
                    switch ($newStatus) {
                        case 1:
                            $statusText = '正常';
                            break;
                        case 2:
                            $statusText = '红色被封';
                            break;
                        case 3:
                            $statusText = '蓝色异常';
                            break;
                        case 4:
                            $statusText = '白色被封';
                            break;
                        default:
                            $statusText = '未知状态(' . $newStatus . ')';
                            break;
                    }
                    
                    echo "HTTP {$httpCode}, 新状态: {$statusText}\n";
                    
                    // 更新数据库中的状态和检测时间
                    if ($newStatus > 0) {
                        $updateResult = updateDomainStatus($conn, $domainId, $newStatus, $data);
                        if ($updateResult) {
                            echo "    状态已更新\n";
                            // 处理状态变更通知
                            handleStatusNotification($conn, $domainId, $domainName, $groupId, $newStatus, $statusText);
                        } else {
                            echo "    状态更新失败\n";
                        }
                    }
                    
                    // 记录日志
                    $responseJson = json_encode($data, JSON_UNESCAPED_UNICODE);
                    logCheckResult($conn, $domainName, $newStatus, $httpCode, $responseJson);
                } else {
                    echo "请求失败: {$result['error']}\n";
                    
                    // 记录错误日志
                    $responseJson = json_encode(['error' => $result['error']], JSON_UNESCAPED_UNICODE);
                    logCheckResult($conn, $domainName, 0, 0, $responseJson);
                }
                
                // 每个域名检测之间增加间隔，避免请求过快
                if ($requestInterval > 0) {
                    sleep($requestInterval);
                }
            }
        }
        
        echo "[" . date('Y-m-d H:i:s') . "] 第{$loopCount}轮检测完成\n";
        
        // 每轮检测完成后休息3秒再开始下一轮
        sleep(3);
    }
    
    $conn->close();
}

// 如果是命令行运行
if (php_sapi_name() == "cli") {
    try {
        main();
    } catch (Exception $e) {
        echo "程序异常终止: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    echo "请在命令行中使用: php check.php";
}

?>
