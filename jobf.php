<?php
/**
 * Janus CLI Obfuscator (jobf.php)
 * Command-line script to encrypt a PHP file manager or script using AES-256.
 * Renders a standard styled login page without hiding styling.
 * 
 * Usage: php jobf.php [source_script.php] [password]
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

if ($argc < 3) {
    echo "Usage: php jobf.php [source_script.php] [password]\n";
    exit(1);
}

$source_file = $argv[1];
$password = $argv[2];

if (!file_exists($source_file)) {
    echo "Error: Source file '{$source_file}' does not exist.\n";
    exit(1);
}

$source = file_get_contents($source_file);
if ($source === false || strlen($source) === 0) {
    echo "Error: Could not read source file or file is empty.\n";
    exit(1);
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

// AES-256 Encryption
$key = hash('sha256', $password, true);
$plain = 'JANUS_OK' . $code;

$iv_len = openssl_cipher_iv_length('aes-256-cbc');
$iv = openssl_random_pseudo_bytes($iv_len);
$encrypted = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
if ($encrypted === false) {
    echo "Error: Encryption failed.\n";
    exit(1);
}

$encoded = $iv . $encrypted;

$login_html = '<!DOCTYPE html>
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
if ((isset($_POST[\'action\']) && $_POST[\'action\'] === \'logout\') || (isset($_GET[\'action\']) && $_GET[\'action\'] === \'logout\')) {
    unset($_SESSION[\'obf_key\']);
    @session_destroy();
    setcookie(\'fm_auth\', \'\', time() - 3600, \'/\');
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
?>
' . $login_html . '
<?php __halt_compiler();';

// Calculate correct binary seek offset dynamically
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
$output_code = $stub . $encoded;

// Generate output file in the same directory as the source script
$dir = pathinfo($source_file, PATHINFO_DIRNAME);
$filename = pathinfo($source_file, PATHINFO_FILENAME);
$output_filename = ($dir === '.' || $dir === '') ? $filename . '_encrypted.php' : $dir . DIRECTORY_SEPARATOR . $filename . '_encrypted.php';

$bytes_written = file_put_contents($output_filename, $output_code);

if ($bytes_written !== false) {
    echo "SUCCESS: Securing complete!\n";
    echo "Source file: {$source_file} (" . strlen($source) . " B)\n";
    echo "Password: {$password}\n";
    echo "Encrypted file created: {$output_filename} ({$bytes_written} B)\n";
} else {
    echo "Error: Could not save output file.\n";
    exit(1);
}
