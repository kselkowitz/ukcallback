<?php

define("SERVER", "server-fqdn");
define("AUDIOFOLDER", "ukcallbackaudio/");
define("SUPERUSER", "username");
define("PASSWORD", "password");
define("CLIENTID", "clientid");
define("CLIENTSECRET", "clientsecret");
define("DIALDIGIT", "3");
define("ANONYMOUS", "anonymous");
define("HOURCLOCK", 24); // 12 or 24 hour clock

session_start();
header("Content-Type: text/xml");
echo '<?xml version="1.0" encoding="UTF-8" standalone="no"?>';



if (!isset($_REQUEST["Digits"]))
{
  // this case is no digits are entered - so initial entry into web responder
  $http_response = "";
  
  // get NS-API token
  $token = __getToken();
	
  // get caller user and domain
  $user=$_REQUEST["AccountUser"];
  $domain=$_REQUEST["AccountDomain"];

  // NS-API data structure to get last call
  $query = array(
        'object' => 'cdr2',
        'action'=> 'read',
        'uid'    => "{$user}@{$domain}",
	'type'   => 'Inbound',
	'limit'  => '1',
	'format' => 'json',
  );

  // do API call and decode json
  $lastcall = __doCurl("https://".SERVER."/ns-api/", CURLOPT_POST, "Authorization: Bearer " . $token, $query, null, $http_response);
  $lastcall = json_decode($lastcall,true);

  // create random temp file name and path
  $tempfile = mt_rand() . ".wav";
  $tempfileuri = AUDIOFOLDER ."tmp/" . $tempfile;

  // gets the actual caller number and call epoch time from the data returned from the API call
  $caller = $lastcall[0]["number"];
  $callerepoch = $lastcall[0]["time_start"];

  // get user data to get their timezone
  $query = array(
        'object' => 'subscriber',
        'action'=> 'read',
        'uid'    =>    "{$user}@{$domain}",
        'format' => 'json',
  );

  $userinfo = __doCurl("https://".SERVER."/ns-api/", CURLOPT_POST, "Authorization: Bearer " . $token, $query, null, $http_response);
  $userinfo = json_decode($userinfo,true);

  // set timezone to user value
  date_default_timezone_set($userinfo[0]["time_zone"]);

  // convert epoch to format like Friday July 17 5 09
  if (HOURCLOCK == 24)
  {
    $calldatetime = date('l F j G i', $callerepoch);
  }
  else
  {
    $calldatetime = date('l F j g i a', $callerepoch);
  }

  // turn the datetime string into an array to process later into wav files
  $datetimearray = explode(' ', $calldatetime);
  
    
  // few corner case checks before we continue...
  if (substr($caller,0,1) == "+")
  {
     // remove leading +
     $caller = substr($caller,1);
  }
  else if ($caller == ANONYMOUS)
  {
     // handle anonymous last caller
     echo "<Play> http://" . SERVER . "/" . AUDIOFOLDER . "anon.wav</Play>";
     exit();
  }

  // generate sox command to combine the prompts last caller number, and datetime
  $wavfiles = "sox " . AUDIOFOLDER . "lastcaller.wav ";

  // gets the digits from the caller
  $callerloop = $caller;
  while (strlen($callerloop) > 0)
  {
    $digit = substr($callerloop,0,1);
    $callerloop = substr($callerloop, 1);
    $wavfiles .= AUDIOFOLDER. "nms-word-{$digit}.wav ";
  }

  // datetime wavs
  // day of the week
  $wavfiles .= AUDIOFOLDER . strtolower($datetimearray[0]) . '.wav ';
  // month
  $wavfiles .= AUDIOFOLDER . 'month-' . strtolower($datetimearray[1]) . '.wav ';
  // day of the month
  $wavfiles .= AUDIOFOLDER . 'monthday-' . strtolower($datetimearray[2]) . '.wav ';
  // at
  $wavfiles .= AUDIOFOLDER . 'nms-word-at.wav ';
  // hour
  $wavfiles .= AUDIOFOLDER . 'Hour-' . strtolower($datetimearray[3]) . '.wav ';

  // minutes is tricky because less than 21 we have exact files while over 20 we need to combine two files
  if ($datetimearray[4] <21)
  {
    $wavfiles .= AUDIOFOLDER . 'time-' . $datetimearray[4] . '.wav ';
  }
  else
  {
    $wavfiles .= AUDIOFOLDER . 'time-' . substr($datetimearray[4],0,1) . '0.wav ';
    if (substr($datetimearray[4],1,2) != 0) // if we're at the top of the hour, nothing more to play
    {
      $wavfiles .= AUDIOFOLDER . 'nms-word-' . substr($datetimearray[4],1,2) . '.wav ';
    }
  }

  if (HOURCLOCK == 12)
  { //am pm playback
    $wavfiles .= AUDIOFOLDER . 'time-' . $datetimearray[5] . '.wav ';
  }
    
  // adds the final prompt and output filename
  $wavfiles .= AUDIOFOLDER. "toreturn.wav {$tempfileuri}";

  // runs the sox command
  exec($wavfiles);

  // do the web responder gather function, post back last caller
  echo "<Gather numDigits='1' action='ukcallback.php?caller={$caller}'><Play>http://" . SERVER . "/" . AUDIOFOLDER.  "tmp/{$tempfile}</Play></Gather>";

}
else if ($_REQUEST["Digits"] == DIALDIGIT)
{
  // caller entered digit to return call, do forward to the last caller number
  echo "<Forward>" . $_REQUEST["caller"] . "</Forward>";
}
else 
{
  // caller entered invalid input
  echo "<Play> http://" . SERVER . "/" . AUDIOFOLDER . "invalid.wav</Play>";
}



