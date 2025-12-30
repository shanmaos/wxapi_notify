<?php
/**
 * 域名监控系统 - 配置管理API
 * 处理系统配置的增删改查操作
 */

// 包含配置文件
require_once __DIR__ . '/../config.php';

// 数据库连接
function getDbConnection() {
    try {
        mysqli_report(MYSQLI_REPORT_ERROR);
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

// 获取所有配置
function actionList() {
    $conn = getDbConnection();
    
    $sql = "SELECT * FROM config LIMIT 1";
    $result = $conn->query($sql);
    
    if ($result->num_rows === 0) {
        // 如果没有配置，返回默认值
        $conn->close();
        jsonResponse(true, getDefaultConfig(), '获取成功');
    }
    
    $config = $result->fetch_assoc();
    $result->free();
    $conn->close();
    
    // 解析通知类型JSON
    if (isset($config['notify_types'])) {
        $config['notify_types'] = json_decode($config['notify_types'], true) ?: [];
    }
    
    jsonResponse(true, $config, '获取成功');
}

// 获取默认配置
function getDefaultConfig() {
    return [
        'id' => 0,
        'request_interval' => 3,
        'timeout' => 10,
        'retry_count' => 3,
        'auto_check' => 1,
        'notify_types' => [2],
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'notify_url' => '',
        'global_notify_url' => '',
        'created_at' => null,
        'update_time' => null
    ];
}

// 获取单个配置项
function actionGet() {
    $key = isset($_GET['key']) ? $_GET['key'] : '';
    
    if (empty($key)) {
        jsonResponse(false, null, '请指定配置键名');
    }
    
    $conn = getDbConnection();
    $key = $conn->real_escape_string($key);
    
    $sql = "SELECT * FROM config LIMIT 1";
    $result = $conn->query($sql);
    
    if ($result->num_rows === 0) {
        $conn->close();
        jsonResponse(false, null, '配置不存在');
    }
    
    $config = $result->fetch_assoc();
    $result->free();
    $conn->close();
    
    if (!isset($config[$key])) {
        jsonResponse(false, null, '配置项不存在: ' . $key);
    }
    
    jsonResponse(true, ['key' => $key, 'value' => $config[$key]], '获取成功');
}

// 更新配置
function actionUpdate() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data)) {
        jsonResponse(false, null, '没有要更新的数据');
    }
    
    $conn = getDbConnection();
    
    // 检查是否存在配置
    $checkSql = "SELECT id FROM config LIMIT 1";
    $checkResult = $conn->query($checkSql);
    
    if ($checkResult === false) {
        $error = $conn->error;
        $conn->close();
        jsonResponse(false, null, '查询配置失败: ' . $error);
    }
    
    $hasConfig = $checkResult->num_rows > 0;
    $checkResult->free();
    
    $now = date('Y-m-d H:i:s');
    
    if ($hasConfig) {
        // 更新现有配置
        $updates = [];
        $types = '';
        $values = [];
        
        // 字段映射：前端字段名 => 数据库字段名
        $fieldMapping = [
            'request_interval' => 'request_interval',
            'notify_types' => 'notify_types',
            'api_url' => 'api_url',
            'notify_api_url' => 'notify_api_url'
        ];
        
        foreach ($data as $key => $value) {
            if (isset($fieldMapping[$key])) {
                $dbField = $fieldMapping[$key];
                if ($key == 'notify_types' && is_array($value)) {
                    $value = json_encode($value);
                }
                $updates[] = "`$dbField` = ?";
                $types .= 's';
                $values[] = $value;
            }
        }
        
        if (count($updates) === 0) {
            $conn->close();
            jsonResponse(false, null, '没有有效的配置字段');
        }
        
        $updates[] = "update_time = ?";
        $types .= 's';
        $values[] = $now;
        
        $sql = "UPDATE config SET " . implode(', ', $updates) . " LIMIT 1";
        
        // 调试：记录SQL语句
        error_log("SQL: " . $sql);
        error_log("Values: " . print_r($values, true));
        
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            $conn->close();
            jsonResponse(false, null, 'SQL准备失败: ' . $conn->error);
        }
        
        if (!$stmt->bind_param($types, ...$values)) {
            $stmt->close();
            $conn->close();
            jsonResponse(false, null, '参数绑定失败: ' . $stmt->error);
        }
        
        if (!$stmt->execute()) {
            $stmt->close();
            $conn->close();
            jsonResponse(false, null, '执行失败: ' . $stmt->error);
        }
        
        $stmt->close();
        $conn->close();
        jsonResponse(true, null, '配置更新成功');
    } else {
        // 创建新配置
        $fields = [];
        $types = '';
        $values = [];
        
        // 字段映射：前端字段名 => 数据库字段名
        $fieldMapping = [
            'request_interval' => 'request_interval',
            'notify_types' => 'notify_types',
            'api_url' => 'api_url',
            'notify_api_url' => 'notify_api_url'
        ];
        
        foreach ($data as $key => $value) {
            if (isset($fieldMapping[$key])) {
                $dbField = $fieldMapping[$key];
                $fields[] = $dbField;
                $types .= 's';
                $values[] = ($key == 'notify_types' && is_array($value)) 
                    ? json_encode($value) 
                    : $value;
            }
        }
        
        if (count($fields) === 0) {
            $conn->close();
            jsonResponse(false, null, '没有有效的配置字段');
        }
        
        $fields[] = 'create_time';
        $fields[] = 'update_time';
        $types .= 'ss';
        $values[] = $now;
        $values[] = $now;
        
        $sql = "INSERT INTO config (" . implode(', ', $fields) . ") VALUES (" . implode(', ', array_fill(0, count($fields), '?')) . ")";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            $conn->close();
            jsonResponse(false, null, 'SQL准备失败: ' . $conn->error);
        }
        
        $stmt->bind_param($types, ...$values);
        
        if ($stmt->execute()) {
            $newId = $conn->insert_id;
            $stmt->close();
            $conn->close();
            jsonResponse(true, ['id' => $newId], '配置创建成功');
        } else {
            $stmt->close();
            $conn->close();
            jsonResponse(false, null, '创建失败: ' . $conn->error);
        }
    }
}

