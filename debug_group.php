<?php
require_once 'config.php';

$conn = getDbConnection();

echo "=== 分组列表 ===\n";
$sql = "SELECT id, name, notify_url FROM domain_groups";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: {$row['id']}, 名称: {$row['name']}, notify_url: '{$row['notify_url']}'\n";
        echo "  notify_url是否为空: " . (empty($row['notify_url']) ? '是' : '否') . "\n";
        echo "  notify_url长度: " . strlen($row['notify_url']) . "\n";
    }
} else {
    echo "没有分组数据\n";
}

echo "\n=== 域名列表（含分组信息） ===\n";
$sql = "SELECT d.id, d.domain, d.group_id, d.status, g.name as group_name, g.notify_url as group_notify_url 
        FROM domainlist d 
        LEFT JOIN domain_groups g ON d.group_id = g.id 
        WHERE d.group_id > 0";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "域名: {$row['domain']}, 分组ID: {$row['group_id']}, 分组名称: {$row['group_name']}\n";
        echo "  分组notify_url: '{$row['group_notify_url']}'\n";
    }
} else {
    echo "没有已分组的域名\n";
}

$conn->close();
?>
