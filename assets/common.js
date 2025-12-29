// 域名监控系统 - 公共JavaScript文件

// 全局通知函数 (兼容旧版本调用方式)
function showNotification(message, type = 'success') {
    if (typeof Utils !== 'undefined' && Utils.showAlert) {
        Utils.showAlert(message, type);
    } else {
        // 如果Utils还未加载，使用原生alert
        console.log('通知: ' + message);
    }
}

// 全局配置
const Config = {
    apiBaseUrl: './api/',
    loadingText: '加载中...',
    successText: '操作成功',
    errorText: '操作失败'
};

// 全局加载状态变量
let globalLoadingOverlay = null;

// 创建全局加载遮罩层
function createLoadingOverlay() {
    if (globalLoadingOverlay) return globalLoadingOverlay;
    
    globalLoadingOverlay = document.createElement('div');
    globalLoadingOverlay.id = 'globalLoadingOverlay';
    globalLoadingOverlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 9999;
    `;
    
    globalLoadingOverlay.innerHTML = `
        <div style="
            background: white;
            padding: 20px 40px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        ">
            <div class="spinner" style="
                width: 40px;
                height: 40px;
                border: 4px #f3f3f3 solid;
                border-top: 4px #3498db solid;
                border-radius: 50%;
                animation: spin 1s infinite linear;
                margin: 0 auto 10px;
            "></div>
            <div>${Config.loadingText}</div>
        </div>
    `;
    
    // 添加旋转动画样式
    if (!document.getElementById('loadingAnimationStyle')) {
        const style = document.createElement('style');
        style.id = 'loadingAnimationStyle';
        style.textContent = `
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(style);
    }
    
    document.body.appendChild(globalLoadingOverlay);
    return globalLoadingOverlay;
}

// 全局显示加载状态
function showLoading() {
    const overlay = createLoadingOverlay();
    overlay.style.display = 'flex';
}

// 全局隐藏加载状态
function hideLoading() {
    if (globalLoadingOverlay) {
        globalLoadingOverlay.style.display = 'none';
    }
}

// 通用工具函数
const Utils = {
    // 显示加载状态
    showLoading: function(elementId) {
        const element = document.getElementById(elementId);
        if (element) {
            element.innerHTML = `
                <div class="loading">
                    <div class="spinner"></div>
                    <div>${Config.loadingText}</div>
                </div>
            `;
        }
    },

    // 隐藏加载状态
    hideLoading: function(elementId) {
        const element = document.getElementById(elementId);
        if (element) {
            element.innerHTML = '';
        }
    },

    // 显示通知消息
    showAlert: function(message, type = 'info') {
        const alertHtml = `
            <div class="alert alert-${type}" id="alert-${Date.now()}">
                ${message}
            </div>
        `;
        
        // 移除之前的通知
        const existingAlerts = document.querySelectorAll('.alert');
        existingAlerts.forEach(alert => alert.remove());
        
        // 添加新通知
        const container = document.querySelector('.container');
        if (container) {
            container.insertAdjacentHTML('afterbegin', alertHtml);
            
            // 3秒后自动消失
            setTimeout(() => {
                const alert = document.getElementById(`alert-${Date.now() - 1000}`);
                if (alert) {
                    alert.remove();
                }
            }, 3000);
        }
    },

    // 显示通知消息 (兼容旧版本)
    showNotification: function(message, type = 'success') {
        this.showAlert(message, type);
    },

    // 格式化时间
    formatDate: function(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleString('zh-CN');
    },

    // 获取状态显示文本
    getStatusText: function(status) {
        const statusMap = {
            1: '正常',
            2: '红色被封',
            3: '蓝色异常',
            4: '白色被封'
        };
        return statusMap[status] || '未知';
    },

    // 获取通知状态显示文本
    getNotifyStatusText: function(notifyStatus) {
        const statusMap = {
            0: '未通知',
            1: '已红色通知',
            2: '已蓝色通知',
            3: '已白色通知'
        };
        return statusMap[notifyStatus] || '未知';
    },

    // 获取状态样式类
    getStatusClass: function(status) {
        const classMap = {
            1: 'status-normal',
            2: 'status-red',
            3: 'status-blue',
            4: 'status-white'
        };
        return classMap[status] || 'status-normal';
    },

    // 获取通知状态样式类
    getNotifyStatusClass: function(notifyStatus) {
        return notifyStatus > 0 ? 'status-notified' : '';
    },

    // 验证域名
    validateDomain: function(domain) {
        const domainRegex = /^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/;
        return domainRegex.test(domain.trim());
    },

    // 验证URL
    validateUrl: function(url) {
        try {
            new URL(url);
            return true;
        } catch {
            return false;
        }
    }
};

// API调用封装
const Api = {
    // 通用请求方法
    request: function(url, options = {}) {
        const defaultOptions = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            }
        };

        const mergedOptions = { ...defaultOptions, ...options };

        return fetch(Config.apiBaseUrl + url, mergedOptions)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .catch(error => {
                console.error('API请求失败:', error);
                Utils.showAlert('网络请求失败: ' + error.message, 'error');
                throw error;
            });
    },

    // 获取域名列表
    getDomains: function() {
        return this.request('domain_api.php?action=list');
    },

    // 添加域名
    addDomain: function(domainData) {
        return this.request('domain_api.php?action=add', {
            method: 'POST',
            body: JSON.stringify(domainData)
        });
    },

    // 批量添加域名
    batchAddDomains: function(domains) {
        return this.request('domain_api.php?action=batch_add', {
            method: 'POST',
            body: JSON.stringify({ domains: domains })
        });
    },

    // 更新域名状态
    updateDomainStatus: function(id, status) {
        return this.request('domain_api.php?action=update_status', {
            method: 'POST',
            body: JSON.stringify({ id: id, status: status })
        });
    },

    // 删除域名
    deleteDomain: function(id) {
        return this.request('domain_api.php?action=delete', {
            method: 'POST',
            body: JSON.stringify({ id: id })
        });
    },

    // 获取系统配置
    getConfig: function() {
        return this.request('config_api.php?action=get');
    },

    // 保存系统配置
    saveConfig: function(configData) {
        return this.request('config_api.php?action=save', {
            method: 'POST',
            body: JSON.stringify(configData)
        });
    }
};

// 模态框管理
const Modal = {
    // 显示模态框
    show: function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
    },

    // 隐藏模态框
    hide: function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }
    },

    // 绑定关闭事件
    bindCloseEvents: function() {
        // 点击关闭按钮
        document.querySelectorAll('.close').forEach(btn => {
            btn.addEventListener('click', function() {
                const modal = this.closest('.modal');
                if (modal) {
                    Modal.hide(modal.id);
                }
            });
        });

        // 点击遮罩层关闭
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    Modal.hide(this.id);
                }
            });
        });

        // ESC键关闭
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal').forEach(modal => {
                    if (modal.style.display === 'block') {
                        Modal.hide(modal.id);
                    }
                });
            }
        });
    }
};

// DOM加载完成后初始化
document.addEventListener('DOMContentLoaded', function() {
    // 绑定模态框关闭事件
    Modal.bindCloseEvents();
    
    // 添加通用事件监听器
    console.log('域名监控系统初始化完成');
});