// 导入配置（从外部接口获取）
function actionImportConfig() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data) || !isset($data['api_key'])) {
        jsonResponse(false, null, '缺少api_key参数');
    }
    
    $apiKey = $data['api_key'];
    $requestInterval = isset($data['request_interval']) ? intval($data['request_interval']) : 3;
    $apiUrl = isset($data['api_url']) ? trim($data['api_url']) : '';
    $notifyApiUrl = isset($data['notify_api_url']) ? trim($data['notify_api_url']) : '';
    $fapi = isset($data['fapi']) ? intval($data['fapi']) : 0;
    $notifyTypes = isset($data['notify_types']) ? trim($data['notify_types']) : '2';
    
    $conn = getDbConnection();
    
    // 检查是否存在配置
    $checkSql = "SELECT id FROM config LIMIT 1";
    $checkResult = $conn->query($checkSql);
    
    if ($checkResult === false) {
        $error = $conn->error;
        $conn->close();
        jsonResponse(false, null, '查询配置失败: ' . $error);
    }
    
    $hasConfig = $checkResult->num_rows > 0;
    $checkResult->free();
    
    $now = date('Y-m-d H:i:s');
    
    if ($hasConfig) {
        // 更新现有配置
        $sql = "UPDATE config SET api_key = ?, request_interval = ?, api_url = ?, notify_api_url = ?, fapi = ?, notify_types = ?, update_time = ? LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            $conn->close();
            jsonResponse(false, null, 'SQL准备失败: ' . $conn->error);
        }
        
        if (!$stmt->bind_param('sississ', $apiKey, $requestInterval, $apiUrl, $notifyApiUrl, $fapi, $notifyTypes, $now)) {
            $stmt->close();
            $conn->close();
            jsonResponse(false, null, '参数绑定失败: ' . $stmt->error);
        }
        
        if (!$stmt->execute()) {
            $stmt->close();
            $conn->close();
            jsonResponse(false, null, '执行失败: ' . $stmt->error);
        }
        
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        $conn->close();
        
        jsonResponse(true, ['affected_rows' => $affectedRows, 'fapi' => $fapi, 'notify_types' => $notifyTypes], '配置更新成功');
    } else {
        // 创建新配置
        $sql = "INSERT INTO config (api_key, request_interval, api_url, notify_api_url, fapi, notify_types, create_time, update_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            $conn->close();
            jsonResponse(false, null, 'SQL准备失败: ' . $conn->error);
        }
        
        if (!$stmt->bind_param('sississs', $apiKey, $requestInterval, $apiUrl, $notifyApiUrl, $fapi, $notifyTypes, $now, $now)) {
            $stmt->close();
            $conn->close();
            jsonResponse(false, null, '参数绑定失败: ' . $stmt->error);
        }
        
        if (!$stmt->execute()) {
            $stmt->close();
            $conn->close();
            jsonResponse(false, null, '执行失败: ' . $stmt->error);
        }
        
        $insertId = $stmt->insert_id;
        $stmt->close();
        $conn->close();
        
        jsonResponse(true, ['id' => $insertId, 'fapi' => $fapi, 'notify_types' => $notifyTypes], '配置创建成功');
    }
}

// 批量更新配置
function actionBatchUpdate() {
    actionUpdate();
}

