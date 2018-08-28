<?php
define('DS', DIRECTORY_SEPARATOR);
define('DIR_ROOT', dirname(__FILE__) . DS);
define('DIR_APP', DIR_ROOT . 'app' . DS);
define('DIR_CORE', DIR_ROOT . 'core' . DS);
define('DIR_CORE_SERVER', DIR_ROOT . 'core' . DS . 'server' . DS);
define('DIR_WRAPPERS', DIR_ROOT . 'wrappers' . DS);
define('DIR_LOG', DIR_ROOT . 'logs');
define('DEBUG_MODE', TRUE);

//limit the number of forked processes to the number of available cpu cores
function cpu_cores($cores = 1) {
  $OS = strtoupper(substr(PHP_OS, 0, 3));
  
  if ('WIN' == $OS) {
    //TODO: test 'the wmic cpu get' on different Windowses (tested only on Win7 x64)
    //NOTE: on Windows XP x64 the command is 'echo %NUMBER_OF_PROCESSORS%'
    if (fgets($process = popen('wmic cpu get NumberOfLogicalProcessors', 'rb'))) {
      $cores = intval(fgets($process));
      pclose($process);
    }
  } else if ('LIN' == $OS) { //tested on Ubuntu x64 14.04, 16.04, 18.04 and Slack x64 14.1
    if (is_file('/proc/cpuinfo')) {
      preg_match_all('/^processor/m', file_get_contents('/proc/cpuinfo'), $matches);
      $cores = count($matches[0]);
    } else if ($process = popen('sysctl -a', 'rb')) {
      preg_match('/hw.ncpu: (\d+)/', stream_get_contents($process), $matches);
      if ($matches) { $cores = intval($matches[1][0]); }
    } else { echo "Unknown Linux-based operating system, setting the fork limit to 1 ... "; }
  } else { echo "Unknown operating system, setting the fork limit to 1 ... "; }
  
  //enable only for testing
  //return "{$OS} | {$cores}";
  
  return $cores;
}

define('FORK_COUNT', cpu_cores());

$error_level = E_ALL;

include 'config.php';
include 'autoload.php';
error_reporting($error_level);
date_default_timezone_set('UTC');
