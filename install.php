<?php
/**
 * 数据库导入脚本
 * 用于创建域名监控系统的数据库表结构
 */

require_once __DIR__ . '/config.php';

// 连接数据库
function getDbConnection() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD);
        if ($conn->connect_error) {
            throw new Exception('数据库连接失败: ' . $conn->connect_error);
        }
        $conn->set_charset(DB_CHARSET);
        return $conn;
    } catch (Exception $e) {
        die('连接错误: ' . $e->getMessage());
    }
}

// 创建数据库
function createDatabase($conn) {
    $sql = "CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci";
    if ($conn->query($sql) === TRUE) {
        echo "数据库 " . DB_NAME . " 创建成功或已存在\n";
        return true;
    } else {
        echo "创建数据库失败: " . $conn->error . "\n";
        return false;
    }
}

// 解析并执行SQL文件
function executeSqlFile($conn, $filePath) {
    if (!file_exists($filePath)) {
        echo "SQL文件不存在: " . $filePath . "\n";
        return false;
    }

    $sql = file_get_contents($filePath);
    if (empty($sql)) {
        echo "SQL文件为空\n";
        return false;
    }

    // 移除注释
    $sql = preg_replace('/--.*$/mu', '', $sql);
    
    // 按分号分割，但需要处理多行语句
    $statements = [];
    $current = '';
    $lines = explode("\n", $sql);
    
    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        if (empty($trimmedLine)) {
            continue;
        }
        
        $current .= ' ' . $line;
        
        // 检查是否语句结束（以分号结尾）
        if (strpos($trimmedLine, ';') !== false) {
            $current = trim($current);
            if (!empty($current) && substr($current, -1) == ';') {
                $statements[] = $current;
                $current = '';
            }
        }
    }
    
    // 如果还有未处理的语句
    if (!empty(trim($current))) {
        $statements[] = trim($current);
    }
    
    echo "解析到 " . count($statements) . " 条SQL语句\n";
    
    $success = true;
    $executed = 0;
    $errors = [];
    
    foreach ($statements as $index => $statement) {
        $statement = trim($statement);
        if (empty($statement) || strlen($statement) < 10) {
            continue;
        }
        
        // 跳过纯注释语句
        if (substr($statement, 0, 2) == '--') {
            continue;
        }
        
        if ($conn->query($statement) === TRUE) {
            $executed++;
            echo "  ✓ 执行成功: " . substr($statement, 0, 50) . "...\n";
        } else {
            $error = $conn->error;
            $errors[] = $error;
            echo "  ✗ 执行失败: " . $error . "\n";
            echo "    SQL: " . substr($statement, 0, 100) . "...\n";
            $success = false;
        }
    }
    
    echo "成功执行 " . $executed . " 条语句\n";
    
    if (!empty($errors)) {
        echo "错误数量: " . count($errors) . "\n";
    }
    
    return $success;
}

// 显示执行结果
function showResults($conn) {
    echo "\n========== 数据库结构导入完成 ==========\n\n";
    
    // 选择数据库
    $conn->select_db(DB_NAME);
    
    // 显示表列表
    $result = $conn->query("SHOW TABLES FROM `" . DB_NAME . "`");
    if ($result) {
        $tables = [];
        while ($row = $result->fetch_array()) {
            $tables[] = $row[0];
        }
        
        if (empty($tables)) {
            echo "  ⚠ 警告: 数据库中没有表!\n\n";
        } else {
            echo "已创建的表 (" . count($tables) . " 个):\n";
            foreach ($tables as $table) {
                echo "  ✓ " . $table . "\n";
                
                // 显示表字段
                $fieldResult = $conn->query("DESCRIBE `" . DB_NAME . "`.`" . $table . "`");
                if ($fieldResult) {
                    echo "    字段:\n";
                    while ($field = $fieldResult->fetch_assoc()) {
                        $fieldName = $field['Field'];
                        $fieldType = $field['Type'];
                        $fieldNull = $field['Null'];
                        $fieldKey = $field['Key'];
                        echo "      - {$fieldName} ({$fieldType}) {$fieldNull}\n";
                    }
                    echo "\n";
                }
            }
        }
        echo "\n";
    }
}

// 主程序
echo "==========================================\n";
echo "  域名监控系统 - 数据库导入工具\n";
echo "==========================================\n\n";

echo "数据库配置信息:\n";
echo "  主机: " . DB_HOST . "\n";
echo "  用户: " . DB_USER . "\n";
echo "  数据库: " . DB_NAME . "\n";
echo "  字符集: " . DB_CHARSET . "\n\n";

try {
    // 连接数据库服务器
    echo "步骤1: 连接数据库服务器...\n";
    $conn = getDbConnection();
    echo "  ✓ 连接成功\n\n";
    
    // 创建数据库
    echo "步骤2: 创建数据库...\n";
    if (createDatabase($conn)) {
        echo "  ✓ 数据库就绪\n\n";
    }
    
    // 选择数据库
    $conn->select_db(DB_NAME);
    
    // 执行SQL文件
    echo "步骤3: 创建数据表...\n";
    $sqlFile = __DIR__ . '/sql57.sql';
    if (file_exists($sqlFile)) {
        echo "SQL文件路径: " . $sqlFile . "\n";
        if (executeSqlFile($conn, $sqlFile)) {
            echo "\n  ✓ 所有表创建成功\n\n";
        } else {
            echo "\n  ⚠ 部分语句执行失败，请查看上方错误信息\n\n";
        }
    } else {
        echo "  ✗ SQL文件不存在: " . $sqlFile . "\n";
    }
    
    // 显示结果
    showResults($conn);
    
    echo "==========================================\n";
    echo "  导入完成!\n";
    echo "==========================================\n";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "\n错误: " . $e->getMessage() . "\n";
    exit(1);
}