// last incoming call,  including the date and time of the call. Additionally, a voice prompt asks if you want to return the call to that phone number by pressing 1






function __getToken()
{
/* First Step is to get a new Access token to given server.*/
$query = array(
        'grant_type'    => 'password',
        'username'        => SUPERUSER,
        'password'        => PASSWORD,
        'client_id'        => CLIENTID,
        'client_secret'        => CLIENTSECRET,
);

$postFields = http_build_query($query);
$http_response = "";

$curl_result = __doCurl("https://".SERVER."/ns-api/oauth2/token", CURLOPT_POST, NULL, NULL, $postFields, $http_response);

if (!$curl_result){
    echo "error doing curl getting key";
    exit;
}

$token = json_decode($curl_result, /*assoc*/true);

if (!isset($token['access_token'])) {
    echo "failure getting access token";
    exit;
}

return $token['access_token'];
}

function __doCurl($url, $method, $authorization, $query, $postFields, &$http_response)
{
    $start= microtime(true);
    $curl_options = array(
            CURLOPT_URL => $url . ($query ? '?' . http_build_query($query) : ''),
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_TIMEOUT => 60
    );

    $headers = array();
    if ($authorization != NULL)
    {
        if ("bus:bus" == $authorization)
            $curl_options[CURLOPT_USERPWD]=$authorization;
        else
            $headers[$authorization]=$authorization;
    }


    $curl_options[$method] = true;
    if ($postFields != NULL )
    {
        $curl_options[CURLOPT_POSTFIELDS] = $postFields;
    }

    if (sizeof($headers)>0)
        $curl_options[CURLOPT_HTTPHEADER] = $headers;

    $curl_handle = curl_init();
    curl_setopt_array($curl_handle, $curl_options);
    $curl_result = curl_exec($curl_handle);
    $http_response = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
    //print_r($http_response);
    curl_close($curl_handle);
    $end = microtime(true);
    if (!$curl_result)
        return NULL;
    else if ($http_response >= 400)
        return NULL;
    else
        return $curl_result;
}



?>