// 重置配置
function actionReset() {
    $conn = getDbConnection();
    
    $sql = "DELETE FROM config";
    if ($conn->query($sql)) {
        $conn->close();
        jsonResponse(true, null, '配置已重置');
    } else {
        $conn->close();
        jsonResponse(false, null, '重置失败: ' . $conn->error);
    }
}

// 测试通知URL
function actionTestNotify() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['url']) || empty($data['url'])) {
        jsonResponse(false, null, '请输入通知URL');
    }
    
    $url = $data['url'];
    $testData = json_encode([
        'type' => 'test',
        'message' => '这是一条测试通知',
        'time' => date('Y-m-d H:i:s')
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $testData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        jsonResponse(false, null, '请求失败: ' . $error);
    }
    
    if ($httpCode >= 200 && $httpCode < 300) {
        jsonResponse(true, ['response' => $response], '通知测试成功');
    } else {
        jsonResponse(false, null, '通知测试失败，HTTP状态码: ' . $httpCode);
    }
}

// 获取配置组列表
function actionGroups() {
    $conn = getDbConnection();
    
    $sql = "SELECT * FROM domain_groups WHERE status = 1 ORDER BY created_at DESC";
    $result = $conn->query($sql);
    
    $groups = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $groups[] = $row;
        }
        $result->free();
    }
    $conn->close();
    
    jsonResponse(true, $groups, '获取成功');
}

// 添加配置组
function actionAddGroup() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['name']) || empty($data['name'])) {
        jsonResponse(false, null, '请输入分组名称');
    }
    
    $conn = getDbConnection();
    
    $name = $conn->real_escape_string($data['name']);
    $notifyUrl = isset($data['notify_url']) ? $conn->real_escape_string($data['notify_url']) : '';
    $status = isset($data['status']) ? (int)$data['status'] : 1;
    $now = date('Y-m-d H:i:s');
    
    $sql = "INSERT INTO domain_groups (name, notify_url, status, create_time, update_time) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssiss', $name, $notifyUrl, $status, $now, $now);
    
    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        $stmt->close();
        $conn->close();
        jsonResponse(true, ['id' => $newId, 'name' => $data['name']], '分组添加成功');
    } else {
        $stmt->close();
        $conn->close();
        jsonResponse(false, null, '添加失败: ' . $conn->error);
    }
}

// 更新配置组
function actionUpdateGroup() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id']) || empty($data['id'])) {
        jsonResponse(false, null, '无效的分组ID');
    }
    
    $id = (int)$data['id'];
    $conn = getDbConnection();
    
    $updates = [];
    $types = '';
    $values = [];
    
    if (isset($data['name'])) {
        $updates[] = "name = ?";
        $types .= 's';
        $values[] = $conn->real_escape_string($data['name']);
    }
    
    if (isset($data['notify_url'])) {
        $updates[] = "notify_url = ?";
        $types .= 's';
        $values[] = $conn->real_escape_string($data['notify_url']);
    }
    
    if (isset($data['status'])) {
        $updates[] = "status = ?";
        $types .= 'i';
        $values[] = (int)$data['status'];
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
    
    $sql = "UPDATE domain_groups SET " . implode(', ', $updates) . " WHERE id = ?";
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

// 删除配置组
function actionDeleteGroup() {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($id <= 0) {
        jsonResponse(false, null, '无效的分组ID');
    }
    
    $conn = getDbConnection();
    
    // 检查是否有域名使用该分组
    $checkSql = "SELECT COUNT(*) as count FROM domainlist WHERE group_id = ?";
    $stmt = $conn->prepare($checkSql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $stmt->close();
    
    if ($count > 0) {
        $conn->close();
        jsonResponse(false, null, '该分组下有 ' . $count . ' 个域名，无法删除');
    }
    
    $sql = "DELETE FROM domain_groups WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    
    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        $stmt->close();
        $conn->close();
        
        if ($affected > 0) {
            jsonResponse(true, null, '删除成功');
        } else {
            jsonResponse(false, null, '分组不存在');
        }
    } else {
        $stmt->close();
        $conn->close();
        jsonResponse(false, null, '删除失败: ' . $conn->error);
    }
}

// 路由处理
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

switch ($action) {
    case 'list':
        actionList();
        break;
    
    case 'get':
        actionGet();
        break;
    
    case 'update':
        actionUpdate();
        break;
    
    case 'import_config':
        actionImportConfig();
        break;
    
    case 'batch_update':
        actionBatchUpdate();
        break;
    
    case 'reset':
        actionReset();
        break;
    
    case 'test_notify':
        actionTestNotify();
        break;
    
    case 'groups':
        actionGroups();
        break;
    
    case 'add_group':
        actionAddGroup();
        break;
    
    case 'update_group':
        actionUpdateGroup();
        break;
    
    case 'delete_group':
        actionDeleteGroup();
        break;
    
    default:
        jsonResponse(false, null, '未知操作: ' . $action);
}
