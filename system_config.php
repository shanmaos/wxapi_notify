<?php
/**
 * 域名监控系统 - 系统配置页面
 * 设置config表数据和分组管理
 */

// 包含配置文件
require_once __DIR__ . '/config.php';

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
        die('数据库连接错误: ' . $e->getMessage());
    }
}

// 获取当前配置
function getCurrentConfig() {
    $conn = getDbConnection();
    $sql = "SELECT * FROM config LIMIT 1";
    $result = $conn->query($sql);
    
    $config = [];
    if ($result && $result->num_rows > 0) {
        $config = $result->fetch_assoc();
        $result->free();
    }
    $conn->close();
    
    // 解析通知类型
    if (isset($config['notify_types'])) {
        $notifyTypesValue = $config['notify_types'];
        // 尝试JSON解析
        $decoded = json_decode($notifyTypesValue, true);
        if (is_array($decoded)) {
            $config['notify_types'] = $decoded;
        } else {
            // 如果是逗号分隔的字符串，解析为数组
            $config['notify_types'] = array_map('intval', array_filter(array_map('trim', explode(',', $notifyTypesValue))));
        }
    } else {
        $config['notify_types'] = [];
    }
    
    return $config;
}

// 获取分组列表
function getGroupList() {
    $conn = getDbConnection();
    $sql = "SELECT * FROM domain_groups ORDER BY create_time DESC";
    $result = $conn->query($sql);
    
    $groups = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $groups[] = $row;
        }
        $result->free();
    }
    $conn->close();
    
    return $groups;
}

