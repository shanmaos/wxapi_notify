<?php
/**
 * 域名监控系统 - 域名管理API
 * 处理域名的增删改查等操作
 */

// 包含配置文件
require_once __DIR__ . '/../config.php';

// 数据库连接
function getDbConnection() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        if ($conn->connect_error) {
            throw new Exception('数据库连接失败: ' . $conn->connect_error);
        }
        $conn->set_charset(DB_CHARSET);
        return $conn;
    } catch (Exception $e) {
        jsonResponse(false, null, '数据库连接错误: ' . $e->getMessage());
    }
}

// JSON响应
function jsonResponse($success, $data = null, $message = '') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ]);
    exit;
}

// 获取所有域名
function actionList() {
    $conn = getDbConnection();
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $groupId = isset($_GET['group_id']) ? $_GET['group_id'] : '';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? min(100, max(1, (int)$_GET['per_page'])) : 10;
    
    $where = "1=1";
    if (!empty($search)) {
        $search = $conn->real_escape_string($search);
        $where .= " AND d.domain LIKE '%$search%'";
    }
    if ($status !== '' && $status !== 'all') {
        $status = (int)$status;
        $where .= " AND d.status = $status";
    }
    if ($groupId !== '' && $groupId !== 'all') {
        $groupId = (int)$groupId;
        $where .= " AND d.group_id = $groupId";
    }
    
    // 获取总数
    $countSql = "SELECT COUNT(*) as total FROM domainlist d WHERE $where";
    $countResult = $conn->query($countSql);
    $total = $countResult->fetch_assoc()['total'];
    $countResult->free();
    
    // 获取数据，按id降序排列
    $offset = ($page - 1) * $perPage;
    $sql = "SELECT d.*, g.name as group_name 
            FROM domainlist d 
            LEFT JOIN domain_groups g ON d.group_id = g.id 
            WHERE $where 
            ORDER BY d.id DESC 
            LIMIT $offset, $perPage";
    $result = $conn->query($sql);
    
    $domains = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $domains[] = $row;
        }
        $result->free();
    }
    $conn->close();
    
    return [
        'domains' => $domains,
        'total' => $total,
        'page' => $page,
        'perPage' => $perPage,
        'totalPages' => ceil($total / $perPage)
    ];
}

// 获取统计信息
function actionStats() {
    $conn = getDbConnection();
    
    $stats = [
        'total' => 0,
        'normal' => 0,
        'red_blocked' => 0,
        'blue_blocked' => 0,
        'white_blocked' => 0
    ];
    
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as normal,
                SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as red_blocked,
                SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) as blue_blocked,
                SUM(CASE WHEN status = 4 THEN 1 ELSE 0 END) as white_blocked
            FROM domainlist";
    $result = $conn->query($sql);
    
    if ($result) {
        $row = $result->fetch_assoc();
        $stats['total'] = (int)$row['total'];
        $stats['normal'] = (int)$row['normal'];
        $stats['red_blocked'] = (int)$row['red_blocked'];
        $stats['blue_blocked'] = (int)$row['blue_blocked'];
        $stats['white_blocked'] = (int)$row['white_blocked'];
        $result->free();
    }
    
    $conn->close();
    return $stats;
}

// 添加单个域名
function actionAdd() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['domain']) || empty($data['domain'])) {
        jsonResponse(false, null, '请输入域名');
    }
    
    $domain = cleanDomain($data['domain']);
    if (!$domain) {
        jsonResponse(false, null, '无效的域名格式');
    }
    
    $conn = getDbConnection();
    
    // 检查是否已存在
    $checkSql = "SELECT id FROM domainlist WHERE domain = ?";
    $stmt = $conn->prepare($checkSql);
    $stmt->bind_param('s', $domain);
    $stmt->execute();
    $checkResult = $stmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        $stmt->close();
        $conn->close();
        jsonResponse(false, null, '域名已存在');
    }
    $stmt->close();
    
    // 插入新域名
    $status = isset($data['status']) ? (int)$data['status'] : 1;
    $groupId = isset($data['group_id']) ? (int)$data['group_id'] : 0;
    $now = date('Y-m-d H:i:s');
    
    $insertSql = "INSERT INTO domainlist (domain, status, group_id, create_time, update_time) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($insertSql);
    $stmt->bind_param('siiss', $domain, $status, $groupId, $now, $now);
    
    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        $stmt->close();
        $conn->close();
        jsonResponse(true, ['id' => $newId, 'domain' => $domain], '添加成功');
    } else {
        $stmt->close();
        $conn->close();
        jsonResponse(false, null, '添加失败: ' . $conn->error);
    }
}

