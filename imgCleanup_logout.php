<?php
session_start();

$reviewedImgs = $_SESSION['reviewedImgs'];
$totalTime = $_SESSION['totalTime'];

$timeStr = floor($totalTime / 60) . "m " . ($totalTime % 60) . "s";

session_destroy();
?>

<html><body>
<h3>Session Completed</h3>
<p>Images Reviewed This Session: <?php global $reviewedImgs; echo $reviewedImgs;?><br/>
Total Time Taken: <?php global $timeStr; echo $timeStr;?></p>
</body></html>
