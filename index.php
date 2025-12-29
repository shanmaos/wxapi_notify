<?php
/**
 * 域名监控系统 - 首页
 * 显示所有域名列表
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

// 获取域名列表
function getDomainList($page = 1, $perPage = 10, $status = '', $groupId = '') {
    $conn = getDbConnection();
    
    $where = "1=1";
    if (!empty($status) && $status !== 'all') {
        $status = (int)$status;
        $where .= " AND d.status = $status";
    }
    if (!empty($groupId) && $groupId !== 'all') {
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

// 获取统计信息
function getStats() {
    $conn = getDbConnection();
    
    $stats = [
        'total' => 0,
        'normal' => 0,
        'blocked_red' => 0,
        'blocked_blue' => 0,
        'blocked_white' => 0
    ];
    
    // 总数
    $result = $conn->query("SELECT COUNT(*) as count FROM domainlist");
    if ($result) {
        $stats['total'] = $result->fetch_assoc()['count'];
        $result->free();
    }
    
    // 正常
    $result = $conn->query("SELECT COUNT(*) as count FROM domainlist WHERE status = 1");
    if ($result) {
        $stats['normal'] = $result->fetch_assoc()['count'];
        $result->free();
    }
    
    // 红色被封
    $result = $conn->query("SELECT COUNT(*) as count FROM domainlist WHERE status = 2");
    if ($result) {
        $stats['blocked_red'] = $result->fetch_assoc()['count'];
        $result->free();
    }
    
    // 蓝色被封
    $result = $conn->query("SELECT COUNT(*) as count FROM domainlist WHERE status = 3");
    if ($result) {
        $stats['blocked_blue'] = $result->fetch_assoc()['count'];
        $result->free();
    }
    
    // 白色被封
    $result = $conn->query("SELECT COUNT(*) as count FROM domainlist WHERE status = 4");
    if ($result) {
        $stats['blocked_white'] = $result->fetch_assoc()['count'];
        $result->free();
    }
    
    $conn->close();
    return $stats;
}

// 获取域名数据（API调用）
if (isset($_GET['api']) && $_GET['api'] === 'list') {
    header('Content-Type: application/json');
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 10;
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $groupId = isset($_GET['group_id']) ? $_GET['group_id'] : '';
    
    $domainsResult = getDomainList($page, $perPage, $status, $groupId);
    echo json_encode([
        'success' => true, 
        'data' => [
            'domains' => $domainsResult['domains'],
            'total' => $domainsResult['total'],
            'page' => $domainsResult['page'],
            'perPage' => $domainsResult['perPage'],
            'totalPages' => $domainsResult['totalPages']
        ]
    ]);
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'stats') {
    header('Content-Type: application/json');
    $stats = getStats();
    echo json_encode(['success' => true, 'data' => $stats]);
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'groups') {
    header('Content-Type: application/json');
    $groups = getGroupList();
    echo json_encode(['success' => true, 'data' => $groups]);
    exit;
}

// 获取初始数据
$domainsResult = getDomainList(1, 10, '', '');
$domains = $domainsResult['domains'];
$total = $domainsResult['total'];
$page = $domainsResult['page'];
$perPage = $domainsResult['perPage'];
$totalPages = $domainsResult['totalPages'];

$stats = getStats();
$groups = getGroupList();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>域名监控系统 - 首页</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="jquery-1.7.2.min.js"></script>
</head>
<body>
    <div class="container">
        <!-- 头部 -->
        <div class="header">
            <h1>📋 域名监控系统</h1>
            <div class="header-buttons">
                <a href="batch_add.php" class="btn btn-success">➕ 批量添加域名</a>
                <a href="system_config.php" class="btn btn-warning">⚙️ 系统设置</a>
            </div>
        </div>

        <!-- 统计信息 -->
        <div class="stats-container" id="stats-container" style="margin-bottom: 20px;">
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <div class="stat-card" style="background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); min-width: 120px; text-align: center;">
                    <div style="font-size: 28px; font-weight: bold; color: #3498db;"><?php echo $stats['total']; ?></div>
                    <div style="color: #666; font-size: 14px;">全部域名</div>
                </div>
                <div class="stat-card" style="background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); min-width: 120px; text-align: center;">
                    <div style="font-size: 28px; font-weight: bold; color: #27ae60;"><?php echo $stats['normal']; ?></div>
                    <div style="color: #666; font-size: 14px;">正常</div>
                </div>
                <div class="stat-card" style="background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); min-width: 120px; text-align: center;">
                    <div style="font-size: 28px; font-weight: bold; color: #e74c3c;"><?php echo $stats['blocked_red']; ?></div>
                    <div style="color: #666; font-size: 14px;">红色被封</div>
                </div>
                <div class="stat-card" style="background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); min-width: 120px; text-align: center;">
                    <div style="font-size: 28px; font-weight: bold; color: #3498db;"><?php echo $stats['blocked_blue']; ?></div>
                    <div style="color: #666; font-size: 14px;">蓝色拦截</div>
                </div>
                <div class="stat-card" style="background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); min-width: 120px; text-align: center;">
                    <div style="font-size: 28px; font-weight: bold; color: #95a5a6;"><?php echo $stats['blocked_white']; ?></div>
                    <div style="color: #666; font-size: 14px;">白色被封</div>
                </div>
            </div>
        </div>

        <!-- 域名列表 -->
        <div class="table-container">
            <div style="padding: 15px 20px; border-bottom: 1px solid #eee;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2 style="color: #2c3e50; margin: 0; font-size: 18px;">域名列表</h2>
                    <button class="btn btn-primary btn-sm" onclick="refreshList()">🔄 刷新列表</button>
                </div>
                
                <!-- 筛选和批量操作栏 -->
                <div id="filter-bar" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <select id="filter-status" class="form-control" style="width: 120px;" onchange="applyFilters()">
                        <option value="">全部状态</option>
                        <option value="1">正常</option>
                        <option value="2">红色被封</option>
                        <option value="3">蓝色异常</option>
                        <option value="4">白色被封</option>
                    </select>
                    
                    <select id="filter-group" class="form-control" style="width: 150px;" onchange="applyFilters()">
                        <option value="">全部分组</option>
                        <option value="0">未分组</option>
                        <?php foreach ($groups as $group): ?>
                        <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <span style="margin-left: auto; display: flex; gap: 10px;">
                        <button class="btn btn-sm btn-warning" onclick="batchMoveGroup()" id="btn-batch-group" style="display: none;">📁 批量移动分组</button>
                        <button class="btn btn-sm btn-danger" onclick="batchDelete()" id="btn-batch-delete" style="display: none;">🗑️ 批量删除</button>
                    </span>
                </div>
            </div>
            
            <div id="domain-list">
                <table class="table" id="domain-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="select-all" onclick="toggleSelectAll()"></th>
                            <th style="width: 60px;">ID</th>
                            <th style="max-width: 400px; min-width: 200px;">域名</th>
                            <th style="width: 100px;">状态</th>
                            <th style="width: 120px;">通知状态</th>
                            <th style="width: 100px;">分组</th>
                            <th style="width: 160px;">创建时间</th>
                            <th style="width: 160px;">更新时间</th>
                            <th style="width: 150px;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="domain-tbody">
                        <?php if (empty($domains)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 40px;">
                                    <div class="empty-state">
                                        <div class="empty-state-icon">📭</div>
                                        <div class="empty-state-text">暂无域名数据</div>
                                        <a href="batch_add.php" class="btn btn-success">➕ 批量添加域名</a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($domains as $domain): ?>
                                <tr data-id="<?php echo $domain['id']; ?>">
                                    <td ><input type="checkbox" class="domain-checkbox" value="<?php echo $domain['id']; ?>" onchange="updateBatchButtons()"></td>
                                    <td><?php echo $domain['id']; ?></td>
                                    <td style="max-width: 400px; min-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <a href="http://<?php echo htmlspecialchars($domain['domain']); ?>" target="_blank" style="color: #3498db; text-decoration: none;">
                                            <?php echo htmlspecialchars($domain['domain']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php 
                                            $statusClass = [
                                                1 => 'status-normal',
                                                2 => 'status-red',
                                                3 => 'status-blue',
                                                4 => 'status-white'
                                            ];
                                            echo isset($statusClass[$domain['status']]) ? $statusClass[$domain['status']] : 'status-normal';
                                        ?>">
                                            <?php 
                                                $statusText = [1 => '正常', 2 => '红色被封', 3 => '蓝色异常', 4 => '白色被封'];
                                                echo isset($statusText[$domain['status']]) ? $statusText[$domain['status']] : '正常';
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $domain['notify_status'] > 0 ? 'status-notified' : 'status-not-notified'; ?>">
                                            <?php 
                                                $notifyText = [0 => '未通知', 1 => '正常', 2 => '红色被封', 3 => '蓝色异常', 4 => '白色被封'];
                                                echo isset($notifyText[$domain['notify_status']]) ? $notifyText[$domain['notify_status']] : '未通知';
                                            ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($domain['group_name'] ?: '未分组'); ?></td>
                                    <td><?php echo $domain['create_time']; ?></td>
                                    <td><?php echo $domain['update_time']; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-warning" onclick="updateStatus(<?php echo $domain['id']; ?>)">修改状态</button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteDomain(<?php echo $domain['id']; ?>)">删除</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- 分页 -->
                <div id="pagination" style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-top: 1px solid #eee;">
                    <div style="color: #666; font-size: 14px;">
                        共 <span id="total-count"><?php echo $total ?? 0; ?></span> 条记录，每页 
                        <select id="per-page-select" onchange="changePerPage(this.value)" style="padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; margin: 0 5px;">
                            <option value="10" <?php echo ($perPage ?? 10) == 10 ? 'selected' : ''; ?>>10</option>
                            <option value="20" <?php echo ($perPage ?? 10) == 20 ? 'selected' : ''; ?>>20</option>
                            <option value="50" <?php echo ($perPage ?? 10) == 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo ($perPage ?? 10) == 100 ? 'selected' : ''; ?>>100</option>
                        </select> 条，
                        第 <span id="current-page"><?php echo $page ?? 1; ?></span>/<span id="total-pages"><?php echo $totalPages ?? 1; ?></span> 页
                    </div>
                    <div>
                        <button class="btn btn-sm btn-default" onclick="goToPage(1)" id="btn-first">首页</button>
                        <button class="btn btn-sm btn-default" onclick="goToPrevPage()" id="btn-prev" style="margin: 0 5px;">上一页</button>
                        <button class="btn btn-sm btn-default" onclick="goToNextPage()" id="btn-next" style="margin-right: 5px;">下一页</button>
                        <button class="btn btn-sm btn-default" onclick="goToLastPage()" id="btn-last">末页</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 修改状态模态框 -->
    <div id="status-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>修改域名状态</h2>
                <span class="close" onclick="closeModal('status-modal')">&times;</span>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit-domain-id">
                <div class="form-group">
                    <label class="form-label">选择状态：</label>
                    <select id="edit-domain-status" class="form-control">
                        <option value="1">正常</option>
                        <option value="2">红色被封</option>
                        <option value="3">蓝色异常</option>
                        <option value="4">白色被封</option>
                    </select>
                </div>
                <div style="text-align: right; margin-top: 20px;">
                    <button class="btn btn-default" onclick="closeModal('status-modal')" style="margin-right: 10px;">取消</button>
                    <button class="btn btn-primary" onclick="saveStatus()">保存</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 批量移动分组模态框 -->
    <div id="group-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>批量移动分组</h2>
                <span class="close" onclick="closeModal('group-modal')">&times;</span>
            </div>
            <div class="modal-body">
                <input type="hidden" id="batch-domain-ids">
                <div class="form-group">
                    <label class="form-label">选择目标分组：</label>
                    <select id="target-group" class="form-control">
                        <option value="0">未分组</option>
                        <?php foreach ($groups as $group): ?>
                        <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="text-align: right; margin-top: 20px;">
                    <button class="btn btn-default" onclick="closeModal('group-modal')" style="margin-right: 10px;">取消</button>
                    <button class="btn btn-primary" onclick="confirmBatchMoveGroup()">确定</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    let currentPage = 1;
    let currentPerPage = 10;
    let currentStatus = '';
    let currentGroupId = '';
    let allDomains = [];
    
    // 使用PHP初始数据初始化pagedData
    let pagedData = {
        domains: <?php echo json_encode($domains ?? []); ?>,
        total: <?php echo (int)($total ?? 0); ?>,
        page: <?php echo (int)($page ?? 1); ?>,
        totalPages: <?php echo (int)($totalPages ?? 1); ?>
    };
    
    // 页面加载完成后初始化分页显示
    $(document).ready(function() {
        renderDomainTable(pagedData.domains);
        updatePagination({
            total: pagedData.total,
            page: pagedData.page,
            totalPages: pagedData.totalPages
        });
    });
    
    // 刷新域名列表
    function refreshList() {
        $('#domain-tbody').html('<tr><td colspan="9" style="text-align: center; padding: 40px;"><div class="loading"><div class="spinner"></div><div>加载中...</div></div></td></tr>');
        
        const statusFilter = $('#filter-status').val();
        const groupFilter = $('#filter-group').val();
        
        $.get('api/domain_api.php?action=list', {
            status: statusFilter,
            group_id: groupFilter,
            page: currentPage,
            per_page: currentPerPage
        }, function(response) {
            if (response.success && response.data) {
                // 兼容两种数据格式：直接数组或包含domains的对象
                let domains = [];
                let total = 0;
                let page = 1;
                let totalPages = 1;
                
                if (Array.isArray(response.data)) {
                    // 格式1: response.data 直接是数组
                    domains = response.data;
                    total = domains.length;
                    totalPages = Math.ceil(total / currentPerPage) || 1;
                } else if (Array.isArray(response.data.domains)) {
                    // 格式2: response.data 是包含 domains 的对象
                    domains = response.data.domains;
                    total = response.data.total || domains.length;
                    page = response.data.page || 1;
                    totalPages = response.data.totalPages || Math.ceil(total / currentPerPage) || 1;
                }
                
                pagedData = { domains, total, page, totalPages };
                renderDomainTable(domains);
                updatePagination({ total, page, totalPages });
            } else {
                pagedData = null;
                renderDomainTable([]);
                updatePagination({ total: 0, page: 1, totalPages: 1 });
            }
        }).fail(function() {
            pagedData = null;
            $('#domain-tbody').html('<tr><td colspan="9" style="text-align: center; padding: 40px; color: #e74c3c;">加载失败，请刷新重试</td></tr>');
        });
    }

    // 更新分页信息
    function updatePagination(data) {
        if (!data) return;
        
        $('#total-count').text(data.total);
        $('#current-page').text(data.page);
        $('#total-pages').text(data.totalPages);
        
        // 更新按钮状态
        $('#btn-prev').prop('disabled', data.page <= 1);
        $('#btn-next').prop('disabled', data.page >= data.totalPages);
        $('#btn-first').prop('disabled', data.page <= 1);
        $('#btn-last').prop('disabled', data.page >= data.totalPages);
        
        // 分页栏始终显示
        $('#pagination').show();
    }

    // 跳转到指定页
    function goToPage(page) {
        if (page < 1) page = 1;
        if (pagedData && page > pagedData.totalPages) page = pagedData.totalPages;
        currentPage = page;
        refreshList();
    }

    // 上一页
    function goToPrevPage() {
        if (pagedData && currentPage > 1) {
            goToPage(currentPage - 1);
        }
    }

    // 下一页
    function goToNextPage() {
        if (pagedData && currentPage < pagedData.totalPages) {
            goToPage(currentPage + 1);
        }
    }

    // 末页
    function goToLastPage() {
        if (pagedData && currentPage < pagedData.totalPages) {
            goToPage(pagedData.totalPages);
        }
    }

    // 切换每页数量
    function changePerPage(perPage) {
        currentPerPage = parseInt(perPage);
        currentPage = 1;
        refreshList();
    }

    // 应用筛选
    function applyFilters() {
        currentPage = 1;
        refreshList();
    }

    // 渲染域名表格
    function renderDomainTable(domains) {
        let html = '';
        if (!domains || domains.length === 0) {
            html = '<tr><td colspan="9" style="text-align: center; padding: 40px; color: #666;">没有符合条件的域名</td></tr>';
        } else {
            domains.forEach(function(domain) {
                let statusClass = ['status-normal','status-normal', 'status-red', 'status-blue',  'status-white'][domain.status] || 'status-normal';
                let statusText = ['未知', '正常', '红色被封', '蓝色异常', '白色被封'][domain.status] || '正常';
                let notifyText = ['未通知', '正常', '红色被封', '蓝色异常', '白色被封'][domain.notify_status] || '未通知';
                
                html += '<tr data-id="' + domain.id + '">' +
                    '<td><input type="checkbox" class="domain-checkbox" value="' + domain.id + '" onchange="updateBatchButtons()"></td>' +
                    '<td>' + domain.id + '</td>' +
                    '<td><a href="http://' + domain.domain + '" target="_blank" style="color: #3498db; text-decoration: none;">' + domain.domain + '</a></td>' +
                    '<td><span class="status-badge ' + statusClass + '">' + statusText + '</span></td>' +
                    '<td><span class="status-badge ' + (domain.notify_status > 0 ? 'status-notified' : '') + '">' + notifyText + '</span></td>' +
                    '<td>' + (domain.group_name || '未分组') + '</td>' +
                    '<td>' + domain.create_time + '</td>' +
                    '<td>' + domain.update_time + '</td>' +
                    '<td>' +
                        '<button class="btn btn-sm btn-warning" onclick="updateStatus(' + domain.id + ')">修改状态</button> ' +
                        '<button class="btn btn-sm btn-danger" onclick="deleteDomain(' + domain.id + ')">删除</button>' +
                    '</td></tr>';
            });
        }
        $('#domain-tbody').html(html);
    }

    // 全选/取消全选
    function toggleSelectAll() {
        const selectAll = document.getElementById('select-all');
        $('.domain-checkbox').prop('checked', selectAll.checked);
        updateBatchButtons();
    }

    // 更新批量操作按钮状态
    function updateBatchButtons() {
        const selectedCount = $('.domain-checkbox:checked').length;
        if (selectedCount > 0) {
            $('#btn-batch-group, #btn-batch-delete').show();
        } else {
            $('#btn-batch-group, #btn-batch-delete').hide();
        }
    }

    // 批量移动分组
    function batchMoveGroup() {
        const selectedIds = [];
        $('.domain-checkbox:checked').each(function() {
            selectedIds.push($(this).val());
        });
        
        if (selectedIds.length === 0) {
            showAlert('请选择要移动的域名', 'error');
            return;
        }
        
        $('#batch-domain-ids').val(selectedIds.join(','));
        $('#group-modal').show();
    }

    // 确认批量移动分组
    function confirmBatchMoveGroup() {
        const ids = $('#batch-domain-ids').val().split(',');
        const targetGroup = $('#target-group').val();
        
        $.post('api/domain_api.php?action=batch_move_group', {
            ids: ids,
            group_id: targetGroup
        }, function(response) {
            if (response.success) {
                closeModal('group-modal');
                refreshList();
                showAlert('批量移动分组成功', 'success');
            } else {
                showAlert('批量移动分组失败: ' + response.message, 'error');
            }
        }).fail(function() {
            showAlert('网络请求失败', 'error');
        });
    }

    // 批量删除
    function batchDelete() {
        const selectedIds = [];
        $('.domain-checkbox:checked').each(function() {
            selectedIds.push($(this).val());
        });
        
        if (selectedIds.length === 0) {
            showAlert('请选择要删除的域名', 'error');
            return;
        }
        
        if (!confirm('确定要删除选中的 ' + selectedIds.length + ' 个域名吗？')) {
            return;
        }
        
        $.post('api/domain_api.php?action=batch_delete', {
            ids: selectedIds
        }, function(response) {
            if (response.success) {
                refreshList();
                showAlert('批量删除成功', 'success');
            } else {
                showAlert('批量删除失败: ' + response.message, 'error');
            }
        }).fail(function() {
            showAlert('网络请求失败', 'error');
        });
    }

    // 修改域名状态
    function updateStatus(id) {
        $('#edit-domain-id').val(id);
        $('#status-modal').show();
    }

    // 保存状态
    function saveStatus() {
        var id = $('#edit-domain-id').val();
        var status = $('#edit-domain-status').val();
        
        $.post('api/domain_api.php?action=update_status', {
            id: id,
            status: status
        }, function(response) {
            if (response.success) {
                closeModal('status-modal');
                refreshList();
                showAlert('状态修改成功', 'success');
            } else {
                showAlert('状态修改失败: ' + response.message, 'error');
            }
        }).fail(function() {
            showAlert('网络请求失败', 'error');
        });
    }

    // 删除域名
    function deleteDomain(id) {
        if (!confirm('确定要删除这个域名吗？')) {
            return;
        }
        
        $.post('api/domain_api.php?action=delete', {
            id: id
        }, function(response) {
            if (response.success) {
                refreshList();
                showAlert('删除成功', 'success');
            } else {
                showAlert('删除失败: ' + response.message, 'error');
            }
        }).fail(function() {
            showAlert('网络请求失败', 'error');
        });
    }

    // 关闭模态框
    function closeModal(modalId) {
        $('#' + modalId).hide();
    }

    // 显示通知
    function showAlert(message, type) {
        var alertClass = 'alert-' + (type === 'success' ? 'success' : 'error');
        var alertHtml = '<div class="alert ' + alertClass + '" style="position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px;">' + message + '</div>';
        
        $('body').append(alertHtml);
        setTimeout(function() {
            $('.alert').fadeOut(function() {
                $(this).remove();
            });
        }, 3000);
    }

    // ESC键关闭模态框
    $(document).keydown(function(e) {
        if (e.key === 'Escape') {
            $('.modal').hide();
        }
    });

    // 点击遮罩层关闭模态框
    $(document).mouseup(function(e) {
        var modal = $('.modal');
        if (modal.is(e.target) && modal.has(e.target).length === 0) {
            modal.hide();
        }
    });
    </script>
</body>
</html>