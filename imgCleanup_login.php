<?php
session_start();
$errorText = "";


// dbServer, dbUser, dbPassword, and dbName omitted from public files
// they'd be in the following:
//$_SESSION['servername'] = XXXXXX;
//$_SESSION['username'] = XXXXXX;
//$_SESSION['password'] = XXXXXX;
//$_SESSION['database'] = XXXXXX;

$_SESSION['imgWebRoot'] = 'http://shannon.cs.illinois.edu/DenotationGraph/graph/flickr30k-images/';
$_SESSION['reviewedImgs'] = 0;
$_SESSION['totalTime'] = 0;


$conn = new mysqli($_SESSION['servername'],$_SESSION['username'],
    $_SESSION['password'],$_SESSION['database']);
if($conn->connect_error){
    die("Connection failed: ".$conn->connect_error);
}
$query = 'SELECT reviewer_id, tier '.
         'FROM reviewers ';
$result = $conn->query($query);
$reviewerTierDict = array();
if($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $reviewerID = $row['reviewer_id'];
        $tier = $row['tier'];
        $reviewerTierDict[$reviewerID] = $tier;
    }
}
$conn->close();

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    if(isset($_POST['usr'])){
        $usr = $_POST['usr'];
        if(array_key_exists($usr, $reviewerTierDict)){
            $_SESSION['usr'] = $usr;
            $tierVal = $reviewerTierDict[$usr];
            if($tierVal == "0"){
                $_SESSION['tier'] = 0;
            } else if($tierVal == "1") {
                $_SESSION['tier'] = 1;
            } else if($tierVal == "2") {
                $_SESSION['tier'] = 2;
            } else if($tierVal == "3") {
                $_SESSION['tier'] = 3;
            }

            if($usr == 'ccervan2'){
                header('location: imgCleanup.php');
            } else {
                header('location: imgCleanup_logout.php');
            }

            //
            /*
        } else if($usr == "chunkOnly") {
            $_SESSION['usr'] = 'ccervan2';
            $_SESSION['tier'] = 3;
            header('location: imgCleanup.php?chunkOnly');
        } else if($usr == "debug") {
            header('location: imgCleanup.php?debug');*/
        } else {
            $errorText = "Invalid net ID.<br/>Please contact ccervan2@illinois.edu to be added to authorized users.";
        }
    }
}
?>
<form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>">
    <p><?php global $errorText; echo $errorText?></p>
    <p>
    Please enter your netID : <input type="text" name="usr">
    <input type="submit" />
    </p>
</form>