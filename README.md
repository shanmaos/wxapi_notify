# wxapi_notify

## 项目简介

wxapi_notify 是一个域名监控系统，用于监控域名的微信状态变化并发送通知。

## 安装说明

### 系统要求
- PHP 7.1+
- MySQL 5.6+
- Web服务器（Apache/Nginx）

### 安装步骤

1. **环境准备**
   - 系统安装好宝塔面板以及lnmp环境，php至少要7.1及以上
   - 将项目上传到服务器的指定目录，如 `/www/wwwroot/wxapi_notify`

2. **网站配置**
   - 在宝塔面板中，为该目录添加一个新的网站，域名可以自定义，如 `notify.yourdomain.com`
   - 将域名解析到服务器IP地址

3. **数据库导入**
   
   **方法1：使用PHP导入工具（推荐）**
   - 修改 `config.php` 中的数据库配置
   - 在浏览器中访问 `notify.yourdomain.com/install.php` 进行数据库导入
   - 或在命令行运行：`php install.php`
   
   **方法2：使用MySQL命令行导入**
   ```bash
   mysql -u root -p < database_schema.sql
   ```

4. **完成安装**
   - 安装完成后，删除 install.php 文件
   - 访问首页 notify.yourdomain.com
   - 配置定时任务：`nohup php /www/wwwroot/wxapi_notify/check.php > /dev/null 2>&1 &`

## 文件说明

- `config.php` - 数据库配置文件
- `database_schema.sql` - 数据库结构SQL文件
- `install.php` - 数据库导入工具
- `check.php` - 定时检查域名状态脚本
- `index.php` - 主页面
- `api/` - API接口目录
- `assets/` - 静态资源目录

## 通知类型说明

- `1` = 正常
- `2` = 红色通知（被封）
- `3` = 蓝色通知（异常）
- `4` = 白色通知（被封）

## 注意事项

- 确保数据库字符集为 utf8mb4
- 导入前请先修改 config.php 中的数据库配置
- 定期检查定时任务是否正常运行