// 批量添加域名
function actionBatchAdd() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['domains']) || !is_array($data['domains']) || count($data['domains']) === 0) {
        jsonResponse(false, null, '请输入域名列表');
    }
    
    $conn = getDbConnection();
    
    $added = 0;
    $duplicated = 0;
    $failed = 0;
    $status = isset($data['status']) ? (int)$data['status'] : 1;
    $groupId = isset($data['group_id']) ? (int)$data['group_id'] : 0;
    $now = date('Y-m-d H:i:s');
    
    $insertSql = "INSERT INTO domainlist (domain, status, group_id, create_time, update_time) VALUES (?, ?, ?, ?, ?)";
    $checkSql = "SELECT id FROM domainlist WHERE domain = ?";
    
    $stmt = $conn->prepare($insertSql);
    $checkStmt = $conn->prepare($checkSql);
    
    foreach ($data['domains'] as $domain) {
        // 验证域名格式并获取完整URL
        $domainInfo = validateAndFormatDomain($domain);
        if (!$domainInfo) {
            $failed++;
            continue;
        }
        
        $fullUrl = $domainInfo['fullUrl'];
        
        // 检查重复（使用完整URL检查）
        $checkStmt->bind_param('s', $fullUrl);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            $duplicated++;
            $checkResult->free();
            continue;
        }
        $checkResult->free();
        
        // 插入（保存完整URL）
        $stmt->bind_param('siiss', $fullUrl, $status, $groupId, $now, $now);
        
        if ($stmt->execute()) {
            $added++;
        } else {
            $failed++;
        }
    }
    
    $stmt->close();
    $checkStmt->close();
    $conn->close();
    
    jsonResponse(true, [
        'added' => $added,
        'duplicated' => $duplicated,
        'failed' => $failed
    ], "成功添加 $added 个域名");
}

// 更新域名
function actionUpdate() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id']) || empty($data['id'])) {
        jsonResponse(false, null, '无效的域名ID');
    }
    
    $id = (int)$data['id'];
    $conn = getDbConnection();
    
    // 构建更新语句
    $updates = [];
    $types = '';
    $values = [];
    
    if (isset($data['status'])) {
        $updates[] = "status = ?";
        $types .= 'i';
        $values[] = (int)$data['status'];
    }
    
    if (isset($data['group_id'])) {
        $updates[] = "group_id = ?";
        $types .= 'i';
        $values[] = (int)$data['group_id'];
    }
    
    if (isset($data['domain'])) {
        $domain = cleanDomain($data['domain']);
        if (!$domain) {
            $conn->close();
            jsonResponse(false, null, '无效的域名格式');
        }
        $updates[] = "domain = ?";
        $types .= 's';
        $values[] = $domain;
    }
    
    if (count($updates) === 0) {
        $conn->close();
        jsonResponse(false, null, '没有要更新的字段');
    }
    
    $updates[] = "update_time = ?";
    $types .= 's';
    $values[] = date('Y-m-d H:i:s');
    
    $values[] = $id;
    $types .= 'i';
    
    $sql = "UPDATE domainlist SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$values);
    
    if ($stmt->execute()) {
        $stmt->close();
        $conn->close();
        jsonResponse(true, null, '更新成功');
    } else {
        $stmt->close();
        $conn->close();
        jsonResponse(false, null, '更新失败: ' . $conn->error);
    }
}

