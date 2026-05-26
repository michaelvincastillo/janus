# Janus (janus.php)

[![PHP Version](https://img.shields.io/badge/php-%3E%3D%205.5-8892BF.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

**Janus** is a lightweight, single-file PHP administration console, file manager, and terminal helper designed to run on resource-constrained servers and legacy hosting environments. Refactored to support legacy PHP environments down to **PHP 5.5**, it provides system administrators and developers with a powerful, zero-dependency toolbox in a single file.

---

## 🎨 Design & Performance Philosophy

- **Zero External Dependencies**: No heavy Monaco editors, jQuery, Bootstrap, or external fonts. Built entirely using raw HTML, vanilla CSS, and lightweight native elements.
- **Low-RAM Footprint**: Extremely light on server memory and client browser RAM.
- **Legacy Friendly**: Programmatically refactored to eliminate PHP 7+ syntax (e.g. replacing null coalescing `??` operators and `Throwable` catch blocks) for maximum compatibility.
- **Responsive Dark Console**: Visual design styled like a modern CLI control center.

---

## 🚀 Tab-by-Tab Feature Breakdown

### 1. 📁 Files Tab (Advanced File Manager)
The core of Janus is a powerful file browser designed with advanced server-level capabilities:
- **Bracketed Directory Lists**: Folders are cleanly formatted inside brackets (e.g. `[app]`, `[config]`) to match standard console file outputs.
- **Unix-style Colored Permissions**: Permissions are formatted as symbolic notations (e.g., `drwxr-xr-x`).
  - **Writable** items are rendered in **green** (`#10b981`).
  - **Read-only** items are rendered in a muted **grey** (`#9ca3af`).
- **Interactive Permissions (Chmod)**: Clicking the permissions column opens an inline form to dynamically execute a `chmod` command.
- **Interactive Timestamps (Touch)**: Clicking a file's modification time opens an inline date form to modify timestamps using PHP's native `touch()`.
- **Modification Time Preservation**: When editing text files, a checkbox allows you to save changes while preserving the file's original modification date.
- **Clean Download System (`D`)**: A dedicated download button uses custom buffer-clearing headers to stream payloads directly to the browser without file leakage.
- **Bulk Operations Toolbar**:
  - Master checkbox in the header to select/deselect all files instantly.
  - Dynamic bulk action bar displaying the selection count.
  - Perform bulk **Delete**, **Copy**, or **Cut** actions.
  - A JSON-encoded clipboard cookie stores copied paths, allowing you to navigate across folders and click **Paste (N items)** to paste them all at once.

### 2. 🖥️ Info Tab (System & Server Diagnostics)
Provides a comprehensive summary of the environment config:
- **Server address**: Lists host names, local domain names, and active network interface IPs (excluding loopbacks).
- **Server OS**: Full operating system and kernel specifications.
- **Server Software & PHP Details**: Shows active web server software, loaded database drivers, and security extensions (cURL, OpenSSL, PDO).
- **Process Owner Details**: Displays the server process UID/GID and username.
- **Disk Usage Bar**: Visual disk capacity indicator showing total, free, and used space percentages.
- **php.ini Limits**: Displays configured limits for `upload_max_filesize`, `post_max_size`, and `memory_limit`.
- **Disabled Functions**: Dynamically lists blocked PHP execution utilities parsed directly from the system configuration.

### 3. 📟 Terminal Tab (Multi-Method Web Terminal)
A flexible system command execution terminal featuring:
- **Multi-Method Dropdown**: Choose between `shell_exec`, `exec`, `system`, `passthru`, `proc_open`, and `popen` or let `Auto-detect` find the first working function.
- **Disabled Option Highlighting**: Methods disabled in `disable_functions` are dynamically greyed out in the select list.
- **Successful Status Feedback**: Prints the last successful command execution method (e.g. `Last executed via: proc_open`).


### 4. 📝 PHP Tab (Interactive PHP Sandbox)
A workspace to run PHP snippets directly on the server:
- Executes custom PHP blocks inside a sandboxed `try-catch` wrapper.
- Implements output buffering (`ob_start()`) to catch all `print`, `echo`, and error outputs.

### 5. 🗃️ SQL Tab (Built-in Database Client)
Allows you to manage local or remote databases:
- **Driver Support**: Connect using MySQL/MariaDB or SQLite via PDO.
- **Cookie-Based Connection Persistence**: Session data is obfuscated and saved as a Base64 cookie `fm_db_conn` to keep you logged in across folder navigation.
- **Table Sidebar**: Auto-lists all tables in the database. Clicking a table auto-queries it to show the first 30 rows.
- **Query Templates**: Clickable shortcuts to instantly write standard templates for `SELECT`, `INSERT`, `UPDATE`, `DELETE`, and table schema information.
- **Query Results & Timing**: Shows timing statistics, row counts, and query syntax error blocks.

### 6. 🔌 WP Tools Tab (WordPress Console)
Integrates directly with local WordPress installations:
- **Auto-Detection**: Scans current, parent, and grandparent directories for `wp-load.php` to connect automatically.
- **Path Override**: If placed outside a WordPress directory, click **Disconnect / Change Path** to explicitly connect to another WordPress directory on the server.
- **Admin Login Bypass**: Lists all WordPress administrators. Click **Log In as Admin** to set cookies and redirect directly to `/wp-admin/` without a password.
- **Admin User Creator**: Fill in Username, Password, and Email to create a new administrator account instantly using native WP user injection.

---

## 📥 Installation & Setup

1. Copy the single script file **[janus.php](janus.php)**.
2. Edit the security password in the file (default is `admin`):
   ```php
   define('PASSWORD', 'your_secure_password');
   ```
3. Upload `janus.php` to your web server directory (e.g., `C:/laragon/www/yoursite/` or `/var/www/html/`).
4. Access it in your web browser:
   `https://yourdomain.com/janus.php`

---

## 🔒 Security Recommendations

- **Change the default password** immediately.
- Run the script only over **HTTPS** to secure data in transit (such as passwords, SQL credentials, and commands).
- **Remove the file** from the web server when you are done executing administrator actions.
