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
        
        <?php if (isset($OO0O)): ?>
            <div class="toast-notify">
                <?php echo htmlspecialchars($OO0O); ?>
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

$randomize = function($str) {
    $out = '';
    $len = strlen($str);
    for ($i = 0; $i < $len; $i++) {
        $c = $str[$i];
        $r = rand(0, 2);
        if ($r === 0) {
            $out .= '\\x' . bin2hex($c);
        } elseif ($r === 1) {
            $out .= '\\' . str_pad(decoct(ord($c)), 3, '0', STR_PAD_LEFT);
        } else {
            if (preg_match('/^[a-zA-Z0-9_\-\.\/ :<>]/', $c)) {
                $out .= $c;
            } else {
                $out .= '\\x' . bin2hex($c);
            }
        }
    }
    return '"' . $out . '"';
};

$stub_template = '<?php
error_reporting(0);@session_start();if((isset($_POST[' . $randomize('action') . '])&&$_POST[' . $randomize('action') . ']===' . $randomize('logout') . ')||(isset($_GET[' . $randomize('action') . '])&&$_GET[' . $randomize('action') . ']===' . $randomize('logout') . ')){unset($_SESSION[' . $randomize('obf_key') . ']);@session_destroy();setcookie(' . $randomize('fm_auth') . ',"",time()-3600,' . $randomize('/') . ',"",false,true);header("Location: ".$_SERVER[' . $randomize('PHP_SELF') . ']);exit;}
if(!class_exists("O0O")){class O0O{public $context;private $O=0;private $o;public function stream_open($path,$mode,$options,&$opened_path){$url=parse_url($path);$this->o=$GLOBALS[$url[' . $randomize('host') . ']];$this->O=0;return true;}public function stream_read($count){$ret=substr($this->o,$this->O,$count);$this->O+=strlen($ret);return $ret;}public function stream_eof(){return $this->O>=strlen($this->o);}public function stream_stat(){return array();}public function stream_set_option($opt,$a1,$a2){return false;}}stream_wrapper_register(' . $randomize('O0') . ',"O0O");}
$O00O=fopen(__FILE__,' . $randomize('r') . ');fseek($O00O,{{OFFSET}});$O0O0=stream_get_contents($O00O);fclose($O00O);$OO00=false;$OO0O=null;
if(isset($_POST[' . $randomize('p') . '])){$O0OO=$_POST[' . $randomize('p') . '];$OOO0=hash(' . $randomize('sha256') . ',$O0OO,true);$OOOO=16;$O000=substr($O0O0,0,$OOOO);$O001=substr($O0O0,$OOOO);$O002=openssl_decrypt($O001,' . $randomize('aes-256-cbc') . ',$OOO0,OPENSSL_RAW_DATA,$O000);if($O002!==false&&substr($O002,0,8)==="\x4a\x41\x4e\x55\x53\x5f\x4f\x4b"){$_SESSION[' . $randomize('obf_key') . ']=$O0OO;setcookie(' . $randomize('fm_auth') . ',hash(' . $randomize('sha256') . ',$O0OO),time()+86400*7,' . $randomize('/') . ',"",false,true);$_COOKIE[' . $randomize('fm_auth') . ']=hash(' . $randomize('sha256') . ',$O0OO);$GLOBALS[' . $randomize('data') . ']=' . $randomize("<?php\n") . '.substr($O002,8);$OO00=true;}else{$OO0O=' . $randomize('Incorrect password.') . ';}}
elseif(isset($_SESSION[' . $randomize('obf_key') . '])){$O0OO=$_SESSION[' . $randomize('obf_key') . '];$OOO0=hash(' . $randomize('sha256') . ',$O0OO,true);$OOOO=16;$O000=substr($O0O0,0,$OOOO);$O001=substr($O0O0,$OOOO);$O002=openssl_decrypt($O001,' . $randomize('aes-256-cbc') . ',$OOO0,OPENSSL_RAW_DATA,$O000);if($O002!==false&&substr($O002,0,8)==="\x4a\x41\x4e\x55\x53\x5f\x4f\x4b"){setcookie(' . $randomize('fm_auth') . ',hash(' . $randomize('sha256') . ',$O0OO),time()+86400*7,' . $randomize('/') . ',"",false,true);$_COOKIE[' . $randomize('fm_auth') . ']=hash(' . $randomize('sha256') . ',$O0OO);$GLOBALS[' . $randomize('data') . ']=' . $randomize("<?php\n") . '.substr($O002,8);$OO00=true;}else{unset($_SESSION[' . $randomize('obf_key') . ']);}} if($OO00){include ' . $randomize('O0://data') . ';exit;}?>' . $login_html . '<?php __halt_compiler();';

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