// 更新域名状态
function actionUpdateStatus() {
    // 支持JSON格式和表单格式的输入
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // 如果JSON解析失败或为空，尝试从$_POST获取
    if (empty($data)) {
        $data = $_POST;
    }
    
    if (!isset($data['id'])) {
        jsonResponse(false, null, '无效的域名ID');
    }
    
    $id = (int)$data['id'];
    
    if ($id <= 0) {
        jsonResponse(false, null, '无效的域名ID');
    }
    
    if (!isset($data['status']) || !in_array((int)$data['status'], [1, 2, 3, 4])) {
        jsonResponse(false, null, '无效的状态值');
    }
    
    $status = (int)$data['status'];
    $conn = getDbConnection();
    
    // 检查域名是否存在
    $checkSql = "SELECT id FROM domainlist WHERE id = ?";
    $stmt = $conn->prepare($checkSql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->close();
        jsonResponse(false, null, '域名不存在');
    }
    $stmt->close();
    
    // 更新状态
    $updateSql = "UPDATE domainlist SET status = ?, update_time = ? WHERE id = ?";
    $stmt = $conn->prepare($updateSql);
    $now = date('Y-m-d H:i:s');
    $stmt->bind_param('isi', $status, $now, $id);
    
    if ($stmt->execute()) {
        $stmt->close();
        $conn->close();
        
        $statusText = getStatusText($status);
        jsonResponse(true, ['status' => $status, 'statusText' => $statusText], '状态更新成功');
    } else {
        $stmt->close();
        $conn->close();
        jsonResponse(false, null, '状态更新失败: ' . $conn->error);
    }
}

// 删除域名
function actionDelete() {
    // 支持JSON格式和表单格式的输入
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // 如果JSON解析失败或为空，尝试从$_POST获取
    if (empty($data)) {
        $data = $_POST;
    }
    
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    
    if ($id <= 0) {
        jsonResponse(false, null, '无效的域名ID');
    }
    
    $conn = getDbConnection();
    
    $sql = "DELETE FROM domainlist WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    
    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        $stmt->close();
        $conn->close();
        
        if ($affected > 0) {
            jsonResponse(true, null, '删除成功');
        } else {
            jsonResponse(false, null, '域名不存在');
        }
    } else {
        $stmt->close();
        $conn->close();
        jsonResponse(false, null, '删除失败: ' . $conn->error);
    }
}

// 检测域名状态
function actionCheck() {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($id <= 0) {
        jsonResponse(false, null, '无效的域名ID');
    }
    
    $conn = getDbConnection();
    
    // 获取域名信息
    $sql = "SELECT domain, status FROM domainlist WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->close();
        jsonResponse(false, null, '域名不存在');
    }
    
    $domain = $result->fetch_assoc();
    $stmt->close();
    
    // 获取系统配置
    $configSql = "SELECT * FROM config LIMIT 1";
    $configResult = $conn->query($configSql);
    $config = $configResult->fetch_assoc();
    $configResult->free();
    
    $timeout = isset($config['timeout']) ? (int)$config['timeout'] : 10;
    $userAgent = isset($config['user_agent']) ? $config['user_agent'] : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
    
    // 检测域名状态
    $newStatus = detectDomainStatus($domain['domain'], $timeout, $userAgent);
    
    // 更新状态
    $updateSql = "UPDATE domainlist SET status = ?, update_time = ? WHERE id = ?";
    $stmt = $conn->prepare($updateSql);
    $now = date('Y-m-d H:i:s');
    $stmt->bind_param('isi', $newStatus, $now, $id);
    $stmt->execute();
    $stmt->close();
    
    // 检查是否需要发送通知
    $notifyTypes = json_decode($config['notify_types'] ?? '[]', true) ?: [];
    if (in_array($newStatus, $notifyTypes) && $newStatus != $domain['status']) {
        sendNotification($domain['domain'], $newStatus, $config);
    }
    
    $conn->close();
    
    $statusText = getStatusText($newStatus);
    jsonResponse(true, ['status' => $newStatus, 'statusText' => $statusText], '检测完成');
}