// 格式化时间（PHP版本）
function formatTime($timeStr) {
    if (empty($timeStr) || $timeStr === null) {
        return '-';
    }
    return date('Y-m-d H:i:s', strtotime($timeStr));
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

$config = getCurrentConfig();
$groups = getGroupList();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统配置 - 域名监控系统</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="jquery-1.7.2.min.js"></script>
    <style>
        /* 美化复选框组 */
        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin: 15px 0;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            border-radius: 8px;
            background-color: #f5f7fa;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            font-size: 14px;
        }
        
        .checkbox-label:hover {
            background-color: #e4ecfa;
            border-color: #3498db;
        }
        
        .checkbox-label input[type="checkbox"] {
            margin-right: 8px;
            transform: scale(1.2);
        }
        
        /* 选中状态的样式 */
        .checkbox-label input[type="checkbox"]:checked {
            accent-color: #27ae60;
        }
        
        /* 选中时的文本样式 */
        .checkbox-label:has(input[type="checkbox"]:checked) {
            background-color: #e8f5e9;
            border-color: #27ae60;
            font-weight: bold;
            color: #27ae60;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- 头部导航 -->
        <div class="header">
            <div class="header-left">
                <a href="index.php" class="btn btn-secondary">
                    <span class="icon">←</span>
                    返回首页
                </a>
            </div>
            <h1 class="header-title">系统配置</h1>
            <div class="header-right">
                <button onclick="saveConfig()" class="btn btn-primary">
                    <span class="icon">💾</span>
                    保存配置
                </button>
            </div>
        </div>
        
        <!-- 配置表单 -->
        <div class="config-container">
            <!-- 监控配置 -->
            <div class="config-section">
                <div class="section-header">
                    <h2>监控配置</h2>
                </div>
                <div class="section-body">
                    <div class="form-group">
                        <label for="apiKey">接口Key</label>
                        <input type="text" id="apiKey" class="form-control" 
                               value="<?php echo htmlspecialchars($config['api_key'] ?? ''); ?>" 
                               placeholder="请输入接口Key">
                        <small>请输入接口Key以获取配置信息 <a href="http://wxapi.jnoo.com/Home/Sapi/addapi?t=229" target="_blank">点击获取Key</a></small>
                    </div>
                    <div id="configPreview" class="config-preview" style="display: none;">
                        <h4>获取到的配置信息：</h4>
                        <div id="configData"></div>
                    </div>
                </div>
            </div>
            
            <!-- 通知配置 -->
            <div class="config-section">
                <div class="section-header">
                    <h2>通知配置</h2>
                </div>
                <div class="section-body">
                    <div class="form-group">
                        <label>通知类型</label>
                        <?php 
                        // 确保notify_types是数组
                        $notifyTypes = [];
                        if (isset($config['notify_types'])) {
                            if (is_array($config['notify_types'])) {
                                $notifyTypes = $config['notify_types'];
                            } else {
                                // 如果是JSON字符串，尝试解析
                                $decoded = json_decode($config['notify_types'], true);
                                if (is_array($decoded)) {
                                    $notifyTypes = $decoded;
                                }
                            }
                        }
                        ?>
                        <div class="checkbox-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="notifyTypes" value="2" 
                                       <?php echo in_array(2, $notifyTypes) ? 'checked' : ''; ?>>
                                微信红色被封通知
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="notifyTypes" value="3" 
                                       <?php echo in_array(3, $notifyTypes) ? 'checked' : ''; ?>>
                                微信蓝色异常通知
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="notifyTypes" value="4" 
                                       <?php echo in_array(4, $notifyTypes) ? 'checked' : ''; ?>>
                                微信白色被封通知
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="notifyTypes" value="5" 
                                       <?php echo in_array(5, $notifyTypes) ? 'checked' : ''; ?>>
                                无法打开通知
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="notifyTypes" value="6" 
                                       <?php echo in_array(6, $notifyTypes) ? 'checked' : ''; ?>>
                                掉备案通知
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="notifyTypes" value="7" 
                                       <?php echo in_array(7, $notifyTypes) ? 'checked' : ''; ?>>
                                404通知
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="notifyTypes" value="8" 
                                       <?php echo in_array(8, $notifyTypes) ? 'checked' : ''; ?>>
                                4xx通知
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="notifyTypes" value="9" 
                                       <?php echo in_array(9, $notifyTypes) ? 'checked' : ''; ?>>
                                5xx通知
                            </label>
                        </div>
                        <small>选择需要发送通知的状态变化类型</small>
                    </div>
                    <div class="form-group">
                        <label for="notifyUrl">全局通知URL</label>
                        <input type="url" id="notifyUrl" class="form-control" 
                               value="<?php echo htmlspecialchars($config['notify_api_url'] ?? ''); ?>" 
                               placeholder="https://example.com/notify">
                        <small>状态变化时的通知接口地址（POST请求，参数msg=消息内容）</small>
                    </div>
                    <div class="form-group">
                        <button onclick="testNotify()" class="btn btn-secondary">
                            <span class="icon">📧</span>
                            测试通知
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- 分组管理 -->
            <div class="config-section">
                <div class="section-header">
                    <h2>分组管理</h2>
                    <button onclick="openGroupModal()" class="btn btn-primary btn-sm">
                        <span class="icon">+</span>
                        添加分组
                    </button>
                </div>
                <div class="section-body">
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>分组名称</th>
                                    <th>通知URL</th>
                                    <th>状态</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="groupListBody">
                                <?php if (empty($groups)): ?>
                                <tr>
                                    <td colspan="6" class="text-center">暂无分组</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($groups as $group): ?>
                                <tr>
                                    <td><?php echo $group['id']; ?></td>
                                    <td><?php echo htmlspecialchars($group['name']); ?></td>
                                    <td><?php echo htmlspecialchars($group['notify_url'] ?: '-'); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $group['status'] == 1 ? 'success' : 'default'; ?>">
                                            <?php echo $group['status'] == 1 ? '启用' : '禁用'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatTime($group['create_time']); ?></td>
                                    <td class="actions">
                                        <button onclick="openGroupModal(<?php echo $group['id']; ?>, '<?php echo htmlspecialchars($group['name']); ?>', '<?php echo htmlspecialchars($group['notify_url']); ?>', <?php echo $group['status']; ?>)" class="btn btn-xs btn-primary">编辑</button>
                                        <button onclick="deleteGroup(<?php echo $group['id']; ?>)" class="btn btn-xs btn-danger">删除</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 分组编辑模态框 -->
    <div id="groupModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="groupModalTitle">添加分组</h3>
                <span class="close" onclick="closeGroupModal()">&times;</span>
            </div>
            <div class="modal-body">
                <input type="hidden" id="groupId">
                <div class="form-group">
                    <label for="groupName">分组名称：</label>
                    <input type="text" id="groupName" class="form-control" placeholder="输入分组名称">
                </div>
                <div class="form-group">
                    <label for="groupNotifyUrl">通知URL：</label>
                    <input type="url" id="groupNotifyUrl" class="form-control" placeholder="https://example.com/notify">
                    <small>该分组域名状态变化时的通知地址，不设置则使用全局通知URL</small>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="groupStatus" checked>
                        启用分组
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeGroupModal()" class="btn btn-secondary">取消</button>
                <button onclick="saveGroup()" class="btn btn-primary">保存</button>
            </div>
        </div>
    </div>
    
    <!-- 通知消息 -->
    <div id="notification" class="notification"></div>
    
    <script src="assets/common.js"></script>
    <script>
        // 页面加载完成后初始化通知类型状态
        $(document).ready(function() {
            // 从PHP配置中获取fapi值和notify_types
            var fapiValue = <?php echo intval($config['fapi'] ?? 0); ?>;
            var notifyTypesArray = <?php 
                if (isset($config['notify_types']) && is_array($config['notify_types'])) {
                    echo json_encode(array_map('intval', $config['notify_types']));
                } else {
                    echo '[2]';
                }
            ?>;
            var isAdvanced = fapiValue === 4;
            
            // 红色通知始终可选
            $('input[name="notifyTypes"][value="2"]').prop('disabled', false);
            
            // 蓝色和白色通知：根据fapi值设置可勾选状态
            $('input[name="notifyTypes"][value="3"]').prop('disabled', !isAdvanced);
            $('input[name="notifyTypes"][value="4"]').prop('disabled', !isAdvanced);
            
            // 根据数据库中保存的notify_types设置勾选状态
            $('input[name="notifyTypes"]').each(function() {
                var value = parseInt($(this).val());
                $(this).prop('checked', notifyTypesArray.indexOf(value) !== -1);
            });
        });
        
        // 格式化时间
        function formatTime(timeStr) {
            if (!timeStr) return '-';
            var date = new Date(timeStr);
            return date.toLocaleString('zh-CN');
        }
        
        // 打开分组模态框
        function openGroupModal(id, name, notifyUrl, status) {
            if (id) {
                $('#groupModalTitle').text('编辑分组');
                $('#groupId').val(id);
                $('#groupName').val(name || '');
                $('#groupNotifyUrl').val(notifyUrl || '');
                $('#groupStatus').prop('checked', status == 1);
            } else {
                $('#groupModalTitle').text('添加分组');
                $('#groupId').val('');
                $('#groupName').val('');
                $('#groupNotifyUrl').val('');
                $('#groupStatus').prop('checked', true);
            }
            $('#groupModal').show();
        }
        
        // 关闭分组模态框
        function closeGroupModal() {
            $('#groupModal').hide();
        }
        
        // 保存分组
        function saveGroup() {
            var id = $('#groupId').val();
            var name = $('#groupName').val().trim();
            var notifyUrl = $('#groupNotifyUrl').val().trim();
            var status = $('#groupStatus').prop('checked') ? 1 : 0;
            
            if (!name) {
                showNotification('请输入分组名称', 'error');
                return;
            }
            
            var data = {
                name: name,
                notify_url: notifyUrl,
                status: status
            };
            
            var url = 'api/config_api.php?action=add_group';
            if (id) {
                data.id = id;
                url = 'api/config_api.php?action=update_group';
            }
            
            $.ajax({
                url: url,
                type: 'POST',
                data: JSON.stringify(data),
                dataType: 'json',
                contentType: 'application/json',
                success: function(response) {
                    if (response.success) {
                        showNotification(id ? '分组更新成功' : '分组添加成功', 'success');
                        closeGroupModal();
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        showNotification(response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    showNotification('操作失败: ' + error, 'error');
                }
            });
        }
        
        // 删除分组
        function deleteGroup(id) {
            if (!confirm('确定要删除该分组吗？删除后该分组下的域名将变为未分组状态。')) {
                return;
            }
            
            $.ajax({
                url: 'api/config_api.php?action=delete_group',
                type: 'GET',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showNotification('删除成功', 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        showNotification(response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    showNotification('删除失败: ' + error, 'error');
                }
            });
        }
        
        // 保存配置 - 从接口获取并保存
        function saveConfig() {
            var apiKey = $('#apiKey').val().trim();
            
            if (!apiKey) {
                showNotification('请输入接口Key', 'error');
                return;
            }
            
            showLoading();
            
            // 先调用外部接口获取配置
            var apiUrl = 'http://wxapi.jnoo.com/Home/Api/getconfig?key=' + encodeURIComponent(apiKey);
            
            $.ajax({
                url: apiUrl,
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response && response.status == 1 && response.data) {
                        // 接口返回成功，保存配置到数据库
                        var fapiValue = parseInt(response.data.fapi) || 0;
                        var isAdvanced = fapiValue === 4;
                        
                        // 获取用户勾选的通知类型
                        var selectedNotifyTypes = [];
                        $('input[name="notifyTypes"]:checked').each(function() {
                            selectedNotifyTypes.push(parseInt($(this).val()));
                        });
                        // 确保红色通知始终被选中
                        if (!selectedNotifyTypes.includes(2)) {
                            selectedNotifyTypes.push(2);
                        }
                        
                        var data = {
                            api_key: apiKey,
                            request_interval: parseInt(response.data.request_interval) || 3,
                            api_url: response.data.api_url || '',
                            notify_api_url: response.data.notify_api_url || '',
                            fapi: fapiValue,
                            notify_types: selectedNotifyTypes.join(',')
                        };
                        
                        // 保存到数据库
                        $.ajax({
                            url: 'api/config_api.php?action=import_config',
                            type: 'POST',
                            data: JSON.stringify(data),
                            dataType: 'json',
                            contentType: 'application/json',
                            success: function(saveResponse) {
                                hideLoading();
                                if (saveResponse.success) {
                                    // 显示获取到的配置信息
                                    $('#configPreview').show();
                                    var notifyTypesText = '红色';
                                    if (isAdvanced) {
                                        notifyTypesText += '、蓝色、白色';
                                    }
                                    $('#configData').html(
                                        '<p><strong>请求间隔：</strong>' + data.request_interval + ' 秒</p>' +
                                        '<p><strong>接口URL：</strong>' + data.api_url + '</p>' +
                                        '<p><strong>通知接口URL：</strong>' + data.notify_api_url + '</p>' +
                                        '<p><strong>接口类型：</strong>' + (isAdvanced ? '高级版' : '普通版') + '</p>' +
                                        '<p><strong>可用通知类型：</strong>' + notifyTypesText + '</p>'
                                    );
                                    
                                    // 根据fapi值设置通知类型的可勾选状态（不是自动勾选）
                                    // 红色通知始终可选
                                    $('input[name="notifyTypes"][value="2"]').prop('disabled', false);
                                    
                                    // 蓝色和白色通知：如果fapi=4则可以勾选，否则禁用
                                    $('input[name="notifyTypes"][value="3"]').prop('disabled', !isAdvanced);
                                    $('input[name="notifyTypes"][value="4"]').prop('disabled', !isAdvanced);
                                    
                                    if (isAdvanced) {
                                        showNotification('配置获取并保存成功（高级版，支持蓝、白通知）', 'success');
                                    } else {
                                        showNotification('配置获取并保存成功（普通版，仅支持红通知）', 'success');
                                    }
                                } else {
                                    showNotification(saveResponse.message, 'error');
                                }
                            },
                            error: function(xhr, status, error) {
                                hideLoading();
                                showNotification('保存配置失败: ' + error, 'error');
                            }
                        });
                    } else {
                        hideLoading();
                        showNotification('获取接口配置失败: ' + (response ? response.info : '接口返回数据格式错误'), 'error');
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    showNotification('调用接口失败: ' + error, 'error');
                }
            });
        }
        
        // 测试通知 - 直接请求notify_api_url，带msg参数
        function testNotify() {
            var url = $('#notifyUrl').val().trim();
            if (!url) {
                showNotification('请先填写全局通知URL', 'error');
                return;
            }
            
            // 构建请求URL，添加msg参数
            var timestamp = new Date().getTime();
            var msg = '测试消息' + timestamp;
            var requestUrl = url + (url.indexOf('?') >= 0 ? '&' : '?') + 'msg=' + encodeURIComponent(msg);
            
            showLoading();
            
            // 直接请求notify_api_url
            $.ajax({
                url: requestUrl,
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    showNotification('通知测试成功，msg=' + msg, 'success');
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    // 对于跨域请求，即使报错也可能成功了
                    if (xhr.status === 0 || xhr.status === 200 || xhr.readyState === 4) {
                        showNotification('通知请求已发送（可能因跨域显示错误），msg=' + msg, 'success');
                    } else {
                        showNotification('通知测试失败: ' + error + '，msg=' + msg, 'error');
                    }
                }
            });
        }
        
        // 点击模态框外部关闭
        $(window).on('click', function(e) {
            if (e.target.id === 'groupModal') {
                closeGroupModal();
            }
        });
    </script>
</body>
</html>