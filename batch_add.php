<?php
/**
 * 域名监控系统 - 批量添加域名页面
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

// 获取分组列表
function getGroupList() {
    $conn = getDbConnection();
    $sql = "SELECT id, name FROM domain_groups WHERE status = 1 ORDER BY sort_order, id";
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

// 获取分组列表（API调用）
if (isset($_GET['api']) && $_GET['api'] === 'groups') {
    header('Content-Type: application/json');
    $groups = getGroupList();
    echo json_encode(['success' => true, 'data' => $groups]);
    exit;
}

$groups = getGroupList();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>批量添加域名 - 域名监控系统</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="jquery-1.7.2.min.js"></script>
</head>
<body>
    <div class="container">
        <!-- 头部 -->
        <div class="header">
            <h1>➕ 批量添加域名</h1>
            <div class="header-buttons">
                <a href="index.php" class="btn btn-primary">← 返回首页</a>
            </div>
        </div>

        <!-- 添加表单 -->
        <div class="table-container">
            <div style="padding: 15px 20px; border-bottom: 1px solid #eee;">
                <h2 style="color: #2c3e50; margin: 0; font-size: 18px;">域名批量添加</h2>
            </div>
            
            <div style="padding: 20px;">
                <form id="batch-add-form">
                    <div class="form-group">
                        <label class="form-label">域名/网址列表 *</label>
                        <textarea id="domains-input" 
                                  name="domains" 
                                  class="form-control" 
                                  rows="15" 
                                  placeholder="请输入域名或网址，每行一个：&#10;&#10;example.com&#10;www.example.com&#10;https://example.com&#10;http://www.example.com"
                                  style="font-family: monospace; font-size: 14px; line-height: 1.4;"></textarea>
                        <small style="color: #666; font-size: 12px;">
                            支持域名格式：example.com, www.example.com | 支持URL格式：http://example.com, https://www.example.com
                        </small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">所属分组</label>
                        <select id="group-id" name="group_id" class="form-control">
                            <option value="0">不分组</option>
                            <?php foreach ($groups as $group): ?>
                                <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #666; font-size: 12px;">选择域名所属分组（可选）</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">初始状态</label>
                        <select id="domain-status" name="status" class="form-control">
                            <option value="1">正常</option>
                            <option value="2">红色被封</option>
                            <option value="3">蓝色异常</option>
                            <option value="4">白色被封</option>
                        </select>
                        <small style="color: #666; font-size: 12px;">设置域名的初始状态</small>
                    </div>

                    <div style="text-align: right; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                        <a href="index.php" class="btn btn-default" style="margin-right: 10px;">取消</a>
                        <button type="button" class="btn btn-info" onclick="previewDomains()" style="margin-right: 10px;">👁️ 预览</button>
                        <button type="submit" class="btn btn-success">➕ 批量添加</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 预览区域 -->
        <div id="preview-container" class="table-container" style="display: none; margin-top: 20px;">
            <div style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="color: #2c3e50; margin: 0; font-size: 16px;">域名预览</h3>
                <button class="btn btn-sm btn-danger" onclick="hidePreview()">✕ 关闭预览</button>
            </div>
            <div id="preview-content" style="padding: 20px;">
                <!-- 预览内容将在这里显示 -->
            </div>
        </div>

        <!-- 使用说明 -->
        <div class="table-container" style="margin-top: 20px;">
            <div style="padding: 15px 20px; border-bottom: 1px solid #eee;">
                <h3 style="color: #2c3e50; margin: 0; font-size: 16px;">使用说明</h3>
            </div>
            <div style="padding: 20px;">
                <div style="margin-bottom: 15px;">
                    <h4 style="color: #34495e; margin-bottom: 8px;">支持的格式</h4>
                    <ul style="color: #666; line-height: 1.6; margin: 0; padding-left: 20px;">
                        <li><strong>纯域名</strong>：example.com, www.example.com, sub.domain.com</li>
                        <li><strong>HTTP URL</strong>：http://example.com, http://www.example.com</li>
                        <li><strong>HTTPS URL</strong>：https://example.com, https://www.example.com</li>
                        <li><strong>带端口</strong>：example.com:8080, https://example.com:443</li>
                    </ul>
                </div>
                <div style="margin-bottom: 15px;">
                    <h4 style="color: #34495e; margin-bottom: 8px;">注意事项</h4>
                    <ul style="color: #666; line-height: 1.6; margin: 0; padding-left: 20px;">
                        <li>每行只能输入一个域名或URL</li>
                        <li>系统会自动去重，重复的域名不会被重复添加</li>
                        <li>不支持IP地址格式（如：192.168.1.1）</li>
                        <li>域名长度不能超过255个字符</li>
                    </ul>
                </div>
                <div>
                    <h4 style="color: #34495e; margin-bottom: 8px;">示例</h4>
                    <div style="background-color: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 12px; line-height: 1.4; color: #495057;">
                        example.com<br>
                        www.example.com<br>
                        https://api.example.com<br>
                        sub.domain.com<br>
                        http://test.com:8080
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 加载提示 -->
    <div id="loading" class="modal" style="display: none;">
        <div class="modal-content" style="text-align: center; padding: 40px;">
            <div class="spinner" style="margin: 0 auto 15px;"></div>
            <div>正在处理域名...</div>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        // 表单提交
        $('#batch-add-form').on('submit', function(e) {
            e.preventDefault();
            submitBatchAdd();
        });

        // 输入框变化时自动预览
        $('#domains-input').on('input', function() {
            var text = $(this).val().trim();
            if (text.length > 0) {
                clearTimeout(window.previewTimer);
                window.previewTimer = setTimeout(function() {
                    // 可以在这里添加实时预览功能
                }, 500);
            }
        });
    });

    // 预览域名
    function previewDomains() {
        var domainsText = $('#domains-input').val().trim();
        if (!domainsText) {
            showAlert('请先输入域名列表', 'error');
            return;
        }

        var parsed = parseDomains(domainsText);
        
        if (parsed.valid.length === 0) {
            showAlert('没有找到有效的域名格式', 'error');
            return;
        }

        var html = `
            <div style="margin-bottom: 15px;">
                <strong>解析结果：</strong>
                <span style="color: #27ae60;">有效域名: ${parsed.valid.length}</span> | 
                <span style="color: #e74c3c;">无效格式: ${parsed.invalid.length}</span>
            </div>
        `;

        if (parsed.valid.length > 0) {
            html += '<h4 style="margin-bottom: 10px; color: #2c3e50;">将添加的域名：</h4>';
            html += '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #eee; border-radius: 4px; padding: 10px; background-color: #f8f9fa;">';
            parsed.valid.forEach(function(domain, index) {
                html += `<div style="margin-bottom: 5px;"><span style="color: #666;">${index + 1}.</span> <code style="background-color: #e9ecef; padding: 2px 4px; border-radius: 3px;">${domain.original}</code></div>`;
            });
            html += '</div>';
        }

        if (parsed.invalid.length > 0) {
            html += '<h4 style="margin-bottom: 10px; margin-top: 15px; color: #e74c3c;">无效格式：</h4>';
            html += '<div style="max-height: 150px; overflow-y: auto; border: 1px solid #f8d7da; border-radius: 4px; padding: 10px; background-color: #f8d7da;">';
            parsed.invalid.forEach(function(item, index) {
                html += `<div style="margin-bottom: 5px;"><span style="color: #721c24;">${index + 1}.</span> <code style="background-color: #f5c6cb; padding: 2px 4px; border-radius: 3px;">${item}</code></div>`;
            });
            html += '</div>';
        }

        html += `
            <div style="margin-top: 20px; text-align: center;">
                <button class="btn btn-primary" onclick="hidePreview()">关闭预览</button>
            </div>
        `;

        $('#preview-content').html(html);
        $('#preview-container').show();
        $('html, body').animate({
            scrollTop: $('#preview-container').offset().top - 20
        }, 500);
    }

    // 隐藏预览
    function hidePreview() {
        $('#preview-container').hide();
    }

    // 解析域名
    function parseDomains(text) {
        var lines = text.split('\n').map(line => line.trim()).filter(line => line.length > 0);
        var valid = [];
        var invalid = [];

        lines.forEach(function(line) {
            var domain = extractDomain(line);
            if (domain && isValidDomain(domain.hostname)) {
                valid.push(domain);
            } else {
                invalid.push(line);
            }
        });

        // 去重（使用完整输入去重）
        var seen = new Set();
        valid = valid.filter(function(d) {
            if (seen.has(d.original)) return false;
            seen.add(d.original);
            return true;
        });

        return { valid: valid, invalid: invalid };
    }

    // 提取域名（返回原始输入和纯域名）
    function extractDomain(url) {
        try {
            var original = url.trim();
            if (original.length === 0) return null;

            var hostname = original.toLowerCase();

            // 如果是URL格式，提取主机名
            if (original.startsWith('http://') || original.startsWith('https://')) {
                var urlObj = new URL(original);
                hostname = urlObj.hostname.toLowerCase();
            }

            return {
                original: original.toLowerCase(),
                hostname: hostname
            };
        } catch (e) {
            return null;
        }
    }

    // 验证域名格式
    function isValidDomain(domain) {
        if (!domain || domain.length > 255) return false;
        
        // 基本域名格式验证
        var domainRegex = /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/;
        return domainRegex.test(domain);
    }

    // 提交批量添加
    function submitBatchAdd() {
        var domainsText = $('#domains-input').val().trim();
        if (!domainsText) {
            showAlert('请输入域名列表', 'error');
            return;
        }

        var parsed = parseDomains(domainsText);
        
        if (parsed.valid.length === 0) {
            showAlert('没有找到有效的域名格式', 'error');
            return;
        }

        // 确认对话框
        var confirmMessage = `确定要添加 ${parsed.valid.length} 个域名吗？\n`;
        if (parsed.invalid.length > 0) {
            confirmMessage += `\n将有 ${parsed.invalid.length} 个无效格式被跳过。`;
        }
        
        if (!confirm(confirmMessage)) {
            return;
        }

        // 提取完整URL用于保存
        var domainsForSave = parsed.valid.map(function(d) {
            return d.original;
        });

        var formData = {
            domains: domainsForSave,
            group_id: parseInt($('#group-id').val()) || 0,
            status: parseInt($('#domain-status').val()) || 0
        };

        // 显示加载
        $('#loading').show();

        // 提交数据
        $.ajax({
            url: 'api/domain_api.php?action=batch_add',
            type: 'POST',
            data: JSON.stringify(formData),
            contentType: 'application/json',
            success: function(response) {
                $('#loading').hide();
                if (response.success) {
                    var message = `成功添加 ${response.data.added} 个域名`;
                    if (response.data.duplicated > 0) {
                        message += `，跳过 ${response.data.duplicated} 个重复域名`;
                    }
                    if (response.data.failed > 0) {
                        message += `，${response.data.failed} 个添加失败`;
                    }
                    showAlert(message, 'success');
                    setTimeout(function() {
                        window.location.href = 'index.php';
                    }, 2000);
                } else {
                    showAlert('添加失败: ' + response.message, 'error');
                }
            },
            error: function() {
                $('#loading').hide();
                showAlert('网络请求失败，请重试', 'error');
            }
        });
    }

    // 显示通知
    function showAlert(message, type) {
        var alertClass = 'alert-' + (type === 'success' ? 'success' : 'error');
        var alertHtml = '<div class="alert ' + alertClass + '" style="position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; animation: slideIn 0.3s ease;">' + message + '</div>';
        
        $('body').append(alertHtml);
        setTimeout(function() {
            $('.alert').fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }

    // 添加滑入动画
    var style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    `;
    document.head.appendChild(style);
    </script>
</body>
</html>