// 批量删除域名
function actionBatchDelete() {
    // 支持JSON格式和表单格式的输入
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // 如果JSON解析失败或为空，尝试从$_POST获取
    if (empty($data)) {
        $data = $_POST;
    }
    
    if (!isset($data['ids']) || !is_array($data['ids']) || count($data['ids']) === 0) {
        jsonResponse(false, null, '请选择要删除的域名');
    }
    
    // 清理ID数组，确保都是有效的整数
    $ids = array_map(function($id) {
        return (int)$id;
    }, $data['ids']);
    $ids = array_filter($ids, function($id) {
        return $id > 0;
    });
    
    if (count($ids) === 0) {
        jsonResponse(false, null, '无效的域名ID');
    }
    
    $conn = getDbConnection();
    
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "DELETE FROM domainlist WHERE id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        $conn->close();
        jsonResponse(false, null, '预处理语句失败: ' . $conn->error);
    }
    
    // 绑定参数
    $types = str_repeat('i', count($ids));
    
    // 使用引用方式绑定参数
    $params = array_values($ids);
    $bindParams = [&$types];
    for ($i = 0; $i < count($params); $i++) {
        $bindParams[] = &$params[$i];
    }
    
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
    
    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        $stmt->close();
        $conn->close();
        jsonResponse(true, ['deleted' => $affected], '批量删除成功');
    } else {
        $error = $stmt->error;
        $stmt->close();
        $conn->close();
        jsonResponse(false, null, '批量删除失败: ' . $error);
    }
}

// 批量移动分组
function actionBatchMoveGroup() {
    // 支持JSON格式和表单格式的输入
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // 如果JSON解析失败或为空，尝试从$_POST获取
    if (empty($data)) {
        $data = $_POST;
    }
    
    if (!isset($data['ids']) || !is_array($data['ids']) || count($data['ids']) === 0) {
        jsonResponse(false, null, '请选择要移动的域名');
    }
    
    if (!isset($data['group_id'])) {
        jsonResponse(false, null, '请选择目标分组');
    }
    
    // 清理ID数组，确保都是有效的整数
    $ids = array_map(function($id) {
        return (int)$id;
    }, $data['ids']);
    $ids = array_filter($ids, function($id) {
        return $id > 0;
    });
    
    if (count($ids) === 0) {
        jsonResponse(false, null, '无效的域名ID');
    }
    
    $groupId = (int)$data['group_id'];
    
    $conn = getDbConnection();
    
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE domainlist SET group_id = ?, update_time = ? WHERE id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        $conn->close();
        jsonResponse(false, null, '预处理语句失败: ' . $conn->error);
    }
    
    $now = date('Y-m-d H:i:s');
    
    // 构建绑定参数类型: group_id是整数, update_time是字符串, ids是整数
    $types = 'i' . 's' . str_repeat('i', count($ids));
    
    // 使用引用方式绑定参数
    $params = array_merge([$groupId, $now], array_values($ids));
    $bindParams = [&$types];
    for ($i = 0; $i < count($params); $i++) {
        $bindParams[] = &$params[$i];
    }
    
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
    
    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        $stmt->close();
        $conn->close();
        jsonResponse(true, ['updated' => $affected], '批量移动分组成功');
    } else {
        $error = $stmt->error;
        $stmt->close();
        $conn->close();
        jsonResponse(false, null, '批量移动分组失败: ' . $error);
    }
}

// 清理域名格式（返回纯域名）
function cleanDomain($input) {
    $input = trim($input);
    if (empty($input)) return false;
    
    // 如果是URL格式，提取域名
    if (preg_match('/^https?:\/\//i', $input)) {
        $urlParts = parse_url($input);
        if (!isset($urlParts['host'])) return false;
        $input = $urlParts['host'];
    }
    
    // 转为小写
    $input = strtolower($input);
    
    // 移除端口
    if (strpos($input, ':') !== false) {
        $parts = explode(':', $input);
        $input = $parts[0];
    }
    
    // 验证域名格式
    if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $input)) {
        return false;
    }
    
    return $input;
}

// 验证域名格式并返回完整URL（用于批量添加）
function validateAndFormatDomain($input) {
    $input = trim($input);
    if (empty($input)) return false;
    
    $inputLower = strtolower($input);
    
    // 如果是URL格式
    if (preg_match('/^https?:\/\//i', $input)) {
        $urlParts = parse_url($input);
        if (!isset($urlParts['host'])) return false;
        
        $hostname = strtolower($urlParts['host']);
        
        // 验证纯域名格式
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $hostname)) {
            return false;
        }
        
        return [
            'fullUrl' => $inputLower,
            'hostname' => $hostname
        ];
    }
    
    // 如果是纯域名格式，保持原样
    // 验证纯域名格式
    if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $inputLower)) {
        return false;
    }
    
    return [
        'fullUrl' => $inputLower,
        'hostname' => $inputLower
    ];
}

// 检测域名状态
function detectDomainStatus($domain, $timeout, $userAgent) {
    $url = 'http://' . $domain;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // 正常访问
    if ($httpCode == 200) {
        return 1; // 正常
    }
    
    // 检测被封状态（基于响应内容判断）
    if (!empty($response)) {
        // 检查红色拦截特征
        if (stripos($response, '该网站因违规') !== false || 
            stripos($response, '停止解析') !== false ||
            stripos($response, '工信部核查') !== false ||
            stripos($response, '备案注销') !== false ||
            stripos($response, '已被取消备案') !== false) {
            return 2; // 红色被封
        }
        
        // 检查蓝色异常特征
        if (stripos($response, '无法访问') !== false || 
            stripos($response, '连接超时') !== false ||
            stripos($response, ' network is unreachable') !== false) {
            return 3; // 蓝色异常
        }
        
        // 检查白色被封特征
        if (stripos($response, '您的访问因') !== false ||
            stripos($response, '信息安全') !== false ||
            stripos($response, '存在违规') !== false) {
            return 4; // 白色被封
        }
    }
    
    // HTTP错误码判断
    if ($httpCode >= 400) {
        return 2; // 红色被封
    }
    
    // 无法连接
    if ($error || $httpCode == 0) {
        return 3; // 蓝色异常
    }
    
    return 1; // 正常
}

// 获取状态文本
function getStatusText($status) {
    $statusMap = [
        1 => '正常',
        2 => '红色被封',
        3 => '蓝色异常',
        4 => '白色被封'
    ];
    return $statusMap[$status] ?? '未知';
}

// 发送通知
function sendNotification($domain, $status, $config) {
    $notifyUrl = $config['global_notify_url'] ?? '';
    if (empty($notifyUrl)) return;
    
    $statusText = getStatusText($status);
    $postData = json_encode([
        'domain' => $domain,
        'status' => $status,
        'status_text' => $statusText,
        'time' => date('Y-m-d H:i:s')
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $notifyUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    
    curl_exec($ch);
    curl_close($ch);
}

// 路由处理
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

switch ($action) {
    case 'list':
        $data = actionList();
        jsonResponse(true, $data);
        break;
    
    case 'stats':
        $data = actionStats();
        jsonResponse(true, $data);
        break;
    
    case 'add':
        actionAdd();
        break;
    
    case 'batch_add':
        actionBatchAdd();
        break;
    
    case 'update':
        actionUpdate();
        break;
    
    case 'update_status':
        actionUpdateStatus();
        break;
    
    case 'delete':
        actionDelete();
        break;
    
    case 'batch_delete':
        actionBatchDelete();
        break;
    
    case 'batch_move_group':
        actionBatchMoveGroup();
        break;
    
    case 'check':
        actionCheck();
        break;
    
    default:
        jsonResponse(false, null, '未知操作: ' . $action);
}
